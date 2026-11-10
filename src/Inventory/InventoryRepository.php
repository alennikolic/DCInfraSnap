<?php
// src/Inventory/InventoryRepository.php
//
// Writing collected objects into the inventory layer, and recording what
// changed.
//
// Written once for every object type. What differs between a cluster and a
// datastore is described by ObjectType, not by another copy of this code.
//
// For each object:
//   unchanged  ->  refresh last_seen and last_run_id, nothing else
//   changed    ->  update the row and write a journal entry holding ONLY the
//                  fields that actually differ
//   new        ->  insert, with first_seen and first_run_id set
//
// Whether something changed is decided by the hash alone, computed here from
// the normalized fields. Comparing column by column is used only to fill the
// journal with the difference — and that comparison is loose on purpose,
// because MySQL hands back "1" where PHP sent true.
//
// The hash covers the normalized fields rather than the raw payload: any
// field vCenter returns but DCInfraSnap does not keep would otherwise
// produce a phantom change on every run.

declare(strict_types=1);

namespace DcInfraSnap\Inventory;

use PDO;

final class InventoryRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Write every collected object of one type.
     *
     * @param list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}> $objects
     *
     * @return array{found: int, changed: int}
     */
    public function sync(ObjectType $type, int $integrationId, int $runId, array $objects): array
    {
        $existing = $this->existingByMoref($type, $integrationId);
        $changed  = 0;

        foreach ($objects as $object) {
            $fields = self::normalize($type, $object['fields']);
            $hash   = self::hash($fields);
            $moref  = $object['moref'];

            $current = $existing[$moref] ?? null;

            if ($current === null) {
                $this->insert($type, $integrationId, $runId, $moref, $fields, $hash);
                $changed++;
                continue;
            }

            if (hash_equals((string) $current['payload_hash'], $hash)) {
                $this->touch($type, (int) $current['id'], $runId);
                continue;
            }

            $this->update($type, (int) $current['id'], $runId, $fields, $hash);
            $this->journal($type, (int) $current['id'], $runId, $current, $fields);

            $changed++;
        }

        return ['found' => count($objects), 'changed' => $changed];
    }

    // -----------------------------------------------------------------
    // Reading current state
    // -----------------------------------------------------------------

    /**
     * Every row of this type for this source, keyed by moref.
     *
     * Read in one query rather than one per object: on an inventory of a
     * couple of thousand machines the difference is a single round trip
     * against a couple of thousand.
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingByMoref(ObjectType $type, int $integrationId): array
    {
        $columns = implode(', ', array_merge(['id', 'moref', 'payload_hash'], $type->columns));

        $stmt = $this->pdo->prepare(
            'SELECT ' . $columns . ' FROM ' . $type->table . ' WHERE integration_id = :id'
        );
        $stmt->execute(['id' => $integrationId]);

        $byMoref = [];

        while (($row = $stmt->fetch()) !== false) {
            $byMoref[(string) $row['moref']] = $row;
        }

        return $byMoref;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    private function insert(
        ObjectType $type,
        int $integrationId,
        int $runId,
        string $moref,
        array $fields,
        string $hash,
    ): void {
        $columns = array_merge(
            ['integration_id', 'moref', 'payload_hash', 'first_run_id', 'last_run_id'],
            $type->columns
        );

        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . $type->table . ' (' . implode(', ', $columns)
            . ', first_seen, last_seen) VALUES (' . implode(', ', $placeholders)
            . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())';

        $params = [
            'integration_id' => $integrationId,
            'moref'          => $moref,
            'payload_hash'   => $hash,
            'first_run_id'   => $runId,
            'last_run_id'    => $runId,
        ];

        foreach ($type->columns as $column) {
            $params[$column] = $fields[$column] ?? null;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    private function update(ObjectType $type, int $id, int $runId, array $fields, string $hash): void
    {
        $assignments = array_map(static fn (string $c): string => $c . ' = :' . $c, $type->columns);

        $sql = 'UPDATE ' . $type->table . ' SET ' . implode(', ', $assignments)
            . ', payload_hash = :payload_hash, last_run_id = :last_run_id,'
            . ' last_seen = UTC_TIMESTAMP() WHERE id = :id';

        $params = [
            'id'           => $id,
            'payload_hash' => $hash,
            'last_run_id'  => $runId,
        ];

        foreach ($type->columns as $column) {
            $params[$column] = $fields[$column] ?? null;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Nothing changed: the object was simply seen again.
     */
    private function touch(ObjectType $type, int $id, int $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $type->table . '
                SET last_seen = UTC_TIMESTAMP(), last_run_id = :run
              WHERE id = :id'
        );

        $stmt->execute(['id' => $id, 'run' => $runId]);
    }

    /**
     * Record the difference between what was stored and what arrived.
     *
     * Only the fields that actually differ are written. A journal entry
     * holding the whole object would be a copy of the row, and reading it
     * would mean diffing it by hand later.
     */
    private function journal(ObjectType $type, int $id, int $runId, array $current, array $fields): void
    {
        $old = [];
        $new = [];

        foreach ($type->columns as $column) {
            $before = $current[$column] ?? null;
            $after  = $fields[$column] ?? null;

            if (self::same($before, $after)) {
                continue;
            }

            $old[$column] = $before;
            $new[$column] = $after;
        }

        if ($old === []) {
            // The hash moved but no column did. That means the hash covers
            // something the column comparison does not, which would be a bug
            // here rather than a change worth recording.
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $type->changeTable . '
                (' . $type->changeKey . ', run_id, changed_at, old_values, new_values)
             VALUES (:id, :run, UTC_TIMESTAMP(), :old, :new)'
        );

        $stmt->execute([
            'id'  => $id,
            'run' => $runId,
            'old' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    // -----------------------------------------------------------------
    // Comparison
    // -----------------------------------------------------------------

    /**
     * Keep only the columns this type declares, in a fixed order.
     *
     * Fixed order matters: the hash is taken over this array, and two
     * identical objects whose keys arrived in a different order must not
     * hash differently.
     *
     * @return array<string, scalar|null>
     */
    private static function normalize(ObjectType $type, array $fields): array
    {
        $out = [];

        foreach ($type->columns as $column) {
            $value = $fields[$column] ?? null;

            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $out[$column] = $value;
        }

        return $out;
    }

    private static function hash(array $fields): string
    {
        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Whether two stored values are the same thing.
     *
     * Loose on purpose. What comes back from MySQL is not what PHP sent:
     * an integer column returns an int but a DECIMAL returns a string, and
     * TINYINT(1) returns 1 where the collector produced true. A strict
     * comparison here would report a change on every single run.
     */
    private static function same(mixed $before, mixed $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return (string) $before === (string) $after
                || abs((float) $before - (float) $after) < 0.000001;
        }

        return (string) $before === (string) $after;
    }
}
