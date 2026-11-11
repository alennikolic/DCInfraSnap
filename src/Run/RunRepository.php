<?php
// src/Run/RunRepository.php
//
// The queue and the record of collection runs.
//
// Locking lives in the database, not here: discovery_run.active_lock holds
// integration_id only while a run is queued or running, and carries a UNIQUE
// index. Two runs against one source therefore collide on insert rather than
// racing each other. That is deliberate — a lock held in PHP would not
// survive the worker being restarted mid-run.

declare(strict_types=1);

namespace DcInfraSnap\Run;

use PDO;
use PDOException;
use RuntimeException;

final class RunRepository
{
    /** A run with no sign of life for this long is presumed dead. */
    public const STALE_AFTER_MINUTES = 30;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    // -----------------------------------------------------------------
    // Queueing
    // -----------------------------------------------------------------

    /**
     * Put a run on the queue.
     *
     * @throws RuntimeException when one is already queued or running
     */
    public function queue(int $integrationId, string $trigger = 'cli'): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO discovery_run (integration_id, status, trigger_source, queued_at)
                 VALUES (:id, \'queued\', :trigger, UTC_TIMESTAMP())'
            );

            $stmt->execute(['id' => $integrationId, 'trigger' => $trigger]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // 23000 is the integrity violation: active_lock already holds
            // this source, which means a run is in flight.
            if ($e->getCode() === '23000') {
                throw new RuntimeException(
                    'A collection for this source is already queued or running.'
                );
            }

            throw $e;
        }
    }

    /**
     * Take the oldest queued run, if there is one, and mark it running.
     *
     * The UPDATE carries status = 'queued' in its WHERE clause, so two
     * workers racing for the same row cannot both win: the second one
     * updates nothing and moves on.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $row = $this->pdo->query(
            'SELECT id, integration_id FROM discovery_run
              WHERE status = \'queued\'
              ORDER BY queued_at, id
              LIMIT 1'
        )->fetch();

        if ($row === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = \'running\', started_at = UTC_TIMESTAMP(),
                    heartbeat_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = \'queued\''
        );
        $stmt->execute(['id' => $row['id']]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->find((int) $row['id']);
    }

    /**
     * Create a run already in the running state, for a synchronous
     * collection from the command line.
     */
    public function startDirect(int $integrationId, string $trigger = 'cli'): int
    {
        $id = $this->queue($integrationId, $trigger);

        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = \'running\', started_at = UTC_TIMESTAMP(),
                    heartbeat_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $id;
    }

    // -----------------------------------------------------------------
    // Progress
    // -----------------------------------------------------------------

    public function heartbeat(int $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run SET heartbeat_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $stmt->execute(['id' => $runId]);
    }

    /**
     * Record how one object type went.
     *
     * Written as a row in discovery_run_type rather than as columns on the
     * run. A run of a switch and a run of a vCenter produce entirely
     * different sets of types, and there is no row shape that fits both
     * without most of it being NULL either way.
     *
     * The absence of a row is what 'pending' used to mean: the type was never
     * reached. So only an outcome is ever written here — ok or failed.
     *
     * Values are bound, never interpolated, which is the other reason to
     * prefer this over the columns: the type name no longer reaches SQL as
     * part of a column name.
     */
    public function recordType(
        int $runId,
        string $typeKey,
        string $sourceType,
        int $order,
        string $status,
        int $found = 0,
        int $changed = 0,
        ?string $error = null,
    ): void {
        if (!in_array($status, ['ok', 'failed'], true)) {
            throw new RuntimeException("Unknown status: {$status}");
        }

        // ON DUPLICATE KEY, so that a type collected twice within one run —
        // a retry, or a collector that was interrupted — replaces its own
        // row instead of failing on uq_run_type.
        $stmt = $this->pdo->prepare(
            'INSERT INTO discovery_run_type
                    (run_id, type_key, source_type, sort_order, status, found, changed,
                     error_message, recorded_at)
             VALUES (:run, :type, :source, :sort_order, :status, :found, :changed,
                     :error, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                     source_type   = VALUES(source_type),
                     sort_order    = VALUES(sort_order),
                     status        = VALUES(status),
                     found         = VALUES(found),
                     changed       = VALUES(changed),
                     error_message = VALUES(error_message),
                     recorded_at   = VALUES(recorded_at)'
        );

        $stmt->execute([
            'run'        => $runId,
            'type'       => $typeKey,
            'source'     => $sourceType,
            'sort_order' => $order,
            'status'     => $status,
            'found'      => $found,
            'changed'    => $changed,
            'error'      => $error,
        ]);

        // Progress is also a sign of life: a long collection must not look
        // dead to releaseStale() while it is still working through its types.
        $this->heartbeat($runId);
    }

    /**
     * The per-type outcome of several runs at once, keyed by run id.
     *
     * One query for a page of runs rather than one per run. The Runs page
     * renders whatever types come back, so a switch run showing seven FC
     * types and a vCenter run showing six needs no special case.
     *
     * @param list<int> $runIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function typesFor(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        // Built from integers cast here, not from anything a caller typed.
        $ids = implode(',', array_map('intval', $runIds));

        $rows = $this->pdo->query(
            'SELECT run_id, type_key, source_type, sort_order, status, found, changed, error_message
               FROM discovery_run_type
              WHERE run_id IN (' . $ids . ')
              ORDER BY run_id, sort_order, type_key'
        )->fetchAll();

        $byRun = [];

        foreach ($rows as $row) {
            $byRun[(int) $row['run_id']][] = $row;
        }

        return $byRun;
    }

    // -----------------------------------------------------------------
    // Finishing
    // -----------------------------------------------------------------

    public function finish(int $runId, string $status, int $apiCalls = 0, ?string $error = null): void
    {
        if (!in_array($status, ['success', 'partial', 'failed'], true)) {
            throw new RuntimeException("Unknown final status: {$status}");
        }

        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = :status, finished_at = UTC_TIMESTAMP(),
                    api_calls = :calls, error_message = :error
              WHERE id = :id'
        );

        $stmt->execute([
            'id'     => $runId,
            'status' => $status,
            'calls'  => $apiCalls,
            'error'  => $error,
        ]);
    }

    /**
     * Mark runs that stopped reporting as failed, releasing their lock.
     *
     * This is what recovers from a worker that was killed mid-run: the row
     * would otherwise stay 'running' forever and block the source.
     *
     * @return int how many were released
     */
    public function releaseStale(int $minutes = self::STALE_AFTER_MINUTES): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = \'failed\', finished_at = UTC_TIMESTAMP(),
                    error_message = :message
              WHERE status = \'running\'
                AND heartbeat_at IS NOT NULL
                AND heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)'
        );

        $stmt->bindValue('message', "No sign of life for {$minutes} minutes; presumed dead.");
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Release one run by hand, when it is stuck and waiting is not wanted.
     */
    public function reset(int $runId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_run
                SET status = \'failed\', finished_at = UTC_TIMESTAMP(),
                    error_message = \'Released by hand.\'
              WHERE id = :id AND status IN (\'queued\', \'running\')'
        );
        $stmt->execute(['id' => $runId]);

        return $stmt->rowCount() > 0;
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_run WHERE id = :id');
        $stmt->execute(['id' => $runId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The most recent runs, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50, ?int $integrationId = null): array
    {
        $sql = 'SELECT r.*, i.name AS integration_name
                  FROM discovery_run r
                  JOIN integration i ON i.id = r.integration_id';

        $params = [];

        if ($integrationId !== null) {
            $sql                    .= ' WHERE r.integration_id = :id';
            $params['id'] = $integrationId;
        }

        $sql .= ' ORDER BY r.queued_at DESC, r.id DESC LIMIT ' . max(1, $limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * The last run of a source that finished completely.
     *
     * This is the reference for "not seen in the last collection". Partial
     * and failed runs are deliberately not counted: after one of those, an
     * object being absent may only mean collection never reached it.
     */
    public function lastSuccessfulId(int $integrationId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM discovery_run
              WHERE integration_id = :id AND status = \'success\'
              ORDER BY finished_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute(['id' => $integrationId]);

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
