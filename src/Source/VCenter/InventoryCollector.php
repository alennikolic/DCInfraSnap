<?php
// src/Source/VCenter/InventoryCollector.php
//
// Reads an inventory out of one vCenter and hands back plain rows.
//
// One method per object type, each returning a list of:
//   ['moref' => string, 'fields' => array<column, value>, 'raw' => array]
//
// 'fields' is what goes into the inventory table; 'raw' is what vCenter
// actually said, kept whole so a field nobody thought to extract today can
// be recovered later without rescanning anything.
//
// Each type is fetched in its own call rather than all of them in one. One
// call would be cheaper, but a permission error on datastores would then
// take the virtual machines down with it. Collection is seconds either way;
// losing an entire run to one unreadable type is not worth the saving.
//
// Normalization happens here, on the way out: poweredOn becomes POWERED_ON
// and so on, so what is stored does not carry vim25's spelling into every
// query and report downstream.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

final class InventoryCollector
{
    private ?EntityTree $tree = null;

    /** host moref => cluster moref, built while collecting hosts. */
    private array $hostToCluster = [];

    public function __construct(
        private readonly Vim25Client $client,
    ) {
    }

    // =================================================================
    // The inventory tree
    // =================================================================

    /**
     * Folders, datacentres and compute resources, so placement can be
     * resolved without a call per object.
     */
    public function loadTree(): EntityTree
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $tree = new EntityTree();

        $objects = $this->client->retrieve([
            'Folder'          => ['name', 'parent'],
            'Datacenter'      => ['name', 'parent'],
            'ComputeResource' => ['name', 'parent'],
        ]);

        foreach ($objects as $moref => $props) {
            $tree->add(
                (string) $moref,
                (string) ($props['_type'] ?? ''),
                self::str($props['name'] ?? null),
                self::str($props['parent'] ?? null),
            );
        }

