<?php
// src/Inventory/ObjectType.php
//
// What a kind of collected object is: where it is stored, which columns it
// has, and what its counters are called in discovery_run.
//
// Five object types would otherwise mean five near-identical repositories,
// five sets of INSERT and UPDATE, and five journal writers. Describing the
// differences as data instead means the writing is written once
// (InventoryRepository) and a sixth type is a few lines here rather than a
// new class.
//
// The columns listed are the ones a collector fills. Everything else on the
// row — first_seen, last_seen, the run ids, payload_hash — is bookkeeping
// the repository manages itself.

declare(strict_types=1);

namespace DcInfraSnap\Inventory;

final class ObjectType
{
    /**
     * @param string       $key         short name, also the raw_object enum value
     * @param string       $table       inventory table
     * @param string       $changeTable journal table
     * @param string       $changeKey   journal column pointing back at the row
     * @param list<string> $columns     columns a collector fills
     * @param string       $runPrefix   prefix of the discovery_run counters
     * @param list<string> $volatile    columns kept current but never compared
     */
    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly string $changeTable,
        public readonly string $changeKey,
        public readonly array $columns,
        public readonly string $runPrefix,
        public readonly array $volatile = [],
    ) {
    }

    /**
     * The columns that decide whether an object has changed.
     *
     * Volatile ones are left out. A column that moves on its own — a counter
     * that only goes up — would otherwise report a change on every single
     * run and bury the real ones. Such a column is still stored and still
     * kept current; it just does not get a vote on what counts as a change.
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
     * Clusters and hosts come before virtual machines because a machine
     * records which host and cluster it sits on, and a reader is better
     * served by those rows already existing.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $types = [
            new self(
                key: 'cluster',
                table: 'vc_cluster',
                changeTable: 'vc_cluster_change',
                changeKey: 'cluster_id',
                columns: [
                    'name', 'datacenter_moref', 'datacenter_name',
                    'ha_enabled', 'drs_enabled', 'drs_behavior',
                ],
                runPrefix: 'clusters',
            ),

            new self(
                key: 'host',
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
                runPrefix: 'hosts',

                // Uptime climbs by itself. Left in the comparison it marks
                // every host as changed on every run, which turns the host
                // journal into a clock and hides the changes worth seeing.
                volatile: ['uptime_seconds'],
            ),

            new self(
                key: 'vm',
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
                runPrefix: 'vms',
            ),

            new self(
                key: 'datastore',
                table: 'vc_datastore',
                changeTable: 'vc_datastore_change',
                changeKey: 'datastore_id',
                columns: [
                    'name', 'type', 'is_accessible', 'maintenance_mode',
                    'capacity_bytes', 'free_bytes', 'uncommitted_bytes',
                    'url', 'vmfs_version', 'nfs_remote_host', 'nfs_remote_path',
                    'host_count', 'vm_count', 'datacenter_moref',
                ],
                runPrefix: 'datastores',
            ),

            new self(
                key: 'host_device',
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
                runPrefix: 'host_devices',

                // Free ports on a switch move as machines power on and off,
                // which is not a configuration change and does not belong in
                // the journal.
                volatile: ['num_ports_available'],
            ),

            new self(
                key: 'license',
                table: 'vc_license',
                changeTable: 'vc_license_change',
                changeKey: 'license_id',
                columns: [
                    'name', 'edition_key', 'product_name', 'product_version',
                    'total_units', 'used_units', 'cost_unit', 'expires_at',
                ],
                runPrefix: 'licenses',
            ),
        ];

        $byKey = [];

        foreach ($types as $type) {
            $byKey[$type->key] = $type;
        }

        return $byKey;
    }

    public static function get(string $key): self
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown object type: {$key}");
        }

        return $all[$key];
    }
}
