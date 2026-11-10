<?php
// src/Inventory/RawObjectRepository.php
//
// The raw layer: what vCenter actually said, one row per object per run.
//
// The point is recovery without rescanning. When it turns out in six months
// that a field matters, it is extracted from here for every run already
// collected, rather than being available only from the day somebody added
// it to a collector.
//
// Rows are inserted in batches. One INSERT per object over an inventory of a
// few thousand is thousands of round trips; a few hundred per statement
// turns that into a handful.

declare(strict_types=1);

namespace DcInfraSnap\Inventory;

use PDO;

final class RawObjectRepository
{
    /** Objects per INSERT. Large enough to matter, small enough for max_allowed_packet. */
    private const BATCH = 100;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Store the payloads of one object type for one run.
     *
     * @param list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}> $objects
     */
    public function store(string $objectType, int $integrationId, int $runId, array $objects): void
    {
        foreach (array_chunk($objects, self::BATCH) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $index => $object) {
                $payload = json_encode(
                    $object['raw'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );

                $payload = $payload === false ? '{}' : $payload;

                $values[] = "(:run{$index}, :integration{$index}, :type{$index}, :moref{$index},"
                    . " :payload{$index}, :hash{$index}, UTC_TIMESTAMP())";

                $params["run{$index}"]         = $runId;
                $params["integration{$index}"] = $integrationId;
                $params["type{$index}"]        = $objectType;
                $params["moref{$index}"]       = $object['moref'];
                $params["payload{$index}"]     = $payload;
                $params["hash{$index}"]        = hash('sha256', $payload);
            }

            if ($values === []) {
                continue;
            }

            // A re-run of the same object within one run should not fail the
            // collection. The unique key makes that impossible to do twice,
            // and the newer payload is the one worth keeping.
            $sql = 'INSERT INTO raw_object
                        (run_id, integration_id, object_type, moref, payload, payload_hash, collected_at)
                    VALUES ' . implode(', ', $values) . '
                    ON DUPLICATE KEY UPDATE
                        payload = VALUES(payload),
                        payload_hash = VALUES(payload_hash),
                        collected_at = VALUES(collected_at)';

            $this->pdo->prepare($sql)->execute($params);
        }
    }

    /**
     * Delete the payloads of runs older than the given number of days,
     * keeping the first run of each month.
     *
     * Not called from anywhere yet — retention becomes interesting once
     * there is something to delete. It lives here so the policy is written
     * down in one place when it does.
     */
    public function prune(int $keepDays = 30): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE ro FROM raw_object ro
               JOIN discovery_run r ON r.id = ro.run_id
              WHERE r.queued_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
                AND r.id NOT IN (
                      SELECT first_of_month FROM (
                          SELECT MIN(id) AS first_of_month
                            FROM discovery_run
                           GROUP BY integration_id, YEAR(queued_at), MONTH(queued_at)
                      ) AS keep
                )'
        );

        $stmt->bindValue('days', $keepDays, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
