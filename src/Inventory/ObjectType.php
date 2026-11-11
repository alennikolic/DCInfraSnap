<?php
// src/Inventory/ObjectType.php
//
// What a kind of collected object is: which source it comes from, where it
// is stored, which columns it has, and which of them are worth comparing.
//
// Thirteen object types would otherwise mean thirteen near-identical
// repositories, thirteen sets of INSERT and UPDATE, and thirteen journal
// writers. Describing the differences as data instead means the writing is
// written once (InventoryRepository) and a fourteenth type is a few lines
// here rather than a new class.
//
// The columns listed are the ones a collector fills. Everything else on the
// row — first_seen, last_seen, the run ids, payload_hash — is bookkeeping
// the repository manages itself.
//
// THE LIST IS ALSO THE DELETE ORDER AND THE RUN REPORT. IntegrationRepository
// walks it to clear up after a deleted source, and RunRepository records one
// row per type per run against the key below. A type missing from here is a
// table nothing cleans up and nothing reports, which is why adding one to
// this list is the whole of the work.

declare(strict_types=1);

namespace DcInfraSnap\Inventory;

use DcInfraSnap\Integration\Integration;
use InvalidArgumentException;

final class ObjectType
{
    /**
     * @param string       $key         short name, also the raw_object enum value
     * @param string       $source      Integration::TYPE_* this type comes from
     * @param string       $table       inventory table
     * @param string       $changeTable journal table
     * @param string       $changeKey   journal column pointing back at the row
     * @param list<string> $columns     columns a collector fills
     * @param list<string> $volatile    columns kept current but never compared
     */
    public function __construct(
        public readonly string $key,
        public readonly string $source,
        public readonly string $table,
        public readonly string $changeTable,
        public readonly string $changeKey,
        public readonly array $columns,
        public readonly array $volatile = [],
    ) {
    }

    /**
     * The columns that decide whether an object has changed.
     *
     * Volatile ones are left out. A column that moves on its own — a counter
     * that only goes up, an index that renumbers when a neighbour disappears
     * — would otherwise report a change on every single run and bury the real
     * ones. Such a column is still stored and still kept current; it just
     * does not get a vote on what counts as a change.
     *
     * @return list<string>
     */
    public function comparedColumns(): array
    {
        return array_values(array_diff($this->columns, $this->volatile));
    }

