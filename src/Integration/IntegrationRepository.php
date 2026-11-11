<?php
// src/Integration/IntegrationRepository.php
//
// Reading and writing source definitions.
//
// The password is deliberately not returned alongside the Integration object.
// There is a separate call for it, password(), which decrypts it only when a
// client is about to sign in.

declare(strict_types=1);

namespace DcInfraSnap\Integration;

use DcInfraSnap\Core\Secret;
use DcInfraSnap\Inventory\ObjectType;
use PDO;
use RuntimeException;
use SensitiveParameter;

// Not final so a test can substitute a repository that returns a prepared
// credential instead of reading the database.
class IntegrationRepository
{
    private const COLUMNS = 'id, type, name, hostname, port, username, verify_peer,
                             product_version, is_active, last_tested_at, last_test_ok,
                             last_test_message, created_at, updated_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Secret $secret,
    ) {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * @return list<Integration>
     */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM integration';

        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY name';

        return array_map(
            static fn (array $row): Integration => Integration::fromRow($row),
            $this->pdo->query($sql)->fetchAll()
        );
    }

    public function find(int $id): ?Integration
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM integration WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : Integration::fromRow($row);
    }

    public function findByEndpoint(string $type, string $hostname, int $port): ?Integration
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM integration
              WHERE type = :type AND hostname = :hostname AND port = :port'
        );
        $stmt->execute(['type' => $type, 'hostname' => $hostname, 'port' => $port]);

        $row = $stmt->fetch();

        return $row === false ? null : Integration::fromRow($row);
    }

    /**
     * The decrypted password. Called only at the moment of signing in.
     */
    public function password(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT password_encrypted FROM integration WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $blob = $stmt->fetchColumn();

        if ($blob === false) {
            throw new RuntimeException("Integration {$id} does not exist.");
        }

        return $this->secret->decrypt((string) $blob);
    }

    /**
     * How many collected objects point at this source.
     *
     * Used by the delete confirmation, so it says what is about to go.
     *
     * @return array<string, int>
     */
    public function collectedCounts(int $id): array
    {
        $counts = [];

        // Derived from ObjectType rather than listed here. The list used to
        // be written out by hand and fell behind: vc_host_device was added
        // and this was not, so the confirmation under-reported what was about
        // to be deleted and the delete itself then failed on the foreign key.
        foreach (self::inventoryTables() as $table) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE integration_id = :id'
            );
            $stmt->execute(['id' => $id]);

            $count = (int) $stmt->fetchColumn();

            // Only what is actually there, so a vCenter is not described in
            // terms of empty FC tables and the other way round.
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        foreach (['raw_object', 'discovery_run'] as $table) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE integration_id = :id'
            );
            $stmt->execute(['id' => $id]);

            $counts[$table] = (int) $stmt->fetchColumn();
        }

        return $counts;
    }

    /**
     * Every inventory table, in the order the types are declared.
     *
     * @return list<string>
     */
    private static function inventoryTables(): array
    {
        $tables = [];

        foreach (ObjectType::all() as $type) {
            $tables[] = $type->table;
        }

        return $tables;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * @return int the id of the new source
     */
    public function create(
        string $name,
        string $hostname,
        string $username,
        #[SensitiveParameter] string $password,
        int $port = 443,
        bool $verifyPeer = false,
        string $type = Integration::TYPE_VCENTER,
    ): int {
        if ($this->findByEndpoint($type, $hostname, $port) !== null) {
            throw new RuntimeException("A source for {$hostname}:{$port} already exists.");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO integration
                (type, name, hostname, port, username, password_encrypted,
                 verify_peer, is_active, created_at, updated_at)
             VALUES
                (:type, :name, :hostname, :port, :username, :password,
                 :verify_peer, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        $stmt->bindValue('type', $type);
        $stmt->bindValue('name', $name);
        $stmt->bindValue('hostname', $hostname);
        $stmt->bindValue('port', $port, PDO::PARAM_INT);
        $stmt->bindValue('username', $username);
        $stmt->bindValue('password', $this->secret->encrypt($password), PDO::PARAM_LOB);
        $stmt->bindValue('verify_peer', $verifyPeer ? 1 : 0, PDO::PARAM_INT);

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Change everything except the password — updatePassword() does that.
     */
    public function update(
        int $id,
        string $name,
        string $hostname,
        int $port,
        string $username,
        bool $verifyPeer,
        string $type = Integration::TYPE_VCENTER,
    ): void {
        $existing = $this->findByEndpoint($type, $hostname, $port);

        if ($existing !== null && $existing->id !== $id) {
            throw new RuntimeException("A source for {$hostname}:{$port} already exists.");
        }

        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET name = :name, hostname = :hostname, port = :port,
                    username = :username, verify_peer = :verify_peer,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->execute([
            'id'          => $id,
            'name'        => $name,
            'hostname'    => $hostname,
            'port'        => $port,
            'username'    => $username,
            'verify_peer' => $verifyPeer ? 1 : 0,
        ]);
    }

    public function updatePassword(int $id, #[SensitiveParameter] string $password): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET password_encrypted = :password, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('password', $this->secret->encrypt($password), PDO::PARAM_LOB);

        $stmt->execute();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integration
                SET is_active = :active, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );

        $stmt->execute(['id' => $id, 'active' => $active ? 1 : 0]);
    }

    /**
     * Record the outcome of a connection test.
     *
     * The version is only written when the test succeeded; a failed test
     * should not erase what the last good one found out.
     */
    public function recordTest(
        int $id,
        bool $ok,
        string $message,
        ?string $productVersion = null,
    ): void {
        $sql = 'UPDATE integration
                   SET last_tested_at = UTC_TIMESTAMP(),
                       last_test_ok = :ok,
                       last_test_message = :message';

        $params = [
            'id'      => $id,
            'ok'      => $ok ? 1 : 0,

            // The column is VARCHAR(255) and a vCenter fault can be longer.
            'message' => mb_substr($message, 0, 255),
        ];

        if ($ok && $productVersion !== null) {
            $sql                      .= ', product_version = :version';
            $params['version'] = $productVersion;
        }

        $sql .= ', updated_at = UTC_TIMESTAMP() WHERE id = :id';

        $this->pdo->prepare($sql)->execute($params);
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    /**
     * Remove a source and everything collected through it.
     *
     * In order, and in one transaction. The foreign keys are RESTRICT rather
     * than CASCADE on purpose (see the comment at the top of the schema), so
     * the order here is not a nicety — it is the only order that works:
     *
     *   journals -> inventory -> raw payloads -> runs -> the source itself
     *
     * Anything out of order hits a foreign key and the whole transaction
     * rolls back, which is the behaviour worth having: a half-deleted source
     * is worse than one that refused to go.
     */
    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();

        try {
            // Journals first: they point at both the inventory row and the
            // run. Every table comes from ObjectType, so a new object type
            // is cleaned up here without anyone remembering to add it — the
            // hand-written version of this list had already fallen a type
            // behind, which left integration:delete failing on a foreign key
            // for any vCenter whose host devices had been collected.
            foreach (ObjectType::all() as $type) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM ' . $type->changeTable . '
                      WHERE ' . $type->changeKey . ' IN (
                            SELECT id FROM ' . $type->table . ' WHERE integration_id = :id
                      )'
                );
                $stmt->execute(['id' => $id]);
            }

            foreach (self::inventoryTables() as $table) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM ' . $table . ' WHERE integration_id = :id'
                );
                $stmt->execute(['id' => $id]);
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM raw_object WHERE integration_id = :id'
            );
            $stmt->execute(['id' => $id]);

            // The per-type outcome rows point at the run, so they go before
            // it. ON DELETE RESTRICT throughout means this cannot be left to
            // the database.
            $stmt = $this->pdo->prepare(
                'DELETE FROM discovery_run_type
                  WHERE run_id IN (
                        SELECT id FROM discovery_run WHERE integration_id = :id
                  )'
            );
            $stmt->execute(['id' => $id]);

            $stmt = $this->pdo->prepare(
                'DELETE FROM discovery_run WHERE integration_id = :id'
            );
            $stmt->execute(['id' => $id]);

            $stmt = $this->pdo->prepare('DELETE FROM integration WHERE id = :id');
            $stmt->execute(['id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
