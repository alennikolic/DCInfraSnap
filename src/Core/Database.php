<?php
// src/Core/Database.php
//
// One PDO connection, configured the same way everywhere.

declare(strict_types=1);

namespace DcInfraSnap\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * The connection, created on first use and reused afterwards.
     *
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    public static function connect(array $db): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );

        try {
            $pdo = new PDO($dsn, $db['user'], $db['password'], [
                // Errors become exceptions. Silent failure is the one thing
                // worse than a crash here: a half-written run is harder to
                // find than one that stopped.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Real prepared statements, not emulated ones. Emulation
                // returns every column as a string and quotes parameters
                // client-side; both are worth avoiding.
                PDO::ATTR_EMULATE_PREPARES   => false,

                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The DSN carries no password, but the message can still carry
            // host and user; that is fine in a log, and it is what tells you
            // whether the database is simply not up yet.
            throw new RuntimeException(
                'Cannot connect to the database (' . $db['host'] . ':' . $db['port'] . '): '
                . $e->getMessage(),
                0,
                $e
            );
        }

        // The session works in UTC regardless of how the server is set up.
        $pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo = $pdo;
    }

    /**
     * Whether the schema has been created. Used to show a useful message
     * instead of an SQL error when migrations have not been run yet.
     */
    public static function isMigrated(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM integration LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
