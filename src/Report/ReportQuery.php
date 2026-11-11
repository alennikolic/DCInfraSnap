<?php
// src/Report/ReportQuery.php
//
// Everything the report reads, scoped to a chosen set of sources.
//
// SEPARATE FROM InventoryQuery ON PURPOSE. That one answers the questions a
// page asks — one host, one page of machines, filtered and paged. A report
// asks different ones: every host of these three sources, and the totals
// across them. Bending the page queries to take a source list as well would
// make both harder to read than having two.
//
// WHY A GENERATED PLACEHOLDER PER ID. PDO runs with ATTR_EMULATE_PREPARES
// set to false, so named placeholders are rewritten positionally and a name
// cannot appear twice in one statement. An IN list therefore gets :src0,
// :src1, :src2 and so on — never one name bound to several values, and never
// an id pasted into the SQL.
//
// Every method returns [] for an empty source list rather than building an
// IN () that no engine accepts.

declare(strict_types=1);

namespace DcInfraSnap\Report;

use DcInfraSnap\Integration\Integration;
use PDO;

final class ReportQuery
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    // -----------------------------------------------------------------
    // Sources
    // -----------------------------------------------------------------

    /**
     * The chosen sources of one kind, with when each was last collected.
     *
     * Only a complete run counts as collected. A partial one got some object
     * types and not others, so the date it finished says nothing useful about
     * the environment as a whole.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function sources(array $ids, string $type): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $params['type'] = $type;

        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.type, i.name, i.hostname, i.port, i.username,
                    i.product_version, i.is_active,
                    (SELECT MAX(r.finished_at) FROM discovery_run r
                      WHERE r.integration_id = i.id AND r.status = \'success\') AS last_success_at
               FROM integration i
              WHERE i.type = :type AND i.id IN ' . $in . '
              ORDER BY i.name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function vcenterSources(array $ids): array
    {
        return $this->sources($ids, Integration::TYPE_VCENTER);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function brocadeSources(array $ids): array
    {
        return $this->sources($ids, Integration::TYPE_BROCADE);
    }

    // =================================================================
    // vCenter
    // =================================================================

    /**
     * Clusters, with how many hosts and machines are in each.
     *
     * The counts are subqueries rather than stored columns, for the same
     * reason the SAN pages count their ports that way: a number kept on the
     * cluster row would have to be maintained in step with the host rows, and
     * the two would eventually disagree.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function clusters(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT c.integration_id, c.moref, c.name, c.datacenter_name,
                    c.ha_enabled, c.drs_enabled, c.drs_behavior,
                    (SELECT COUNT(*) FROM vc_host h
                      WHERE h.integration_id = c.integration_id
                        AND h.cluster_moref = c.moref)          AS host_count,
                    (SELECT COUNT(*) FROM vc_vm v
                      WHERE v.integration_id = c.integration_id
                        AND v.cluster_moref = c.moref
                        AND v.is_template = 0)                   AS vm_count
               FROM vc_cluster c
              WHERE c.integration_id IN ' . $in . '
              ORDER BY c.datacenter_name, c.name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Hosts, with the name of the cluster each belongs to.
     *
     * A standalone host has no cluster_moref and comes back with a null
     * cluster name, which the builder prints as "standalone" rather than as
     * a blank cell.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function hosts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT h.*, c.name AS cluster_name
               FROM vc_host h
               LEFT JOIN vc_cluster c
                      ON c.integration_id = h.integration_id
                     AND c.moref = h.cluster_moref
              WHERE h.integration_id IN ' . $in . '
              ORDER BY h.name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Every device of every chosen host, grouped host by host and kind by
     * kind.
     *
     * One query for the whole report rather than one per host: his two
     * vCenters hold about three hundred devices between them, and three
     * hundred round trips to lay out five tables per host is the kind of
     * thing that makes a report feel broken.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, array<string, list<array<string, mixed>>>>>
     *         integration id -> host moref -> kind -> rows
     */
    public function hostDevices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_host_device
              WHERE integration_id IN ' . $in . '
              ORDER BY host_moref, kind, device'
        );
        $stmt->execute($params);

        $grouped = [];

        while (($row = $stmt->fetch()) !== false) {
            $grouped[(int) $row['integration_id']][(string) $row['host_moref']][(string) $row['kind']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function datastores(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_datastore
              WHERE integration_id IN ' . $in . '
              ORDER BY name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Machine totals per source.
     *
     * Templates are counted but kept out of every total. A template holds
     * disk and a configured processor count, and nothing is running: adding
     * it to the vCPU total of a cluster would overstate what is actually
     * deployed, which is the figure a report of this kind is read for.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, int>> integration id -> totals
     */
    public function vmTotals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT integration_id,
                    COUNT(*)                                                AS all_count,
                    SUM(CASE WHEN is_template = 1 THEN 1 ELSE 0 END)        AS templates,
                    SUM(CASE WHEN is_template = 0 THEN 1 ELSE 0 END)        AS machines,
                    SUM(CASE WHEN is_template = 0 THEN cpu_count ELSE 0 END)           AS vcpu,
                    SUM(CASE WHEN is_template = 0 THEN memory_mib ELSE 0 END)          AS memory_mib,
                    SUM(CASE WHEN is_template = 0 THEN disk_capacity_bytes ELSE 0 END) AS disk_bytes,
                    SUM(CASE WHEN is_template = 0 THEN disk_count ELSE 0 END)          AS disks,
                    SUM(CASE WHEN is_template = 0 THEN nic_count ELSE 0 END)           AS nics
               FROM vc_vm
              WHERE integration_id IN ' . $in . '
              GROUP BY integration_id'
        );
        $stmt->execute($params);

        $totals = [];

        while (($row = $stmt->fetch()) !== false) {
            $id = (int) $row['integration_id'];

            unset($row['integration_id']);

            // Every one of these is a count or a sum, and MySQL hands a SUM
            // back as a string.
            $totals[$id] = array_map(static fn (mixed $value): int => (int) $value, $row);
        }

        return $totals;
    }

    /**
     * How many machines are in each power state, per source.
     *
     * Grouped by whatever the source reports rather than matched against a
     * list of states written here: vCenter's spelling is its own business,
     * and a state nobody anticipated should appear in the report instead of
     * disappearing from the totals.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, int>> integration id -> state -> count
     */
    public function vmPowerStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT integration_id, power_state, COUNT(*) AS count
               FROM vc_vm
              WHERE integration_id IN ' . $in . ' AND is_template = 0
              GROUP BY integration_id, power_state
              ORDER BY integration_id, power_state'
        );
        $stmt->execute($params);

        $states = [];

        while (($row = $stmt->fetch()) !== false) {
            $states[(int) $row['integration_id']][(string) $row['power_state']] = (int) $row['count'];
        }

        return $states;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function licenses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM vc_license
              WHERE integration_id IN ' . $in . '
              ORDER BY product_name, name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // =================================================================
    // Fibre Channel
    // =================================================================

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function fcSwitches(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM fc_switch
              WHERE integration_id IN ' . $in . '
              ORDER BY name'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> integration id -> members
     */
    public function fcFabricMembers(array $ids): array
    {
        return $this->groupByIntegration($ids,
            'SELECT * FROM fc_fabric_member
              WHERE integration_id IN %s
              ORDER BY domain_id'
        );
    }

    /**
     * Ports present, licensed and in use, counted from the port rows.
     *
     * The switch row carries its own port_count and licensed_port_count, as
     * the switch reported them, and those go in the switch's identity. These
     * are counted from what was actually stored, so the table that follows
     * and the numbers above it cannot disagree.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{present: int, licensed: int, in_use: int}>
     */
    public function fcPortCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(
            'SELECT integration_id,
                    COUNT(*)                                                 AS present,
                    SUM(CASE WHEN is_licensed = 1 THEN 1 ELSE 0 END)         AS licensed,
                    SUM(CASE WHEN port_type IS NOT NULL THEN 1 ELSE 0 END)   AS in_use
               FROM fc_port
              WHERE integration_id IN ' . $in . '
              GROUP BY integration_id'
        );
        $stmt->execute($params);

        $counts = [];

        while (($row = $stmt->fetch()) !== false) {
            $counts[(int) $row['integration_id']] = [
                'present'  => (int) $row['present'],
                'licensed' => (int) $row['licensed'],
                'in_use'   => (int) $row['in_use'],
            ];
        }

        return $counts;
    }

    /**
     * The ports that are actually carrying something, with what is on them.
     *
     * A port with no port_type is cabled but not logged in, or has no module
     * at all. Sixty-four rows of mostly nothing is what makes the difference
     * between a report someone reads and one they skip, so only the live
     * ports are listed and the counts above say how many there are in total.
     *
     * The join is on WWPN: switchshow says a port has a device, nsshow says
     * what that device is.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> integration id -> ports
     */
    public function fcActivePorts(array $ids): array
    {
        return $this->groupByIntegration($ids,
            'SELECT p.*, d.node_symb, d.port_symb, d.device_type, d.wwnn
               FROM fc_port p
               LEFT JOIN fc_device d
                      ON d.integration_id = p.integration_id
                     AND d.wwpn = p.attached_wwpn
              WHERE p.integration_id IN %s AND p.port_type IS NOT NULL
              ORDER BY p.port_index'
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> integration id -> links
     */
    public function fcIsls(array $ids): array
    {
        return $this->groupByIntegration($ids,
            'SELECT * FROM fc_isl
              WHERE integration_id IN %s
              ORDER BY local_port'
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> integration id -> devices
     */
    public function fcDevices(array $ids): array
    {
        return $this->groupByIntegration($ids,
            'SELECT d.*, p.port_name
               FROM fc_device d
               LEFT JOIN fc_port p
                      ON p.integration_id = d.integration_id
                     AND p.port_index = d.port_index
              WHERE d.integration_id IN %s
              ORDER BY d.port_index, d.wwpn'
        );
    }

    /**
     * Zoning objects of one section, each with its members.
     *
     * Two queries and an assembly in PHP, not a join with GROUP_CONCAT: a
     * zone can hold many members, GROUP_CONCAT truncates silently at
     * group_concat_max_len, and a report that quietly drops zone members is
     * worse than no report. This is the same reasoning as the zoning page,
     * and the same bug that cost 82 zones their members once already.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> integration id -> objects, each with 'members'
     */
    public function fcZoning(array $ids, string $section): array
    {
        if ($ids === []) {
            return [];
        }

        $section = $section === 'effective' ? 'effective' : 'defined';

        [$in, $params]   = self::inList($ids);
        $params['section'] = $section;

        $stmt = $this->pdo->prepare(
            'SELECT integration_id, kind, name, member_count
               FROM fc_zone_object
              WHERE integration_id IN ' . $in . ' AND section = :section
              ORDER BY kind, name'
        );
        $stmt->execute($params);

        $objects = [];

        while (($row = $stmt->fetch()) !== false) {
            $row['members'] = [];

            $objects[(int) $row['integration_id']][(string) $row['kind'] . "\0" . (string) $row['name']] = $row;
        }

        // The members, in their stored order. sort_order is volatile — it
        // shifts when anything before it is removed — but it is still the
        // order the switch lists them in, which is the order to print.
        [$in, $params]     = self::inList($ids);
        $params['section'] = $section;

        $stmt = $this->pdo->prepare(
            'SELECT integration_id, kind, object_name, member_name, member_kind
               FROM fc_zone_member
              WHERE integration_id IN ' . $in . ' AND section = :section
              ORDER BY object_name, sort_order'
        );
        $stmt->execute($params);

        while (($row = $stmt->fetch()) !== false) {
            $key = (string) $row['kind'] . "\0" . (string) $row['object_name'];
            $id  = (int) $row['integration_id'];

            if (isset($objects[$id][$key])) {
                $objects[$id][$key]['members'][] = $row;
            }
        }

        // Drop the assembly keys; the order from the first query is kept.
        return array_map(
            static fn (array $byKey): array => array_values($byKey),
            $objects
        );
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    /**
     * Run a query whose SQL has one %s where the IN list goes, and group the
     * rows by source.
     *
     * Every Fibre Channel table is read per switch and printed per switch, so
     * the grouping is the same every time.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByIntegration(array $ids, string $sql): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = self::inList($ids);

        $stmt = $this->pdo->prepare(sprintf($sql, $in));
        $stmt->execute($params);

        $grouped = [];

        while (($row = $stmt->fetch()) !== false) {
            $grouped[(int) $row['integration_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * An IN list with one placeholder per id.
     *
     * @param list<int> $ids
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function inList(array $ids): array
    {
        $placeholders = [];
        $params       = [];

        foreach (array_values($ids) as $index => $id) {
            $name = 'src' . $index;

            $placeholders[]  = ':' . $name;
            $params[$name]   = (int) $id;
        }

        return ['(' . implode(', ', $placeholders) . ')', $params];
    }
}
