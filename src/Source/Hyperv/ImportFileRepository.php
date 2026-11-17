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
// The payload is on disk, not in a column. It is kept in raw_object once
// imported, and a 2.5 MB upload has no business travelling through a BLOB on
// its way there. The row holds the envelope — enough to render the waiting
// list without opening every file again.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use PDO;
use RuntimeException;

final class ImportFileRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $importDir,
    ) {
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
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hv_import_file
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
     * A file whose row is here but whose payload is gone from disk is a real
     * possibility — someone cleared the folder — so it is reported by name
     * rather than skipped quietly.
     *
     * @return list<OfflinePayload>
     */
    public function stagedPayloads(int $integrationId): array
    {
        $payloads = [];

        foreach ($this->staged($integrationId) as $row) {
            $path = (string) $row['file_path'];

            if (!is_file($path)) {
                throw new RuntimeException(
                    'The uploaded file ' . $row['file_name'] . ' is no longer on disk. Remove it and upload it again.'
                );
            }

            $payloads[] = OfflinePayload::fromFile($path);
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
     * not a collector file never reaches the folder.
     *
     * @return int the id of the staged file
     */
    public function stage(int $integrationId, string $temporaryPath, string $originalName): int
    {
        $contents = file_get_contents($temporaryPath);

        if ($contents === false) {
            throw new RuntimeException('The upload could not be read.');
        }

        // Validated here rather than at import time, so the person finds out
        // while they are looking at the upload form.
        $payload = OfflinePayload::fromJson($contents, $originalName);
        $sha     = hash('sha256', $contents);

        $directory = $this->importDir . '/' . $integrationId;

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("The import folder {$directory} could not be created.");
        }

        $path = $directory . '/' . $sha . '.json';

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("The upload could not be written to {$path}.");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hv_import_file
                    (integration_id, file_name, file_path, byte_size, sha256,
                     schema_version, script_version, source_token, source_kind,
                     collector_host, cluster_guid, cluster_name,
                     node_count, vm_count, warning_count, generated_at,
                     uploaded_at, status)
             VALUES (:integration, :name, :path, :size, :sha,
                     :schema, :script, :token, :kind,
                     :host, :cluster_guid, :cluster_name,
                     :nodes, :vms, :warnings, :generated,
                     UTC_TIMESTAMP(), :status)
             ON DUPLICATE KEY UPDATE
                     file_name      = VALUES(file_name),
                     file_path      = VALUES(file_path),
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
            'path'         => $path,
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
     * The payload on disk goes at the same time: raw_object now holds it per
     * object, and keeping a second copy would mean two places to prune.
     */
    public function markImported(int $integrationId, int $runId): void
    {
        $staged = $this->staged($integrationId);

        $stmt = $this->pdo->prepare(
            'UPDATE hv_import_file
                SET status = :status, run_id = :run
              WHERE integration_id = :id AND status = :staged'
        );

        $stmt->execute([
            'status' => 'imported',
            'run'    => $runId,
            'id'     => $integrationId,
            'staged' => 'staged',
        ]);

        foreach ($staged as $row) {
            $path = (string) $row['file_path'];

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Throw one staged file away, row and payload together. */
    public function discard(int $id): void
    {
        $row = $this->find($id);

        if ($row === null) {
            return;
        }

        $path = (string) $row['file_path'];

        if (is_file($path)) {
            @unlink($path);
        }

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
