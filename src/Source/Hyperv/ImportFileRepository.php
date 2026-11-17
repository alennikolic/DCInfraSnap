<?php
// src/Source/Hyperv/ImportFileRepository.php
//
// Uploaded collector files waiting to be imported.
//
// WHY A WAITING ROOM AT ALL. A host reports only its own machines, and
// sync() reconciles a whole source, so a run must carry every node's file at
// once. The files arrive one browser upload at a time, which means they have
// to be kept somewhere until the set is complete. That somewhere is this
// table plus a folder.
//
// The payload is a column, not a file. It was a file until the first real
// upload, which failed with "mkdir(): Permission denied": the repository is
// bind mounted and owned by the host's user, while PHP-FPM runs as www-data.
// A chown fixes that once, on one machine — a staged file in the database
// fixes it everywhere, and there is nothing here that wants a filesystem.
// The row also holds the envelope, so the waiting list renders without
// decoding every payload again.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use PDO;
use RuntimeException;

final class ImportFileRepository
{
    /**
     * MEDIUMTEXT holds 16 MiB. The limit is below that so a file that is
     * too large is refused with a message, rather than being truncated by
     * MySQL into a payload that no longer parses.
     */
    private const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The files still waiting, oldest upload first.
     *
     * @return list<array<string, mixed>>
     */
    public function staged(int $integrationId): array
    {
        // Every column but the payload: the list shows the envelope, and
        // dragging a few megabytes per row through it would be waste.
        $stmt = $this->pdo->prepare(
            'SELECT id, integration_id, file_name, byte_size, sha256,
                    schema_version, script_version, source_token, source_kind,
                    collector_host, cluster_guid, cluster_name,
                    node_count, vm_count, warning_count, generated_at,
                    uploaded_at, status, reject_reason, run_id
               FROM hv_import_file
              WHERE integration_id = :id AND status = :status
              ORDER BY uploaded_at'
        );

        $stmt->execute(['id' => $integrationId, 'status' => 'staged']);

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hv_import_file WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The payloads of every staged file, in upload order.
     *
     * @return list<OfflinePayload>
     */
    public function stagedPayloads(int $integrationId): array
    {
        $payloads = [];

        $stmt = $this->pdo->prepare(
            'SELECT file_name, payload FROM hv_import_file
              WHERE integration_id = :id AND status = :status
              ORDER BY uploaded_at'
        );

        $stmt->execute(['id' => $integrationId, 'status' => 'staged']);

        foreach ($stmt->fetchAll() as $row) {
            $payloads[] = OfflinePayload::fromJson(
                (string) $row['payload'],
                (string) $row['file_name']
            );
        }

        return $payloads;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Keep an uploaded file and record what is in it.
     *
     * The payload is validated before anything is written: a file that is
     * not a collector file is never stored.
     *
     * @return int the id of the staged file
     */
    public function stage(int $integrationId, string $temporaryPath, string $originalName): int
    {
        $contents = @file_get_contents($temporaryPath);

        if ($contents === false) {
            throw new RuntimeException('The upload could not be read.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                '%s is %s MB, and the limit for one file is %d MB.',
                $originalName,
                number_format(strlen($contents) / 1048576, 1),
                intdiv(self::MAX_BYTES, 1048576)
            ));
        }

        // Validated here rather than at import time, so the person finds out
        // while they are looking at the upload form.
        $payload = OfflinePayload::fromJson($contents, $originalName);
        $sha     = hash('sha256', $contents);

        $stmt = $this->pdo->prepare(
            'INSERT INTO hv_import_file
                    (integration_id, file_name, payload, byte_size, sha256,
                     schema_version, script_version, source_token, source_kind,
                     collector_host, cluster_guid, cluster_name,
                     node_count, vm_count, warning_count, generated_at,
                     uploaded_at, status)
             VALUES (:integration, :name, :payload, :size, :sha,
                     :schema, :script, :token, :kind,
                     :host, :cluster_guid, :cluster_name,
                     :nodes, :vms, :warnings, :generated,
                     UTC_TIMESTAMP(), :status)
             ON DUPLICATE KEY UPDATE
                     file_name      = VALUES(file_name),
                     payload        = VALUES(payload),
                     byte_size      = VALUES(byte_size),
                     script_version = VALUES(script_version),
                     source_token   = VALUES(source_token),
                     collector_host = VALUES(collector_host),
                     node_count     = VALUES(node_count),
                     vm_count       = VALUES(vm_count),
                     warning_count  = VALUES(warning_count),
                     generated_at   = VALUES(generated_at),
                     uploaded_at    = UTC_TIMESTAMP(),
                     status         = VALUES(status),
                     reject_reason  = NULL,
                     run_id         = NULL'
        );

        $stmt->execute([
            'integration'  => $integrationId,
            'name'         => $originalName,
            'payload'      => $contents,
            'size'         => strlen($contents),
            'sha'          => $sha,
            'schema'       => OfflinePayload::SCHEMA_VERSION,
            'script'       => $payload->scriptVersion(),
            'token'        => $payload->sourceToken(),
            'kind'         => $payload->kind(),
            'host'         => $payload->collectorHost(),
            'cluster_guid' => $payload->clusterGuid(),
            'cluster_name' => $payload->clusterName(),
            'nodes'        => count($payload->clusterNodeNames()),
            'vms'          => count($payload->vms()),
            'warnings'     => count($payload->warnings()),
            'generated'    => $payload->generatedAt(),
            'status'       => 'staged',
        ]);

        $id = (int) $this->pdo->lastInsertId();

        // ON DUPLICATE KEY leaves lastInsertId at 0 on some configurations,
        // so the row is looked up rather than assumed.
        if ($id === 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM hv_import_file WHERE integration_id = :id AND sha256 = :sha'
            );
            $stmt->execute(['id' => $integrationId, 'sha' => $sha]);
            $id = (int) $stmt->fetchColumn();
        }

        return $id;
    }

    /**
     * Mark every staged file of a source as imported by one run.
     *
     * The payload is emptied at the same time: raw_object now holds it per
     * object, and a second copy would be a second thing to prune. The row
     * stays, because "when was this last imported, and by which run" is the
     * question the page asks afterwards.
     */
    public function markImported(int $integrationId, int $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hv_import_file
                SET status = :status, run_id = :run, payload = :empty
              WHERE integration_id = :id AND status = :staged'
        );

        $stmt->execute([
            'status' => 'imported',
            'run'    => $runId,
            'empty'  => '',
            'id'     => $integrationId,
            'staged' => 'staged',
        ]);
    }

    /** Throw one staged file away. */
    public function discard(int $id): void
    {
        $this->pdo->prepare('DELETE FROM hv_import_file WHERE id = :id')->execute(['id' => $id]);
    }

    /** What the last import of this source did, for the page to show. */
    public function lastImported(int $integrationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hv_import_file
              WHERE integration_id = :id AND status = :status
              ORDER BY uploaded_at DESC
              LIMIT 1'
        );

        $stmt->execute(['id' => $integrationId, 'status' => 'imported']);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