        return $this->tree = $tree;
    }

    // =================================================================
    // Clusters
    // =================================================================

    /**
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function clusters(): array
    {
        $tree = $this->loadTree();

        $objects = $this->client->retrieve([
            'ClusterComputeResource' => [
                'name',
                'parent',
                'configuration.dasConfig.enabled',
                'configuration.drsConfig.enabled',
                'configuration.drsConfig.defaultVmBehavior',
            ],
        ]);

        $rows = [];

        foreach ($objects as $moref => $props) {
            [$dcMoref, $dcName] = $tree->datacenterOf(self::str($props['parent'] ?? null));

            $rows[] = [
                'moref'  => (string) $moref,
                'raw'    => $props,
                'fields' => [
                    'name'             => self::str($props['name'] ?? null),
                    'datacenter_moref' => $dcMoref,
                    'datacenter_name'  => $dcName,
                    'ha_enabled'       => self::bool($props['configuration.dasConfig.enabled'] ?? null),
                    'drs_enabled'      => self::bool($props['configuration.drsConfig.enabled'] ?? null),
                    'drs_behavior'     => self::str($props['configuration.drsConfig.defaultVmBehavior'] ?? null),
                ],
            ];
        }

        return $rows;
    }

    // =================================================================
    // Hosts
    // =================================================================

    /**
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function hosts(): array
    {
        $tree = $this->loadTree();

        $objects = $this->client->retrieve([
            'HostSystem' => [
                'name',
                'parent',
                'vm',
                'runtime.connectionState',
                'runtime.powerState',
                'runtime.inMaintenanceMode',
                'summary.hardware.vendor',
                'summary.hardware.model',
                'summary.hardware.uuid',
                'summary.hardware.cpuModel',
                'summary.hardware.numCpuPkgs',
                'summary.hardware.numCpuCores',
                'summary.hardware.numCpuThreads',
                'summary.hardware.cpuMhz',
                'summary.hardware.memorySize',
                'summary.hardware.otherIdentifyingInfo',
                'summary.config.product.version',
                'summary.config.product.build',
                'summary.quickStats.uptime',
                'hardware.biosInfo.biosVersion',
                'hardware.systemInfo.serialNumber',
            ],
        ]);

        $rows = [];

        foreach ($objects as $moref => $props) {
            $parent = self::str($props['parent'] ?? null);

            // A host's parent is a ComputeResource. It only counts as a
            // cluster when it is a ClusterComputeResource — a standalone
            // host also has one, generated and meaningless to a reader.
            $cluster = $parent !== null && $tree->kind($parent) === 'ClusterComputeResource'
                ? $parent
                : null;

            $this->hostToCluster[(string) $moref] = $cluster;

            [$dcMoref] = $tree->datacenterOf($parent);

            $rows[] = [
                'moref'  => (string) $moref,
                'raw'    => $props,
                'fields' => [
                    'name'             => self::str($props['name'] ?? null),
                    'connection_state' => self::constant($props['runtime.connectionState'] ?? null),
                    'power_state'      => self::constant($props['runtime.powerState'] ?? null),
                    'in_maintenance'   => self::bool($props['runtime.inMaintenanceMode'] ?? null),
                    'cluster_moref'    => $cluster,
                    'datacenter_moref' => $dcMoref,

                    'vendor'        => self::str($props['summary.hardware.vendor'] ?? null),
                    'model'         => self::str($props['summary.hardware.model'] ?? null),
                    'serial_number' => self::serialNumber($props),
                    'asset_tag'     => self::identifyingInfo($props, ['AssetTag']),
                    'hardware_uuid' => self::str($props['summary.hardware.uuid'] ?? null),

                    'cpu_model'   => self::str($props['summary.hardware.cpuModel'] ?? null),
                    'cpu_sockets' => self::int($props['summary.hardware.numCpuPkgs'] ?? null),
                    'cpu_cores'   => self::int($props['summary.hardware.numCpuCores'] ?? null),
                    'cpu_threads' => self::int($props['summary.hardware.numCpuThreads'] ?? null),
                    'cpu_mhz'     => self::int($props['summary.hardware.cpuMhz'] ?? null),

                    'memory_bytes' => self::int($props['summary.hardware.memorySize'] ?? null),

                    'esxi_version' => self::str($props['summary.config.product.version'] ?? null),
                    'esxi_build'   => self::str($props['summary.config.product.build'] ?? null),
                    'bios_version' => self::str($props['hardware.biosInfo.biosVersion'] ?? null),

                    'vm_count'       => self::count($props['vm'] ?? null),
                    'uptime_seconds' => self::int($props['summary.quickStats.uptime'] ?? null),
                ],
            ];
        }

        return $rows;
    }

    // =================================================================
    // Virtual machines
    // =================================================================

    /**
     * Call hosts() first: the cluster a machine belongs to is the cluster of
     * the host it runs on, and that map is built while hosts are collected.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function vms(): array
    {
        $tree = $this->loadTree();

        $objects = $this->client->retrieve([
            'VirtualMachine' => [
                'name',
                'parent',
                'config.instanceUuid',
                'config.uuid',
                'config.template',
                'config.version',
                'config.guestId',
                'config.annotation',
                'config.hardware.numCPU',
                'config.hardware.numCoresPerSocket',
                'config.hardware.memoryMB',
                'config.hardware.device',
                'runtime.powerState',
                'runtime.host',
                'guest.hostName',
                'guest.ipAddress',
                'guest.guestState',
                'guest.guestFullName',
                'guest.toolsStatus',
            ],
        ]);

        $rows = [];

        foreach ($objects as $moref => $props) {
            $host   = self::str($props['runtime.host'] ?? null);
            $parent = self::str($props['parent'] ?? null);

            [$disks, $capacity, $nics] = self::devices($props['config.hardware.device'] ?? null);

            [$dcMoref] = $tree->datacenterOf($parent);

            $rows[] = [
                'moref'  => (string) $moref,
                'raw'    => $props,
                'fields' => [
                    'instance_uuid' => self::str($props['config.instanceUuid'] ?? null),
                    'bios_uuid'     => self::str($props['config.uuid'] ?? null),
                    'name'          => self::str($props['name'] ?? null),
                    'power_state'   => self::constant($props['runtime.powerState'] ?? null),
                    'is_template'   => self::bool($props['config.template'] ?? null) ? 1 : 0,

                    'guest_os'         => self::str($props['config.guestId'] ?? null),
                    'notes'            => self::str($props['config.annotation'] ?? null),
                    'hardware_version' => self::str($props['config.version'] ?? null),

                    'cpu_count'            => self::int($props['config.hardware.numCPU'] ?? null),
                    'cpu_cores_per_socket' => self::int($props['config.hardware.numCoresPerSocket'] ?? null),
                    'memory_mib'           => self::int($props['config.hardware.memoryMB'] ?? null),

                    'disk_count'          => $disks,
                    'disk_capacity_bytes' => $capacity,
                    'nic_count'           => $nics,

                    'guest_state'        => self::str($props['guest.guestState'] ?? null),
                    'guest_tools_status' => self::str($props['guest.toolsStatus'] ?? null),
                    'guest_hostname'     => self::str($props['guest.hostName'] ?? null),
                    'guest_ip_address'   => self::str($props['guest.ipAddress'] ?? null),
                    'guest_full_name'    => self::str($props['guest.guestFullName'] ?? null),

                    'host_moref'       => $host,
                    'cluster_moref'    => $host !== null ? ($this->hostToCluster[$host] ?? null) : null,
                    'datacenter_moref' => $dcMoref,
                    'folder_path'      => $tree->folderPathOf($parent),
                ],
            ];
        }

        return $rows;
    }

    // =================================================================
    // Datastores
    // =================================================================

    /**
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function datastores(): array
    {
        $tree = $this->loadTree();

        $objects = $this->client->retrieve([
            'Datastore' => [
                'name',
                'parent',
                'host',
                'vm',
                'info',
                'summary.type',
                'summary.accessible',
                'summary.maintenanceMode',
                'summary.capacity',
                'summary.freeSpace',
                'summary.uncommitted',
                'summary.url',
            ],
        ]);

        $rows = [];

        foreach ($objects as $moref => $props) {
            $info = is_array($props['info'] ?? null) ? $props['info'] : [];
            $nas  = is_array($info['nas'] ?? null) ? $info['nas'] : [];
            $vmfs = is_array($info['vmfs'] ?? null) ? $info['vmfs'] : [];

            [$dcMoref] = $tree->datacenterOf(self::str($props['parent'] ?? null));

            $rows[] = [
                'moref'  => (string) $moref,
                'raw'    => $props,
                'fields' => [
                    'name'             => self::str($props['name'] ?? null),
                    'type'             => self::str($props['summary.type'] ?? null),
                    'is_accessible'    => self::bool($props['summary.accessible'] ?? null),
                    'maintenance_mode' => self::str($props['summary.maintenanceMode'] ?? null),

                    'capacity_bytes'    => self::int($props['summary.capacity'] ?? null),
                    'free_bytes'        => self::int($props['summary.freeSpace'] ?? null),
                    'uncommitted_bytes' => self::int($props['summary.uncommitted'] ?? null),

                    'url'             => self::str($props['summary.url'] ?? null),
                    'vmfs_version'    => self::str($vmfs['version'] ?? null),
                    'nfs_remote_host' => self::str($nas['remoteHost'] ?? null),
                    'nfs_remote_path' => self::str($nas['remotePath'] ?? null),

                    'host_count'       => self::count($props['host'] ?? null),
                    'vm_count'         => self::count($props['vm'] ?? null),
                    'datacenter_moref' => $dcMoref,
                ],
            ];
        }

        return $rows;
    }

    // =================================================================
    // Host devices
    // =================================================================

    /**
     * Network cards, VMkernel adapters, switches, port groups and storage
     * adapters, for every host.
     *
     * A PASS OF ITS OWN, and that is measured rather than tidy-minded: these
     * fields are not added to the host read because the response for five
     * hosts grows from 1.1 KB to 291 KB, roughly 255 times. Most of that is
     * the iSCSI adapter, which carries supportedAdvancedOptions and
     * advancedOptions — dozens of blocks per adapter, none of which is kept.
     *
     * These devices have no moref: they exist only as entries in a host's
     * configuration. The identity is assembled as host:kind:device.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function hostDevices(): array
    {
        $objects = $this->client->retrieve([
            'HostSystem' => [
                'config.network.pnic',
                'config.network.vnic',
                'config.network.vswitch',
                'config.network.portgroup',
                'config.storageDevice.hostBusAdapter',
            ],
        ]);

        $rows = [];

        foreach ($objects as $hostMoref => $props) {
            $host = (string) $hostMoref;

            foreach ([
                ['pnic',      'config.network.pnic',                 self::pnic(...)],
                ['vmk',       'config.network.vnic',                 self::vmk(...)],
                ['vswitch',   'config.network.vswitch',              self::vswitch(...)],
                ['portgroup', 'config.network.portgroup',            self::portgroup(...)],
                ['hba',       'config.storageDevice.hostBusAdapter', self::hba(...)],
            ] as [$kind, $path, $parser]) {
                foreach (self::listOf($props[$path] ?? null) as $entry) {
                    $fields = $parser($entry);

                    if ($fields === null) {
                        continue;
                    }

                    $fields['host_moref'] = $host;
                    $fields['kind']       = $kind;

                    $rows[] = [
                        'moref'  => $host . ':' . $kind . ':' . $fields['device'],
                        'raw'    => $entry,
                        'fields' => $fields,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * A physical network card.
     *
     * linkSpeed is missing while the link is down, which leaves speed and
     * duplex empty — an accurate description of the state rather than a gap.
     *
     * @return array<string, mixed>|null
     */
    private static function pnic(array $e): ?array
    {
        $device = self::str($e['device'] ?? null);

        if ($device === null) {
            return null;
        }

        $link = is_array($e['linkSpeed'] ?? null) ? $e['linkSpeed'] : [];

        return [
            'device'           => $device,
            'mac'              => self::str($e['mac'] ?? null),
            'driver'           => self::str($e['driver'] ?? null),
            'driver_version'   => self::str($e['driverVersion'] ?? null),
            'firmware_version' => self::str($e['firmwareVersion'] ?? null),
            'speed_mb'         => self::int($link['speedMb'] ?? null),
            'duplex'           => self::bool($link['duplex'] ?? null),
            'pci'              => self::str($e['pci'] ?? null),
        ];
    }

    /**
     * A VMkernel adapter. This is where a host's own IP addresses live.
     *
     * @return array<string, mixed>|null
     */
    private static function vmk(array $e): ?array
    {
        $device = self::str($e['device'] ?? null);

        if ($device === null) {
            return null;
        }

        $spec = is_array($e['spec'] ?? null) ? $e['spec'] : [];
        $ip   = is_array($spec['ip'] ?? null) ? $spec['ip'] : [];

        $route   = is_array($spec['ipRouteSpec'] ?? null) ? $spec['ipRouteSpec'] : [];
        $config  = is_array($route['ipRouteConfig'] ?? null) ? $route['ipRouteConfig'] : [];

        return [
            'device'      => $device,
            'port_group'  => self::str($e['portgroup'] ?? null),
            'ip_address'  => self::str($ip['ipAddress'] ?? null),
            'subnet_mask' => self::str($ip['subnetMask'] ?? null),
            'dhcp'        => self::bool($ip['dhcp'] ?? null),
            'gateway'     => self::str($config['defaultGateway'] ?? null),
            'mac'         => self::str($spec['mac'] ?? null),
            'mtu'         => self::int($spec['mtu'] ?? null),
        ];
    }

    /**
     * A standard virtual switch.
     *
     * @return array<string, mixed>|null
     */
    private static function vswitch(array $e): ?array
    {
        $name = self::str($e['name'] ?? null);

        if ($name === null) {
            return null;
        }

        return [
            'device'              => $name,
            'num_ports'           => self::int($e['numPorts'] ?? null),
            'num_ports_available' => self::int($e['numPortsAvailable'] ?? null),
            'mtu'                 => self::int($e['mtu'] ?? null),
        ];
    }

    /**
     * A standard port group. Its name and VLAN sit inside the spec block.
     *
     * @return array<string, mixed>|null
     */
    private static function portgroup(array $e): ?array
    {
        $spec = is_array($e['spec'] ?? null) ? $e['spec'] : [];
        $name = self::str($spec['name'] ?? null);

        if ($name === null) {
            return null;
        }

        // VLAN 0 is a real value meaning "no VLAN", so it must not be lost
        // the way an empty string would be.
        $vlan = $spec['vlanId'] ?? null;

        return [
            'device'       => $name,
            'vlan_id'      => is_numeric($vlan) ? (int) $vlan : null,
            'vswitch_name' => self::str($spec['vswitchName'] ?? null),
        ];
    }

    /**
     * A storage adapter, all four sub-kinds.
     *
     * @return array<string, mixed>|null
     */
    private static function hba(array $e): ?array
    {
        $device = self::str($e['device'] ?? null);

        if ($device === null) {
            return null;
        }

        $type = match (self::str($e['_type'] ?? null)) {
            'HostFibreChannelHba'     => 'fibreChannel',
            'HostInternetScsiHba'     => 'iscsi',
            'HostSerialAttachedHba'   => 'serialAttached',
            'HostParallelScsiHba'     => 'parallelScsi',
            'HostFibreChannelOverEthernetHba' => 'fcoe',
            default                   => null,
        };

        return [
            'device'           => $device,
            'hba_type'         => $type,
            'model'            => self::str($e['model'] ?? null),
            'driver'           => self::str($e['driver'] ?? null),
            'pci'              => self::str($e['pci'] ?? null),
            'status'           => self::str($e['status'] ?? null),
            'storage_protocol' => self::str($e['storageProtocol'] ?? null),
            'wwpn'             => self::wwn($e['portWorldWideName'] ?? null),
            'wwnn'             => self::wwn($e['nodeWorldWideName'] ?? null),
            'iscsi_name'       => self::str($e['iScsiName'] ?? null),
            'speed_mb'         => self::int($e['speed'] ?? null),
        ];
    }

    /**
     * A WWN in the form everyone actually uses: 21:00:f4:e9:d4:55:b9:00.
     *
     * Fibre Channel reports a decimal number (2378169888378566912) while SAS
     * already reports a hex string. Both end up the same here, so a serial
     * from one adapter can be compared with one from another.
     */
    public static function wwn(mixed $value): ?string
    {
        $text = self::str($value);

        if ($text === null) {
            return null;
        }

        if (ctype_digit($text)) {
            // 16 hex digits, zero padded; the decimal form loses leading zeros.
            $text = str_pad(dechex((int) $text), 16, '0', STR_PAD_LEFT);
        }

        $text = strtolower(str_replace([':', '-', ' '], '', $text));

        if (!ctype_xdigit($text) || strlen($text) !== 16) {
            // Anything unexpected is passed through rather than mangled.
            return mb_substr(self::str($value) ?? '', 0, 32);
        }

        return implode(':', str_split($text, 2));
    }

    /**
     * Whatever vCenter returned, as a list.
     *
     * A single entry arrives as a map rather than a list of one, and a host
     * with one network card must not produce a different shape from a host
     * with two.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (!array_is_list($value)) {
            $value = [$value];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    // =================================================================
    // Licenses
    // =================================================================

    /**
     * Licenses come from the LicenseManager, which is a single object, so
     * there is no container view here.
     *
     * The key is stored masked, the way vCenter itself displays it. A full
     * licence key is a purchasable asset and has no business sitting in an
     * inventory database; the last block is enough to tell two apart.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function licenses(): array
    {
        $manager = $this->client->licenseManager;

        if ($manager === null) {
            // A standalone ESXi host has no LicenseManager in its service
            // content. Nothing to collect is not a failure.
            return [];
        }

        $props    = $this->client->retrieveOn('LicenseManager', $manager, ['licenses']);
        $licenses = $props['licenses'] ?? [];

        if (!is_array($licenses)) {
            return [];
        }

        // One licence comes back as a map rather than a list.
        if (!array_is_list($licenses)) {
            $licenses = [$licenses];
        }

        $rows = [];

        foreach ($licenses as $license) {
            if (!is_array($license)) {
                continue;
            }

            $key        = self::str($license['licenseKey'] ?? null) ?? '';
            $properties = self::keyedProperties($license['properties'] ?? null);

            $rows[] = [
                'moref'  => self::maskKey($key),
                'raw'    => $license,
                'fields' => [
                    'name'            => self::str($license['name'] ?? null) ?? 'unknown',
                    'edition_key'     => self::str($license['editionKey'] ?? null),
                    'product_name'    => self::str($properties['ProductName'] ?? null),
                    'product_version' => self::str($properties['ProductVersion'] ?? null),
                    'total_units'     => self::int($license['total'] ?? null),
                    'used_units'      => self::int($license['used'] ?? null),
                    'cost_unit'       => self::str($license['costUnit'] ?? null),
                    'expires_at'      => self::datetime($properties['expirationDate'] ?? null),
                ],
            ];
        }

        return $rows;
    }

    // =================================================================
    // Conversions
    // =================================================================

    private static function str(mixed $value): ?string
    {
        if (is_array($value) || $value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function int(mixed $value): ?int
    {
        $text = self::str($value);

        return $text === null || !is_numeric($text) ? null : (int) $text;
    }

    private static function bool(mixed $value): ?int
    {
        $text = self::str($value);

        if ($text === null) {
            return null;
        }

        return in_array(strtolower($text), ['true', '1'], true) ? 1 : 0;
    }

    private static function count(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? count($value) : 1;
    }

    /**
     * vim25 spelling to the stored one: poweredOn -> POWERED_ON.
     */
    private static function constant(mixed $value): ?string
    {
        $text = self::str($value);

        if ($text === null) {
            return null;
        }

        return strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $text) ?? $text);
    }

    /**
     * Disk count, total disk capacity and NIC count, from the device list.
     *
     * A device is a NIC when it has a MAC address. Matching on the class
     * name instead would mean listing VirtualVmxnet3, VirtualE1000,
     * VirtualE1000e, VirtualPCNet32 and whatever VMware adds next.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private static function devices(mixed $devices): array
    {
        if (!is_array($devices)) {
            return [null, null, null];
        }

        if (!array_is_list($devices)) {
            $devices = [$devices];
        }

        $disks = 0;
        $nics  = 0;
        $bytes = 0;

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            if (($device['_type'] ?? '') === 'VirtualDisk') {
                $disks++;

                $capacity = self::int($device['capacityInBytes'] ?? null);

                if ($capacity === null) {
                    // Older hardware versions report only kilobytes.
                    $kb       = self::int($device['capacityInKB'] ?? null);
                    $capacity = $kb === null ? null : $kb * 1024;
                }

                $bytes += $capacity ?? 0;
            }

            if (isset($device['macAddress']) || isset($device['addressType'])) {
                $nics++;
            }
        }

        return [$disks, $bytes, $nics];
    }

    private static function serialNumber(array $props): ?string
    {
        return self::str($props['hardware.systemInfo.serialNumber'] ?? null)
            ?? self::identifyingInfo($props, ['SerialNumberTag', 'ServiceTag', 'EnclosureSerialNumberTag']);
    }

    /**
     * Pull a value out of summary.hardware.otherIdentifyingInfo, which is a
     * list of {identifierValue, identifierType: {key}} entries.
     *
     * @param list<string> $keys acceptable identifier keys, best first
     */
    private static function identifyingInfo(array $props, array $keys): ?string
    {
        $info = $props['summary.hardware.otherIdentifyingInfo'] ?? null;

        if (!is_array($info)) {
            return null;
        }

        if (!array_is_list($info)) {
            $info = [$info];
        }

        foreach ($keys as $wanted) {
            foreach ($info as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $type = $entry['identifierType'] ?? null;
                $key  = is_array($type) ? self::str($type['key'] ?? null) : null;

                if ($key === $wanted) {
                    $value = self::str($entry['identifierValue'] ?? null);

                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * A list of {key, value} pairs as a map.
     *
     * @return array<string, mixed>
     */
    private static function keyedProperties(mixed $properties): array
    {
        if (!is_array($properties)) {
            return [];
        }

        if (!array_is_list($properties)) {
            $properties = [$properties];
        }

        $out = [];

        foreach ($properties as $property) {
            if (!is_array($property)) {
                continue;
            }

            $key = self::str($property['key'] ?? null);

            if ($key !== null) {
                $out[$key] = $property['value'] ?? null;
            }
        }

        return $out;
    }

    /**
     * An ISO 8601 timestamp as "Y-m-d H:i:s" in UTC, or null.
     */
    private static function datetime(mixed $value): ?string
    {
        $text = is_array($value) ? self::str($value['value'] ?? null) : self::str($value);

        if ($text === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($text))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * XXXXX-XXXXX-XXXXX-XXXXX-ABCDE — everything but the last block hidden.
     */
    public static function maskKey(string $key): string
    {
        if ($key === '') {
            return 'unknown';
        }

        $blocks = explode('-', $key);

        if (count($blocks) < 2) {
            // Evaluation licences report a word rather than a key.
            return mb_substr($key, 0, 64);
        }

        $last = array_pop($blocks);

        return implode('-', array_fill(0, count($blocks), 'XXXXX')) . '-' . $last;
    }
}
