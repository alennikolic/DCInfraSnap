<?php
// src/Core/Migrator.php
//
// Applies the SQL migrations in db/migrations/ and keeps a record of them in
// the schema_migrations table.
//
// Rules:
//   - files are named NNNN_name.sql (e.g. 0001_schema.sql)
//   - they are applied in ascending version order
//   - an applied migration is NEVER edited; a fix goes in as a new version
//   - the checksum of each file is stored, so a later edit is detected
//
// NOTE on transactions: MySQL cannot roll back DDL. If one file contains
// several statements and the third fails, the first two stay applied. The
// initial schema is one file by design — it only ever runs against an empty
// database, where the remedy is to drop the volume and start again.

declare(strict_types=1);

namespace DcInfraSnap\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dir,
    ) {
    }

    // -----------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------

    /**
     * Create schema_migrations if it is missing.
     *
     * This table is not a migration over itself — the migrator creates it,
     * because otherwise it would have to read it before it exists.
     */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version     VARCHAR(20)  NOT NULL,
                name        VARCHAR(191) NOT NULL,
                checksum    CHAR(64)     NOT NULL COMMENT "SHA-256 of the file",
                applied_at  DATETIME     NOT NULL COMMENT "UTC",
                duration_ms INT UNSIGNED NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_0900_ai_ci
              COMMENT="Record of applied migrations"'
        );
    }

    // -----------------------------------------------------------------
    // Reading state
    // -----------------------------------------------------------------

    /**
     * Migrations that exist as files, sorted by version.
     *
     * @return array<string, array{version: string, name: string, path: string, checksum: string}>
     */
    public function available(): array
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException("The migrations folder does not exist: {$this->dir}");
        }

        $files = glob($this->dir . '/*.sql') ?: [];
        $found = [];

        foreach ($files as $path) {
            $basename = basename($path);

            if (!preg_match('/^(\d{4,})_([a-z0-9_]+)\.sql$/', $basename, $m)) {
                throw new RuntimeException(
                    "Invalid migration name: {$basename}. Expected NNNN_name.sql"
                );
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Cannot read migration: {$path}");
            }

            $found[$m[1]] = [
                'version'  => $m[1],
                'name'     => $m[2],
                'path'     => $path,
                'checksum' => hash('sha256', $contents),
            ];
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * Migrations already applied, keyed by version.
     *
     * @return array<string, array{version: string, name: string, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $rows = $this->pdo
            ->query('SELECT version, name, checksum, applied_at FROM ' . self::TABLE . ' ORDER BY version')
            ->fetchAll();

        $byVersion = [];

        foreach ($rows as $row) {
            $byVersion[(string) $row['version']] = $row;
        }

        return $byVersion;
    }

    /**
     * Files that have not been applied yet, in order.
     *
     * @return list<array{version: string, name: string, path: string, checksum: string}>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->available(),
            static fn (array $m): bool => !isset($applied[$m['version']])
        ));
    }

    /**
     * Applied migrations whose file has changed since.
     *
     * This is the check that catches the tempting mistake: editing a
     * migration that has already run. The file and the database then
     * disagree, and every environment set up afterwards gets a different
     * schema from the one in production.
     *
     * @return list<array{version: string, name: string}>
     */
    public function drift(): array
    {
        $available = $this->available();
        $drifted   = [];

        foreach ($this->applied() as $version => $row) {
            if (!isset($available[$version])) {
                continue;
            }

            if (!hash_equals((string) $row['checksum'], $available[$version]['checksum'])) {
                $drifted[] = ['version' => $version, 'name' => (string) $row['name']];
            }
        }

        return $drifted;
    }

    // -----------------------------------------------------------------
    // Applying
    // -----------------------------------------------------------------

    /**
     * Run one migration and record it.
     *
     * @param array{version: string, name: string, path: string, checksum: string} $migration
     *
     * @return int how long it took, in milliseconds
     */
    public function apply(array $migration): int
    {
        $sql = file_get_contents($migration['path']);

        if ($sql === false) {
            throw new RuntimeException("Cannot read migration: {$migration['path']}");
        }

        $started = microtime(true);

        $this->pdo->exec($sql);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
                (version, name, checksum, applied_at, duration_ms)
             VALUES
                (:version, :name, :checksum, UTC_TIMESTAMP(), :duration)'
        );

        $stmt->execute([
            'version'  => $migration['version'],
            'name'     => $migration['name'],
            'checksum' => $migration['checksum'],
            'duration' => $durationMs,
        ]);

        return $durationMs;
    }
}
