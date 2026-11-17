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

            // ---------------------------------------------------------
            // Hyper-V
            //
            // Collected offline: a read-only PowerShell script runs on each
            // host and the resulting file is uploaded. The keys carry an hv_
            // prefix because this list is a map keyed by the key — "cluster"
            // and "vm" are already taken by vCenter, and a repeat would
            // silently replace it.
            //
            // Order: the cluster and its storage first, then the nodes, then
            // what sits on them, so a reader walking the list meets a
            // machine's host before the machine.
            // ---------------------------------------------------------
            new self(
                key: 'hv_cluster',
                source: Integration::TYPE_HYPERV,
                table: 'hv_cluster',
                changeTable: 'hv_cluster_change',
                changeKey: 'cluster_id',
                columns: [
                    'name', 'domain', 'functional_level', 'upgrade_version',
                    'quorum_type', 'quorum_resource', 'shared_volumes_root', 'node_count',
                ],
            ),

            new self(
                key: 'hv_cluster_network',
                source: Integration::TYPE_HYPERV,
                table: 'hv_cluster_network',
                changeTable: 'hv_cluster_network_change',
                changeKey: 'network_id',
                columns: [
                    'cluster_moref', 'name', 'network_role', 'network_state',
                    'address', 'address_mask', 'ipv6_address', 'metric',
                ],
            ),

            new self(
                key: 'hv_csv',
                source: Integration::TYPE_HYPERV,
                table: 'hv_csv',
                changeTable: 'hv_csv_change',
                changeKey: 'csv_id',
                columns: [
                    'cluster_moref', 'name', 'csv_state', 'owner_node',
                    'friendly_path', 'volume_name', 'file_system', 'fault_state',
                    'redirected_access', 'maintenance_mode',
                    'size_bytes', 'used_bytes', 'free_bytes',
                ],

                // Free space moves whenever a machine writes a block. The
                // owner node is deliberately not here: a volume changing
                // owner is a real event and belongs in the journal.
                volatile: ['used_bytes', 'free_bytes'],
            ),

            new self(
                key: 'hv_node',
                source: Integration::TYPE_HYPERV,
                table: 'hv_node',
                changeTable: 'hv_node_change',
                changeKey: 'node_id',
                columns: [
                    'hostname', 'fqdn', 'domain', 'part_of_domain',
                    'cluster_moref', 'cluster_node_id', 'node_state', 'drain_status',
                    'smbios_uuid', 'vendor', 'model', 'serial_number',
                    'bios_version', 'bios_release_date',
                    'cpu_model', 'cpu_sockets', 'cpu_cores', 'cpu_logical',
                    'cpu_max_clock_mhz', 'memory_bytes',
                    'os_caption', 'os_version', 'os_build', 'os_architecture',
                    'os_install_date', 'last_boot', 'uptime_seconds',
                    'hyperv_module_version', 'powershell_version',
                    'hyperv_logical_processors', 'hyperv_memory_capacity',
                    'virtual_machine_path', 'virtual_hard_disk_path',
                    'numa_spanning_enabled', 'enhanced_session_mode', 'iov_support',
                    'mac_address_minimum', 'mac_address_maximum',
                    'migration_enabled', 'migration_auth_type', 'migration_performance',
                    'max_vm_migrations', 'max_storage_migrations',
                    'replica_enabled', 'replica_auth_type', 'replica_storage_location',
                    'vm_count', 'source_collected_at',
                ],

                // Uptime climbs by itself, and the collection time is
                // different on every run by definition.
                volatile: ['uptime_seconds', 'source_collected_at'],
            ),

            new self(
                key: 'hv_host_device',
                source: Integration::TYPE_HYPERV,
                table: 'hv_host_device',
                changeTable: 'hv_host_device_change',
                changeKey: 'device_id',
                columns: [
                    'node_moref', 'kind', 'name', 'adapter_id',
                    'interface_description', 'interface_index',
                    'mac_address', 'device_status', 'link_speed', 'speed_bps',
                    'media_type', 'full_duplex', 'is_virtual',
                    'driver_name', 'driver_version',
                    'switch_name', 'switch_moref',
                    'vlan_mode', 'vlan_access_id', 'vlan_native_id', 'vlan_allowed_list',
                    'ip_addresses',
                    'teaming_mode', 'load_balancing', 'team_members',
                ],

                // Windows assigns the interface index and renumbers it on
                // reboot or when a driver reloads, which is not a change to
                // the adapter.
                volatile: ['interface_index'],
            ),

            new self(
                key: 'hv_vswitch',
                source: Integration::TYPE_HYPERV,
                table: 'hv_vswitch',
                changeTable: 'hv_vswitch_change',
                changeKey: 'vswitch_id',
                columns: [
                    'node_moref', 'name', 'switch_guid', 'switch_type',
                    'allow_management_os', 'embedded_teaming',
                    'iov_enabled', 'iov_support', 'packet_direct_enabled',
                    'bandwidth_reservation_mode',
                    'default_flow_min_absolute', 'default_flow_min_weight',
                    'uplink_adapters', 'team_teaming_mode', 'team_load_balancing',
                    'notes',
                ],
            ),

            new self(
                key: 'hv_vm',
                source: Integration::TYPE_HYPERV,
                table: 'hv_vm',
                changeTable: 'hv_vm_change',
                changeKey: 'vm_id',
                columns: [
                    'name', 'vm_state', 'vm_status', 'generation', 'config_version',
                    'owner_node_moref', 'owner_node', 'is_clustered',
                    'processor_count', 'cpu_usage_percent',
                    'cpu_reserve_percent', 'cpu_limit_percent', 'cpu_relative_weight',
                    'cpu_compat_for_migration', 'cpu_nested_virtualization',
                    'cpu_threads_per_core',
                    'memory_startup', 'memory_minimum', 'memory_maximum',
                    'dynamic_memory_enabled', 'memory_buffer_percent', 'memory_priority',
                    'memory_assigned', 'memory_demand', 'uptime_seconds',
                    'integration_services_version', 'integration_services_enabled',
                    'guest_os_name', 'guest_os_version', 'guest_hostname',
                    'secure_boot', 'secure_boot_template', 'boot_order',
                    'automatic_start_action', 'automatic_start_delay',
                    'automatic_stop_action', 'automatic_critical_action',
                    'automatic_checkpoints', 'checkpoint_type',
                    'vm_path', 'configuration_location',
                    'snapshot_file_location', 'smart_paging_file_path',
                    'created_at_source', 'notes',
                    'disk_count', 'disk_capacity_bytes', 'disk_file_bytes',
                    'nic_count', 'checkpoint_count',
                    'replication_state', 'replication_health', 'replication_mode',
                    'replication_role',
                    'replication_primary_server', 'replication_replica_server',
                    'replication_frequency_sec', 'replication_recovery_history',
                    'replicated_disk_count', 'replication_last_time',
                ],

                // Everything that moves while nothing is configured: the
                // counters a running machine produces, and the replication
                // timestamp, which advances every cycle.
                volatile: [
                    'cpu_usage_percent', 'memory_assigned', 'memory_demand',
                    'uptime_seconds', 'disk_file_bytes', 'replication_last_time',
                ],
            ),

            new self(
                key: 'hv_vm_disk',
                source: Integration::TYPE_HYPERV,
                table: 'hv_vm_disk',
                changeTable: 'hv_vm_disk_change',
                changeKey: 'disk_id',
                columns: [
                    'vm_moref', 'controller_type', 'controller_number', 'controller_location',
                    'disk_path', 'disk_number', 'is_pass_through', 'is_shared', 'supports_pr',
                    'vhd_format', 'vhd_type', 'size_bytes', 'file_size_bytes', 'parent_path',
                    'block_size', 'logical_sector_size', 'physical_sector_size',
                    'fragmentation_pct', 'disk_identifier',
                    'maximum_iops', 'minimum_iops', 'write_hardening', 'vhd_error',
                ],

                // A dynamic disk grows as the guest writes, and fragmentation
                // moves with it. Neither is a configuration change.
                volatile: ['file_size_bytes', 'fragmentation_pct'],
            ),

            new self(
                key: 'hv_vm_nic',
                source: Integration::TYPE_HYPERV,
                table: 'hv_vm_nic',
                changeTable: 'hv_vm_nic_change',
                changeKey: 'nic_id',
                columns: [
                    'vm_moref', 'name', 'mac_address', 'dynamic_mac',
                    'switch_name', 'switch_moref', 'is_connected', 'nic_status',
                    'is_legacy', 'device_naming',
                    'mac_spoofing', 'dhcp_guard', 'router_guard', 'port_mirroring',
                    'vlan_mode', 'vlan_access_id', 'vlan_native_id', 'vlan_primary_id',
                    'vlan_allowed_list', 'isolation_mode', 'isolation_vlan_id',
                    'allow_untagged',
                    'vmq_weight', 'iov_weight',
                    'min_bandwidth_absolute', 'min_bandwidth_weight', 'max_bandwidth',
                    'ip_addresses',
                ],
            ),

            new self(
                key: 'hv_vm_checkpoint',
                source: Integration::TYPE_HYPERV,
                table: 'hv_vm_checkpoint',
                changeTable: 'hv_vm_checkpoint_change',
                changeKey: 'checkpoint_id',
                columns: [
                    'vm_moref', 'name', 'checkpoint_type', 'created_at_source',
                    'parent_checkpoint_moref', 'parent_checkpoint_name',
                    'checkpoint_path', 'config_location',
                ],
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
