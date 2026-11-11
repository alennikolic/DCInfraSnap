<?php
// src/Inventory/InventoryQuery.php
//
// Reading the inventory for the interface.
//
// Separate from InventoryRepository on purpose: that one writes what a
// collection found, this one answers what a person asked. They change for
// different reasons — a new field in the collector touches the writer, a new
// filter on the machines page touches this.
//
// Filters are assembled from fixed fragments with bound parameters. Nothing
// from the query string is ever concatenated into SQL; the only values that
// reach a statement are bound.

declare(strict_types=1);

namespace DcInfraSnap\Inventory;

use Generator;
use PDO;

final class InventoryQuery
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    // =================================================================
    // "Not seen in the last collection"
    // =================================================================

    /**
     * The last COMPLETE run of each source.
     *
     * This is the reference an object's last_run_id is compared against.
     * Partial and failed runs are deliberately not counted: after one of
     * those, an object being absent may only mean collection never got to
     * it, and marking it missing would be a guess dressed up as a fact.
     *
     * @return array<int, int> integration_id => run_id
     */
    public function lastSuccessfulRuns(): array
    {
        $rows = $this->pdo->query(
            'SELECT integration_id, MAX(id) AS run_id
               FROM discovery_run
              WHERE status = \'success\'
              GROUP BY integration_id'
        )->fetchAll();

        $byIntegration = [];

        foreach ($rows as $row) {
            $byIntegration[(int) $row['integration_id']] = (int) $row['run_id'];
        }

        return $byIntegration;
    }

    // =================================================================
    // Virtual machines
    // =================================================================

    /**
     * @param array<string, string> $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function vms(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = $this->vmConditions($filters);

        $total = $this->count('vc_vm v', $where, $params);

        $offset = max(0, ($page - 1) * $perPage);

        $stmt = $this->pdo->prepare(
            $this->vmSelect() . $where . ' ORDER BY v.name, v.id LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Every machine matching the filters, for the CSV export.
     *
     * A generator, not an array: the export has no page size, and an
     * inventory of a few thousand machines held in memory as one array —
     * alongside the string being built from it — is how an export falls over
     * exactly when it is most wanted.
     *
     * @param array<string, string> $filters
     *
     * @return Generator<array<string, mixed>>
     */
    public function vmsForExport(array $filters): Generator
    {
        [$where, $params] = $this->vmConditions($filters);

        $stmt = $this->pdo->prepare($this->vmSelect() . $where . ' ORDER BY v.name, v.id');
        $stmt->execute($params);

        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    public function vm(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->vmSelect() . ' WHERE v.id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function vmSelect(): string
    {
        return 'SELECT v.*,
                       i.name AS integration_name,
                       h.name AS host_name,
                       c.name AS cluster_name
                  FROM vc_vm v
                  JOIN integration i ON i.id = v.integration_id
                  LEFT JOIN vc_host h
                         ON h.integration_id = v.integration_id AND h.moref = v.host_moref
                  LEFT JOIN vc_cluster c
                         ON c.integration_id = v.integration_id AND c.moref = v.cluster_moref';
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function vmConditions(array $filters): array
    {
        $conditions = [];
        $params     = [];

        $search = trim($filters['q'] ?? '');

        if ($search !== '') {
            // One box over the fields people actually search by: the name,
            // what the guest calls itself, its address, the identifiers, and
            // the notes — which is where the reason a machine exists tends
            // to be written down.
            // ESCAPE is stated rather than relied on, and the character is
            // "!" rather than a backslash. A backslash is itself escaped
            // inside a SQL string literal by some engines and not others,
            // so what reaches the parser depends on the engine; "!" means
            // the same thing everywhere.
            //
            // EACH FIELD GETS ITS OWN PLACEHOLDER, and that is not style.
            // With PDO::ATTR_EMULATE_PREPARES off, PDO rewrites named
            // placeholders into positional ones before handing the statement
            // to MySQL, which has no named parameters of its own. One name
            // used six times becomes six positions expecting six values, and
            // execute() fails with "Invalid parameter number".
            $fields = [
                'v.name',
                'v.guest_hostname',
                'v.guest_ip_address',
                'v.instance_uuid',
                'v.bios_uuid',
                'v.notes',
            ];

            $term  = '%' . self::escapeLike($search) . '%';
            $parts = [];

            foreach ($fields as $index => $field) {
                $parts[]                      = $field . " LIKE :search{$index} ESCAPE '!'";
                $params['search' . $index] = $term;
            }

            $conditions[] = '(' . implode("\n                           OR ", $parts) . ')';
        }

        if (($filters['integration'] ?? '') !== '') {
            $conditions[]            = 'v.integration_id = :integration';
            $params['integration'] = (int) $filters['integration'];
        }

        if (($filters['power'] ?? '') !== '') {
            $conditions[]      = 'v.power_state = :power';
            $params['power'] = $filters['power'];
        }

        if (($filters['template'] ?? '') !== '') {
            $conditions[]         = 'v.is_template = :template';
            $params['template'] = $filters['template'] === '1' ? 1 : 0;
        }

        if (($filters['cluster'] ?? '') !== '') {
            $conditions[]        = 'v.cluster_moref = :cluster';
            $params['cluster'] = $filters['cluster'];
        }

        if (($filters['host'] ?? '') !== '') {
            $conditions[]     = 'v.host_moref = :host';
            $params['host'] = $filters['host'];
        }

        if (($filters['missing'] ?? '') === '1') {
            // Objects that the last complete run did not see.
            $conditions[] = 'v.last_run_id < (
                                 SELECT COALESCE(MAX(r.id), 0) FROM discovery_run r
                                  WHERE r.integration_id = v.integration_id AND r.status = \'success\'
                             )';
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    /**
     * Values for the drop-downs on the machines page.
     *
     * @return array{integrations: list<array<string,mixed>>, clusters: list<array<string,mixed>>, hosts: list<array<string,mixed>>, powers: list<string>}
     */
    public function vmFilterOptions(): array
    {
        return [
            'integrations' => $this->pdo->query(
                'SELECT DISTINCT i.id, i.name FROM integration i
                   JOIN vc_vm v ON v.integration_id = i.id ORDER BY i.name'
            )->fetchAll(),

            'clusters' => $this->pdo->query(
                'SELECT DISTINCT c.moref, c.name FROM vc_cluster c
                   JOIN vc_vm v ON v.cluster_moref = c.moref AND v.integration_id = c.integration_id
                  ORDER BY c.name'
            )->fetchAll(),

            'hosts' => $this->pdo->query(
                'SELECT DISTINCT h.moref, h.name FROM vc_host h
                   JOIN vc_vm v ON v.host_moref = h.moref AND v.integration_id = h.integration_id
                  ORDER BY h.name'
            )->fetchAll(),

            'powers' => array_column(
                $this->pdo->query(
                    'SELECT DISTINCT power_state FROM vc_vm
                      WHERE power_state IS NOT NULL ORDER BY power_state'
                )->fetchAll(),
                'power_state'
            ),
        ];
    }

    // =================================================================
    // Hosts
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    public function hosts(): array
    {
        return $this->pdo->query(
            'SELECT h.*,
                    i.name AS integration_name,
                    c.name AS cluster_name
               FROM vc_host h
               JOIN integration i ON i.id = h.integration_id
               LEFT JOIN vc_cluster c
                      ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
              ORDER BY i.name, h.name'
        )->fetchAll();
    }

    public function host(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*, i.name AS integration_name, c.name AS cluster_name
               FROM vc_host h
               JOIN integration i ON i.id = h.integration_id
               LEFT JOIN vc_cluster c
                      ON c.integration_id = h.integration_id AND c.moref = h.cluster_moref
              WHERE h.id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The machines running on one host.
     *
     * @return list<array<string, mixed>>
     */
    public function vmsOnHost(int $integrationId, string $hostMoref): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, power_state, guest_ip_address, memory_mib, cpu_count
               FROM vc_vm
              WHERE integration_id = :id AND host_moref = :moref
              ORDER BY name'
        );
        $stmt->execute(['id' => $integrationId, 'moref' => $hostMoref]);

        return $stmt->fetchAll();
    }

    /**
     * The devices of one host, grouped by kind.
     *
     * Ordered by name, which for vmnic0..vmnic7 and vmk0..vmk3 is also the
     * order a person expects to read them in.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function hostDevices(int $integrationId, string $hostMoref): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_host_device
              WHERE integration_id = :id AND host_moref = :moref
              ORDER BY kind, device'
        );
        $stmt->execute(['id' => $integrationId, 'moref' => $hostMoref]);

        $byKind = [];

        while (($row = $stmt->fetch()) !== false) {
            $byKind[(string) $row['kind']][] = $row;
        }

        return $byKind;
    }

    // =================================================================
    // Clusters, datastores, licences
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    public function clusters(): array
    {
        return $this->pdo->query(
            'SELECT c.*,
                    i.name AS integration_name,
                    (SELECT COUNT(*) FROM vc_host h
                      WHERE h.integration_id = c.integration_id AND h.cluster_moref = c.moref) AS host_count,
                    (SELECT COUNT(*) FROM vc_vm v
                      WHERE v.integration_id = c.integration_id AND v.cluster_moref = c.moref) AS vm_count
               FROM vc_cluster c
               JOIN integration i ON i.id = c.integration_id
              ORDER BY i.name, c.name'
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function datastores(): array
    {
        return $this->pdo->query(
            'SELECT d.*, i.name AS integration_name
               FROM vc_datastore d
               JOIN integration i ON i.id = d.integration_id
              ORDER BY i.name, d.name'
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function licenses(): array
    {
        return $this->pdo->query(
            'SELECT l.*, i.name AS integration_name
               FROM vc_license l
               JOIN integration i ON i.id = l.integration_id
              ORDER BY i.name, l.name'
        )->fetchAll();
    }

    // =================================================================
    // Fibre Channel
    // =================================================================

    /**
     * Every collected switch, with the figures a reader wants at a glance.
     *
     * The counts are subqueries rather than stored columns: a port count on
     * the switch row would have to be kept in step with the port rows, and
     * the two would eventually disagree.
     *
     * @return list<array<string, mixed>>
     */
    public function fcSwitches(): array
    {
        return $this->pdo->query(
            'SELECT s.*,
                    i.name AS integration_name,
                    (SELECT COUNT(*) FROM fc_port p
                      WHERE p.integration_id = s.integration_id
                        AND p.port_type IS NOT NULL)        AS port_online_count,
                    (SELECT COUNT(*) FROM fc_device d
                      WHERE d.integration_id = s.integration_id) AS device_count,
                    (SELECT COUNT(*) FROM fc_isl l
                      WHERE l.integration_id = s.integration_id) AS isl_count,
                    (SELECT COUNT(*) FROM fc_zone_object z
                      WHERE z.integration_id = s.integration_id
                        AND z.section = \'defined\' AND z.kind = \'zone\') AS zone_count,
                    (SELECT COUNT(*) FROM fc_zone_object z
                      WHERE z.integration_id = s.integration_id
                        AND z.section = \'defined\' AND z.kind = \'alias\') AS alias_count
               FROM fc_switch s
               JOIN integration i ON i.id = s.integration_id
              ORDER BY s.name'
        )->fetchAll();
    }

    public function fcSwitch(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, i.name AS integration_name, i.hostname
               FROM fc_switch s
               JOIN integration i ON i.id = s.integration_id
              WHERE s.id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The other switches in this switch's fabric.
     *
     * @return list<array<string, mixed>>
     */
    public function fcFabricMembers(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fc_fabric_member
              WHERE integration_id = :id
              ORDER BY domain_id'
        );
        $stmt->execute(['id' => $integrationId]);

        return $stmt->fetchAll();
    }

    /**
     * Every port, with what the name server knows about whatever is attached.
     *
     * The join is on WWPN: switchshow says a port has a device, nsshow says
     * what that device is, and the port page is where both are wanted.
     *
     * @return list<array<string, mixed>>
     */
    public function fcPorts(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*,
                    d.node_symb, d.port_symb, d.device_type, d.fc4_types, d.wwnn
               FROM fc_port p
               LEFT JOIN fc_device d
                      ON d.integration_id = p.integration_id
                     AND d.wwpn = p.attached_wwpn
              WHERE p.integration_id = :id
              ORDER BY p.port_index'
        );
        $stmt->execute(['id' => $integrationId]);

        return $stmt->fetchAll();
    }

    /**
     * Devices logged in to the fabric through this switch.
     *
     * @return list<array<string, mixed>>
     */
    public function fcDevices(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, p.port_name
               FROM fc_device d
               LEFT JOIN fc_port p
                      ON p.integration_id = d.integration_id
                     AND p.port_index = d.port_index
              WHERE d.integration_id = :id
              ORDER BY d.port_index, d.wwpn'
        );
        $stmt->execute(['id' => $integrationId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fcIsls(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fc_isl WHERE integration_id = :id ORDER BY local_port'
        );
        $stmt->execute(['id' => $integrationId]);

        return $stmt->fetchAll();
    }

    /**
     * Zoning objects with their members, for one section.
     *
     * Two queries rather than a join with GROUP_CONCAT: a zone can hold many
     * members, GROUP_CONCAT truncates silently at group_concat_max_len, and
     * a zoning page that quietly drops members is worse than no page.
     *
     * @param string               $section 'defined' or 'effective'
     * @param array<string, mixed> $filters kind, search
     *
     * @return list<array<string, mixed>> each with a 'members' list
     */
    public function fcZoning(string $section, array $filters = []): array
    {
        $section = $section === 'effective' ? 'effective' : 'defined';

        $where  = ['z.section = :section'];
        $params = ['section' => $section];

        $kind = (string) ($filters['kind'] ?? '');

        if (in_array($kind, ['cfg', 'zone', 'alias'], true)) {
            $where[]        = 'z.kind = :kind';
            $params['kind'] = $kind;
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            // The object's own name, or the name of anything in it: looking
            // up a WWPN to find its zones is the main reason to search here.
            $term = '%' . self::escapeLike($search) . '%';

            $where[] = "(z.name LIKE :name ESCAPE '!' OR EXISTS (
                            SELECT 1 FROM fc_zone_member m
                             WHERE m.integration_id = z.integration_id
                               AND m.section = z.section
                               AND m.kind = z.kind
                               AND m.object_name = z.name
                               AND m.member_name LIKE :member ESCAPE '!'
                        ))";

            // Two placeholders for one value: with native prepares a name
            // cannot be reused within a statement.
            $params['name']   = $term;
            $params['member'] = $term;
        }

        $stmt = $this->pdo->prepare(
            'SELECT z.*, i.name AS integration_name, s.name AS switch_name
               FROM fc_zone_object z
               JOIN integration i ON i.id = z.integration_id
               LEFT JOIN fc_switch s ON s.integration_id = z.integration_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY i.name, FIELD(z.kind, \'cfg\', \'zone\', \'alias\'), z.name'
        );
        $stmt->execute($params);

        $objects = $stmt->fetchAll();

        if ($objects === []) {
            return [];
        }

        // Members for the whole page in one query, keyed to their object.
        $members = $this->pdo->prepare(
            'SELECT integration_id, kind, object_name, member_name, member_kind
               FROM fc_zone_member
              WHERE section = :section
              ORDER BY sort_order'
        );
        $members->execute(['section' => $section]);

        $byObject = [];

        while (($row = $members->fetch()) !== false) {
            $key              = $row['integration_id'] . '|' . $row['kind'] . '|' . $row['object_name'];
            $byObject[$key][] = $row;
        }

        foreach ($objects as $index => $object) {
            $key = $object['integration_id'] . '|' . $object['kind'] . '|' . $object['name'];

            $objects[$index]['members'] = $byObject[$key] ?? [];
        }

        return $objects;
    }

    /**
     * Which zones and aliases a WWPN belongs to.
     *
     * Looked up by member name across every switch, which is what makes this
     * worth storing per membership: the same WWPN appears in the zoning of
     * both fabrics, and this finds all of it.
     *
     * @return list<array<string, mixed>>
     */
    public function fcZonesOfMember(string $memberName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, i.name AS integration_name
               FROM fc_zone_member m
               JOIN integration i ON i.id = m.integration_id
              WHERE m.member_name = :member
              ORDER BY i.name, m.section, m.kind, m.object_name'
        );
        $stmt->execute(['member' => $memberName]);

        return $stmt->fetchAll();
    }

    // =================================================================
    // The change journal
    // =================================================================

    /**
     * What changed about one object, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function changes(ObjectType $type, int $objectId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ch.*, r.queued_at, r.trigger_source
               FROM ' . $type->changeTable . ' ch
               JOIN discovery_run r ON r.id = ch.run_id
              WHERE ch.' . $type->changeKey . ' = :id
              ORDER BY ch.changed_at DESC, ch.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['id' => $objectId]);

        return $stmt->fetchAll();
    }

    // =================================================================
    // The database page
    // =================================================================

    /**
     * Every table, with its real row count.
     *
     * The counts come from COUNT(*) rather than information_schema: the
     * statistics there are an estimate and can be out by a wide margin,
     * which makes the page look broken to anyone who checks.
     *
     * @return list<array{name: string, rows: int, comment: string}>
     */
    public function tables(string $database): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment,
                    DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = \'BASE TABLE\'
              ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $database]);

        $tables = [];

        foreach ($stmt->fetchAll() as $row) {
            $name = (string) $row['name'];

            // The name comes from information_schema, not from the request,
            // so it cannot be anything but a real table in this database.
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();

            $tables[] = [
                'name'        => $name,
                'rows'        => $count,
                'comment'     => (string) $row['comment'],
                'data_bytes'  => (int) $row['data_bytes'],
                'index_bytes' => (int) $row['index_bytes'],
            ];
        }

        return $tables;
    }

    /**
     * A page of rows from one table, for looking at.
     *
     * Read only, and the table name is checked against the list of real
     * tables before it reaches a query — a name cannot be interpolated
     * safely any other way, since a table name cannot be a bound parameter.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int}|null
     */
    public function tableRows(string $database, string $table, int $page = 1, int $perPage = 50): ?array
    {
        $known = array_column($this->tables($database), 'name');

        if (!in_array($table, $known, true)) {
            return null;
        }

        $total  = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);

        // The stored credential is not selected at all, rather than selected
        // and hidden in the template. A value that is never read cannot be
        // leaked by a later change to how this page renders.
        $columnStmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table
                AND COLUMN_NAME NOT IN (\'password_encrypted\')
              ORDER BY ORDINAL_POSITION'
        );
        $columnStmt->execute(['db' => $database, 'table' => $table]);

        $names = array_column($columnStmt->fetchAll(), 'COLUMN_NAME');

        if ($names === []) {
            return null;
        }

        $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $names));

        $stmt = $this->pdo->prepare(
            'SELECT ' . $select . ' FROM `' . $table . '` LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'columns' => $names,
            'rows'    => $stmt->fetchAll(),
            'total'   => $total,
        ];
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function count(string $from, string $where, array $params): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $from . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Escape the wildcards in a search term.
     *
     * Without this, a search for "10.0.0.1_" quietly matches more than it
     * was asked to, because _ is a wildcard in LIKE. The escape character
     * is "!", matching the ESCAPE clause above; it is escaped first, or it
     * would corrupt the escapes added after it.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