    /**
     * Every type, in collection order.
     *
     * Order matters twice over. Within vCenter, clusters and hosts come
     * before virtual machines because a machine records which host and
     * cluster it sits on, and a reader is better served by those rows already
     * existing. Within a switch, the switch itself comes first for the same
     * reason, and zone members come last because they name the objects above
     * them.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $types = [
            // ---------------------------------------------------------
            // vCenter
            // ---------------------------------------------------------
            new self(
                key: 'cluster',
                source: Integration::TYPE_VCENTER,
                table: 'vc_cluster',
                changeTable: 'vc_cluster_change',
                changeKey: 'cluster_id',
                columns: [
                    'name', 'datacenter_moref', 'datacenter_name',
                    'ha_enabled', 'drs_enabled', 'drs_behavior',
                ],
            ),

            new self(
                key: 'host',
                source: Integration::TYPE_VCENTER,
                table: 'vc_host',
                changeTable: 'vc_host_change',
                changeKey: 'host_id',
                columns: [
                    'name', 'connection_state', 'power_state', 'in_maintenance',
                    'cluster_moref', 'datacenter_moref',
                    'vendor', 'model', 'serial_number', 'asset_tag', 'hardware_uuid',
                    'cpu_model', 'cpu_sockets', 'cpu_cores', 'cpu_threads', 'cpu_mhz',
                    'memory_bytes', 'esxi_version', 'esxi_build', 'bios_version',
                    'vm_count', 'uptime_seconds',
                ],

                // Uptime climbs by itself. Left in the comparison it marks
                // every host as changed on every run, which turns the host
                // journal into a clock and hides the changes worth seeing.
                volatile: ['uptime_seconds'],
            ),

            new self(
                key: 'vm',
                source: Integration::TYPE_VCENTER,
                table: 'vc_vm',
                changeTable: 'vc_vm_change',
                changeKey: 'vm_id',
                columns: [
                    'instance_uuid', 'bios_uuid', 'name', 'power_state', 'is_template',
                    'guest_os', 'notes', 'hardware_version',
                    'cpu_count', 'cpu_cores_per_socket', 'memory_mib',
                    'disk_count', 'disk_capacity_bytes', 'nic_count',
                    'guest_state', 'guest_tools_status', 'guest_hostname',
                    'guest_ip_address', 'guest_full_name',
                    'host_moref', 'cluster_moref', 'datacenter_moref', 'folder_path',
                ],
            ),

            new self(
                key: 'datastore',
                source: Integration::TYPE_VCENTER,
                table: 'vc_datastore',
                changeTable: 'vc_datastore_change',
                changeKey: 'datastore_id',
                columns: [
                    'name', 'type', 'is_accessible', 'maintenance_mode',
                    'capacity_bytes', 'free_bytes', 'uncommitted_bytes',
                    'url', 'vmfs_version', 'nfs_remote_host', 'nfs_remote_path',
                    'host_count', 'vm_count', 'datacenter_moref',
                ],
            ),

            new self(
                key: 'host_device',
                source: Integration::TYPE_VCENTER,
                table: 'vc_host_device',
                changeTable: 'vc_host_device_change',
                changeKey: 'device_id',
                columns: [
                    'host_moref', 'kind', 'device',
                    'mac', 'driver', 'driver_version', 'firmware_version',
                    'speed_mb', 'duplex', 'pci',
                    'ip_address', 'subnet_mask', 'gateway', 'dhcp', 'mtu', 'port_group',
                    'num_ports', 'num_ports_available',
                    'vlan_id', 'vswitch_name',
                    'hba_type', 'model', 'status', 'storage_protocol',
                    'wwpn', 'wwnn', 'iscsi_name',
                ],

                // Free ports on a switch move as machines power on and off,
                // which is not a configuration change and does not belong in
                // the journal.
                volatile: ['num_ports_available'],
            ),

            new self(
                key: 'license',
                source: Integration::TYPE_VCENTER,
                table: 'vc_license',
                changeTable: 'vc_license_change',
                changeKey: 'license_id',
                columns: [
                    'name', 'edition_key', 'product_name', 'product_version',
                    'total_units', 'used_units', 'cost_unit', 'expires_at',
                ],
            ),

            // ---------------------------------------------------------
            // Brocade
            // ---------------------------------------------------------
            new self(
                key: 'fc_switch',
                source: Integration::TYPE_BROCADE,
                table: 'fc_switch',
                changeTable: 'fc_switch_change',
                changeKey: 'switch_id',
                columns: [
                    'name', 'switch_wwn', 'domain_id', 'switch_id', 'switch_type',
                    'state', 'switch_mode', 'switch_role',
                    'zoning_enabled', 'effective_config',
                    'vf_id', 'is_base_switch', 'is_default_switch', 'fc_router',
                    'fos_version', 'kernel_version', 'bootprom',
                    'fabric_name', 'principal_wwn', 'fabric_member_count',
                    'serial_number', 'oem_serial_number',
                    'part_number', 'oem_part_number', 'oem_id',
                    'port_count', 'licensed_port_count',
                ],
            ),

            new self(
                key: 'fc_fabric_member',
                source: Integration::TYPE_BROCADE,
                table: 'fc_fabric_member',
                changeTable: 'fc_fabric_member_change',
                changeKey: 'member_id',
                columns: [
                    'domain_id', 'switch_id', 'switch_wwn', 'switch_name',
                    'ip_address', 'fc_ip', 'is_principal', 'is_local',
                ],
            ),

            new self(
                key: 'fc_port',
                source: Integration::TYPE_BROCADE,
                table: 'fc_port',
                changeTable: 'fc_port_change',
                changeKey: 'port_id',
                columns: [
                    'port_index', 'slot', 'port_wwn', 'port_name', 'name_is_default',
                    'fcid', 'media', 'speed_gb', 'speed_mode', 'state', 'protocol',
                    'port_type', 'attached_wwpn', 'npiv_count',
                    'neighbor_switch_wwn', 'neighbor_switch_name',
                    'trunk_master_port', 'is_trunk_master', 'is_licensed',
                ],
            ),

            new self(
                key: 'fc_isl',
                source: Integration::TYPE_BROCADE,
                table: 'fc_isl',
                changeTable: 'fc_isl_change',
                changeKey: 'isl_id',
                columns: [
                    'isl_index', 'local_port', 'remote_port',
                    'remote_switch_wwn', 'remote_domain_id', 'remote_switch_name',
                    'speed_gb', 'bandwidth_gb', 'flags',
                    'is_trunk', 'trunk_group', 'member_count', 'member_ports',
                ],

                // islshow numbers its rows from 1 and renumbers them when a
                // link goes away, so the index of a surviving ISL moves for
                // reasons that have nothing to do with that ISL.
                volatile: ['isl_index'],
            ),

            new self(
                key: 'fc_device',
                source: Integration::TYPE_BROCADE,
                table: 'fc_device',
                changeTable: 'fc_device_change',
                changeKey: 'device_id',
                columns: [
                    'entry_type', 'fcid', 'port_index',
                    'wwpn', 'wwnn', 'permanent_wwpn', 'fabric_port_wwn',
                    'node_symb', 'port_symb',
                    'device_type', 'fc4_types', 'link_speed',
                    'cos', 'scr', 'is_npiv',
                ],
            ),

            new self(
                key: 'fc_zone_object',
                source: Integration::TYPE_BROCADE,
                table: 'fc_zone_object',
                changeTable: 'fc_zone_object_change',
                changeKey: 'object_id',
                columns: ['section', 'kind', 'name', 'member_count'],
            ),

            new self(
                key: 'fc_zone_member',
                source: Integration::TYPE_BROCADE,
                table: 'fc_zone_member',
                changeTable: 'fc_zone_member_change',
                changeKey: 'member_id',
                columns: [
                    'section', 'kind', 'object_name',
                    'member_name', 'member_kind', 'sort_order',
                ],

                // Position shifts when anything before it is removed, which
                // is not a change to this member.
                volatile: ['sort_order'],
            ),
        ];

        $byKey = [];

        foreach ($types as $type) {
            $byKey[$type->key] = $type;
        }

        return $byKey;
    }

    /**
     * The types one kind of source produces, in collection order.
     *
     * This is what the collector iterates. A vCenter run must not record a
     * switch type as missing, and the other way round, so the two sets never
     * meet outside this method.
     *
     * @return array<string, self>
     */
    public static function forSource(string $sourceType): array
    {
        return array_filter(
            self::all(),
            static fn (self $type): bool => $type->source === $sourceType
        );
    }

    public static function get(string $key): self
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new InvalidArgumentException("Unknown object type: {$key}");
        }

        return $all[$key];
    }

    /**
     * The collection order of a key, counting from 1.
     *
     * Stored on the run so the Runs page can show the types in the order they
     * were collected without needing to know what any of them are.
     */
    public static function orderOf(string $key): int
    {
        $order = 1;

        foreach (self::all() as $existing => $type) {
            if ($existing === $key) {
                return $order;
            }

            $order++;
        }

        return 0;
    }
}
