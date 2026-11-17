<?php
// src/Source/Hyperv/OfflineMapper.php
//
// A set of uploaded collector files turned into rows, ready for
// InventoryRepository::sync().
//
// WHY A SET AND NOT ONE FILE
//
// A Hyper-V host reports only the machines registered on it, so a cluster is
// only complete once every node has been collected. sync() reconciles a whole
// source — an object it does not see is an object that no longer exists — so
// a run holding one node's file would journal every machine on the other
// nodes as gone. The set is therefore the unit: all the files of one source
// are mapped together and imported as one run.
//
// Every node also reports the whole cluster section, and on CL01 the two
// files agreed field for field. The cluster is taken from the first file and
// the others are compared against it, so files collected too far apart to be
// one snapshot are noticed rather than silently merged.
//
// Nothing here writes, queries or connects. It takes payloads and returns
// arrays, which is what makes it testable against a captured file.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

final class OfflineMapper
{
    /** @var list<OfflinePayload> */
    private readonly array $payloads;

    /** @var list<string> */
    private array $warnings = [];

    /** @param list<OfflinePayload> $payloads */
    public function __construct(array $payloads)
    {
        if ($payloads === []) {
            throw new HypervException('There are no files to import.');
        }

        $this->payloads = array_values($payloads);
        $this->check();
    }

    // -----------------------------------------------------------------
    // The set as a whole
    // -----------------------------------------------------------------

    /**
     * Whether the files can be imported together at all.
     *
     * These are refusals, not warnings: importing anyway would mix two
     * environments into one source.
     */
    private function check(): void
    {
        $kinds = [];
        $hosts = [];

        foreach ($this->payloads as $payload) {
            $kinds[] = $payload->kind();
            $host    = strtolower($payload->collectorHost());

            if (isset($hosts[$host])) {
                throw HypervException::duplicateNode($payload->collectorHost());
            }

            $hosts[$host] = true;
        }

        if (count(array_unique($kinds)) > 1) {
            throw HypervException::mixedKinds();
        }

        if (!$this->isCluster()) {
            if (count($this->payloads) > 1) {
                throw HypervException::severalStandaloneHosts();
            }

            return;
        }

        $first = $this->payloads[0]->clusterGuid();

        foreach ($this->payloads as $payload) {
            $guid = $payload->clusterGuid();

            if ($guid !== null && $first !== null && $guid !== $first) {
                throw HypervException::differentClusters(
                    (string) $this->payloads[0]->clusterName(),
                    (string) $payload->clusterName()
                );
            }
        }

        $this->checkClusterAgreement();
    }

    /**
     * The cluster as two nodes see it. A difference is not fatal — it means
     * the files were collected at different times — but it has to be said,
     * because only one of them is going to be stored.
     */
    private function checkClusterAgreement(): void
    {
        $reference = $this->payloads[0];
        $fields    = ['name', 'quorum_type', 'quorum_resource', 'functional_level', 'node_count'];

        foreach (array_slice($this->payloads, 1) as $payload) {
            foreach ($fields as $field) {
                $a = OfflinePayload::text($reference->cluster()[$field] ?? null);
                $b = OfflinePayload::text($payload->cluster()[$field] ?? null);

                if ($a !== $b) {
                    $this->warnings[] = sprintf(
                        '%s and %s disagree about the cluster %s (%s against %s); the value from %s is stored.',
                        $reference->collectorHost(),
                        $payload->collectorHost(),
                        str_replace('_', ' ', $field),
                        $a ?? '—',
                        $b ?? '—',
                        $reference->collectorHost()
                    );
                }
            }

            if (count($reference->sharedVolumes()) !== count($payload->sharedVolumes())) {
                $this->warnings[] = sprintf(
                    '%s reports %d shared volume(s) and %s reports %d. The files are probably not one snapshot.',
                    $reference->collectorHost(),
                    count($reference->sharedVolumes()),
                    $payload->collectorHost(),
                    count($payload->sharedVolumes())
                );
            }
        }
    }

    public function isCluster(): bool
    {
        return $this->payloads[0]->isCluster();
    }

    /**
     * Cluster nodes no file was uploaded for.
     *
     * This is what decides whether an import is allowed to proceed: the set
     * is the run's whole view of the source, so a missing node means its
     * machines would be recorded as gone.
     *
     * @return list<string>
     */
    public function missingNodes(): array
    {
        if (!$this->isCluster()) {
            return [];
        }

        $collected = [];

        foreach ($this->payloads as $payload) {
            $collected[strtolower($payload->collectorHost())] = true;
        }

        $missing = [];

        foreach ($this->payloads[0]->clusterNodeNames() as $name) {
            if (!isset($collected[strtolower($name)])) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /** How far apart the oldest and newest file were collected. */
    public function timeSpreadSeconds(): int
    {
        $times = [];

        foreach ($this->payloads as $payload) {
            $at = $payload->generatedAt();

            if ($at !== null) {
                $times[] = strtotime($at . ' UTC');
            }
        }

        return $times === [] ? 0 : max($times) - min($times);
    }

    /**
     * Everything worth telling the person, from the files and from comparing
     * them against each other.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $out = $this->warnings;

        foreach ($this->payloads as $payload) {
            if (!$payload->runAsAdmin()) {
                $out[] = $payload->collectorHost()
                    . ' was collected without administrator rights, so disk and cluster data are incomplete.';
            }

            if (!$payload->hasDiskDetails()) {
                $out[] = $payload->collectorHost()
                    . ' was collected with -SkipDiskDetails, so virtual disk sizes and types are missing.';
            }

            foreach ($payload->warnings() as $warning) {
                $out[] = $payload->collectorHost() . ': ' . $warning;
            }

            foreach ($payload->collection() as $step) {
                if ($step['status'] === 'error') {
                    $out[] = sprintf(
                        '%s: %s failed — %s',
                        $payload->collectorHost(),
                        $step['step'],
                        $step['message']
                    );
                }
            }
        }

        $spread = $this->timeSpreadSeconds();

        if ($spread > 86400) {
            $out[] = sprintf(
                'The files were collected %d hours apart, so they are not one snapshot.',
                intdiv($spread, 3600)
            );
        }

        return array_values(array_unique($out));
    }

    /** @return list<OfflinePayload> */
    public function payloads(): array
    {
        return $this->payloads;
    }

    // -----------------------------------------------------------------
    // Rows
    // -----------------------------------------------------------------

    /**
     * Rows per object type, keyed by the ObjectType key.
     *
     * The shape is what both writers take: 'fields' is what sync() compares
     * and stores, and 'raw' is what the collector reported for that object,
     * which RawObjectRepository keeps verbatim. Keeping the payload is the
     * point of the raw layer: a field that turns out to matter in six months
     * can be extracted from runs already collected.
     *
     * @return array<string, list<array{moref: string, fields: array<string, scalar|null>}>>
     */
    public function rows(): array
    {
        $rows = [
            'hv_cluster'         => [],
            'hv_cluster_network' => [],
            'hv_csv'             => [],
            'hv_node'            => [],
            'hv_host_device'     => [],
            'hv_vswitch'         => [],
            'hv_vm'              => [],
            'hv_vm_disk'         => [],
            'hv_vm_nic'          => [],
            'hv_vm_checkpoint'   => [],
        ];

        $clusterMoref = null;

        if ($this->isCluster()) {
            $clusterMoref = $this->payloads[0]->clusterGuid();

            $rows['hv_cluster']         = $this->clusterRow($clusterMoref);
            $rows['hv_cluster_network'] = $this->clusterNetworkRows($clusterMoref);
            $rows['hv_csv']             = $this->csvRows($clusterMoref);
        }

        foreach ($this->payloads as $payload) {
            $nodeMoref = $payload->nodeMoref();

            $rows['hv_node'][]      = $this->nodeRow($payload, $nodeMoref, $clusterMoref);
            $rows['hv_host_device'] = array_merge($rows['hv_host_device'], $this->hostDeviceRows($payload, $nodeMoref));
            $rows['hv_vswitch']     = array_merge($rows['hv_vswitch'], $this->vswitchRows($payload, $nodeMoref));

            foreach ($payload->vms() as $vm) {
                $vmMoref = OfflinePayload::guid($vm['vm_id'] ?? null);

                if ($vmMoref === null) {
                    $this->warnings[] = $payload->collectorHost()
                        . ': a machine without an id was skipped (' . ($vm['name'] ?? '?') . ').';
                    continue;
                }

                $rows['hv_vm'][]           = $this->vmRow($vm, $vmMoref, $nodeMoref, $payload);
                $rows['hv_vm_disk']        = array_merge($rows['hv_vm_disk'], $this->diskRows($vm, $vmMoref));
                $rows['hv_vm_nic']         = array_merge($rows['hv_vm_nic'], $this->nicRows($vm, $vmMoref, $nodeMoref));
                $rows['hv_vm_checkpoint']  = array_merge($rows['hv_vm_checkpoint'], $this->checkpointRows($vm, $vmMoref));
            }
        }

        return $rows;
    }

    /** @return list<array{moref: string, fields: array<string, scalar|null>}> */
    private function clusterRow(?string $moref): array
    {
        if ($moref === null) {
            return [];
        }

        $cluster = $this->payloads[0]->cluster() ?? [];

        return [[
            'moref'  => $moref,
            'raw'    => $cluster,
            'fields' => [
                'name'                => OfflinePayload::text($cluster['name'] ?? null),
                'domain'              => OfflinePayload::text($cluster['domain'] ?? null),
                'functional_level'    => OfflinePayload::text($cluster['functional_level'] ?? null),
                'upgrade_version'     => OfflinePayload::text($cluster['upgrade_version'] ?? null),
                'quorum_type'         => OfflinePayload::text($cluster['quorum_type'] ?? null),
                'quorum_resource'     => OfflinePayload::text($cluster['quorum_resource'] ?? null),
                'shared_volumes_root' => OfflinePayload::text($cluster['shared_volumes_root'] ?? null),
                'node_count'          => OfflinePayload::int($cluster['node_count'] ?? null),
            ],
        ]];
    }

    /** @return list<array{moref: string, fields: array<string, scalar|null>}> */
    private function clusterNetworkRows(?string $clusterMoref): array
    {
        $out = [];

        foreach ($this->payloads[0]->clusterNetworks() as $network) {
            $guid = OfflinePayload::guid($network['id'] ?? null);

            if ($guid === null) {
                continue;
            }

            $out[] = [
                'moref'  => 'net:' . $guid,
                'raw'    => $network,
                'fields' => [
                    'cluster_moref' => $clusterMoref,
                    'name'          => OfflinePayload::text($network['name'] ?? null),
                    'network_role'  => OfflinePayload::text($network['role'] ?? null),
                    'network_state' => OfflinePayload::text($network['state'] ?? null),
                    'address'       => OfflinePayload::text($network['address'] ?? null),
                    'address_mask'  => OfflinePayload::text($network['address_mask'] ?? null),
                    'ipv6_address'  => OfflinePayload::text($network['ipv6_address'] ?? null),
                    'metric'        => OfflinePayload::int($network['metric'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /** @return list<array{moref: string, fields: array<string, scalar|null>}> */
    private function csvRows(?string $clusterMoref): array
    {
        $out = [];

        foreach ($this->payloads[0]->sharedVolumes() as $csv) {
            $guid = OfflinePayload::guid($csv['id'] ?? null);

            if ($guid === null) {
                continue;
            }

            $out[] = [
                'moref'  => 'csv:' . $guid,
                'raw'    => $csv,
                'fields' => [
                    'cluster_moref'     => $clusterMoref,
                    'name'              => OfflinePayload::text($csv['name'] ?? null),
                    'csv_state'         => OfflinePayload::text($csv['state'] ?? null),
                    'owner_node'        => OfflinePayload::text($csv['owner_node'] ?? null),
                    'friendly_path'     => OfflinePayload::text($csv['friendly_path'] ?? null),
                    'volume_name'       => OfflinePayload::text($csv['volume_name'] ?? null),
                    'file_system'       => OfflinePayload::text($csv['file_system'] ?? null),
                    'fault_state'       => OfflinePayload::text($csv['fault_state'] ?? null),
                    'redirected_access' => OfflinePayload::flag($csv['redirected_access'] ?? null),
                    'maintenance_mode'  => OfflinePayload::flag($csv['maintenance_mode'] ?? null),
                    'size_bytes'        => OfflinePayload::int($csv['size_bytes'] ?? null),
                    'used_bytes'        => OfflinePayload::int($csv['used_bytes'] ?? null),
                    'free_bytes'        => OfflinePayload::int($csv['free_bytes'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /** @return array{moref: string, fields: array<string, scalar|null>} */
    private function nodeRow(OfflinePayload $payload, string $moref, ?string $clusterMoref): array
    {
        $node = $payload->node();

        // The cluster-side facts about this node live in the cluster's node
        // list, not in the node section, because they come from a different
        // cmdlet. They stay null on a standalone host.
        $clusterNode = [];

        foreach ($payload->clusterNodes() as $candidate) {
            if (strcasecmp((string) OfflinePayload::text($candidate['name'] ?? null), $payload->hostname()) === 0) {
                $clusterNode = $candidate;
                break;
            }
        }

        return [
            'moref'  => $moref,
            'raw'    => $node,
            'fields' => [
                'hostname'                  => OfflinePayload::text($node['hostname'] ?? null),
                'fqdn'                      => OfflinePayload::text($node['fqdn'] ?? null),
                'domain'                    => OfflinePayload::text($node['domain'] ?? null),
                'part_of_domain'            => OfflinePayload::flag($node['part_of_domain'] ?? null),

                'cluster_moref'             => $clusterMoref,
                'cluster_node_id'           => OfflinePayload::text($clusterNode['id'] ?? null),
                'node_state'                => OfflinePayload::text($clusterNode['state'] ?? null),
                'drain_status'              => OfflinePayload::text($clusterNode['drain_status'] ?? null),

                'smbios_uuid'               => OfflinePayload::guid($node['smbios_uuid'] ?? null),
                'vendor'                    => OfflinePayload::text($node['vendor'] ?? null),
                'model'                     => OfflinePayload::text($node['model'] ?? null),
                'serial_number'             => OfflinePayload::text($node['serial_number'] ?? null),
                'bios_version'              => OfflinePayload::text($node['bios_version'] ?? null),
                'bios_release_date'         => OfflinePayload::utc($node['bios_release_date'] ?? null),

                'cpu_model'                 => OfflinePayload::text($node['cpu_model'] ?? null),
                'cpu_sockets'               => OfflinePayload::int($node['cpu_sockets'] ?? null),
                'cpu_cores'                 => OfflinePayload::int($node['cpu_cores'] ?? null),
                'cpu_logical'               => OfflinePayload::int($node['cpu_logical'] ?? null),
                'cpu_max_clock_mhz'         => OfflinePayload::int($node['cpu_max_clock_mhz'] ?? null),
                'memory_bytes'              => OfflinePayload::int($node['memory_bytes'] ?? null),

                'os_caption'                => OfflinePayload::text($node['os_caption'] ?? null),
                'os_version'                => OfflinePayload::text($node['os_version'] ?? null),
                'os_build'                  => OfflinePayload::text($node['os_build'] ?? null),
                'os_architecture'           => OfflinePayload::text($node['os_architecture'] ?? null),
                'os_install_date'           => OfflinePayload::utc($node['os_install_date'] ?? null),
                'last_boot'                 => OfflinePayload::utc($node['last_boot'] ?? null),
                'uptime_seconds'            => OfflinePayload::int($node['uptime_seconds'] ?? null),

                'hyperv_module_version'     => OfflinePayload::text($payload->envelope()['hyperv_module_version'] ?? null),
                'powershell_version'        => OfflinePayload::text($payload->envelope()['powershell_version'] ?? null),
                'hyperv_logical_processors' => OfflinePayload::int($node['hyperv_logical_processors'] ?? null),
                'hyperv_memory_capacity'    => OfflinePayload::int($node['hyperv_memory_capacity'] ?? null),
                'virtual_machine_path'      => OfflinePayload::text($node['virtual_machine_path'] ?? null),
                'virtual_hard_disk_path'    => OfflinePayload::text($node['virtual_hard_disk_path'] ?? null),
                'numa_spanning_enabled'     => OfflinePayload::flag($node['numa_spanning_enabled'] ?? null),
                'enhanced_session_mode'     => OfflinePayload::flag($node['enhanced_session_mode'] ?? null),
                'iov_support'               => OfflinePayload::flag($node['iov_support'] ?? null),
                'mac_address_minimum'       => OfflinePayload::text($node['mac_address_minimum'] ?? null),
                'mac_address_maximum'       => OfflinePayload::text($node['mac_address_maximum'] ?? null),

                'migration_enabled'         => OfflinePayload::flag($node['migration_enabled'] ?? null),
                'migration_auth_type'       => OfflinePayload::text($node['migration_auth_type'] ?? null),
                'migration_performance'     => OfflinePayload::text($node['migration_performance'] ?? null),
                'max_vm_migrations'         => OfflinePayload::int($node['max_vm_migrations'] ?? null),
                'max_storage_migrations'    => OfflinePayload::int($node['max_storage_migrations'] ?? null),

                // Null rather than 0 when Replica was never configured:
                // Get-VMReplicationServer answers "object was not found" on
                // Server 2025 in that case, and absent is not the same as off.
                'replica_enabled'           => OfflinePayload::flag($node['replica_enabled'] ?? null),
                'replica_auth_type'         => OfflinePayload::text($node['replica_auth_type'] ?? null),
                'replica_storage_location'  => OfflinePayload::text($node['replica_storage_location'] ?? null),

                'vm_count'                  => count($payload->vms()),
                'source_collected_at'       => $payload->generatedAt(),
            ],
        ];
    }

    /** @return list<array{moref: string, fields: array<string, scalar|null>}> */
    private function hostDeviceRows(OfflinePayload $payload, string $nodeMoref): array
    {
        $out = [];

        // Physical adapters, and the management OS side of the virtual ones.
        // The collector labels which is which, so nothing is guessed from a
        // name that happens to start with "vEthernet".
        foreach ($payload->hostDevices() as $device) {
            $kind = OfflinePayload::text($device['kind'] ?? null) ?? 'pnic';
            $mac  = OfflinePayload::text($device['mac_address'] ?? null);
            $name = OfflinePayload::text($device['name'] ?? null);
            $key  = strtolower((string) ($mac ?? $name));

            if ($key === '') {
                continue;
            }

            $out[] = [
                'moref'  => 'dev:' . $nodeMoref . ':' . $kind . ':' . $key,
                'raw'    => $device,
                'fields' => [
                    'node_moref'            => $nodeMoref,
                    'kind'                  => $kind,
                    'name'                  => $name,
                    'adapter_id'            => null,
                    'interface_description' => OfflinePayload::text($device['interface_description'] ?? null),
                    'interface_index'       => OfflinePayload::int($device['interface_index'] ?? null),
                    'mac_address'           => $mac,
                    'device_status'         => OfflinePayload::text($device['status'] ?? null),
                    'link_speed'            => OfflinePayload::text($device['link_speed'] ?? null),
                    'speed_bps'             => OfflinePayload::int($device['speed_bps'] ?? null),
                    'media_type'            => OfflinePayload::text($device['media_type'] ?? null),
                    'full_duplex'           => OfflinePayload::flag($device['full_duplex'] ?? null),
                    'is_virtual'            => OfflinePayload::flag($device['is_virtual'] ?? null),
                    'driver_name'           => OfflinePayload::text($device['driver_name'] ?? null),
                    'driver_version'        => OfflinePayload::text($device['driver_version'] ?? null),
                    'switch_name'           => null,
                    'switch_moref'          => null,
                    'vlan_mode'             => null,
                    'vlan_access_id'        => null,
                    'vlan_native_id'        => null,
                    'vlan_allowed_list'     => null,
                    'ip_addresses'          => OfflinePayload::addressList($device['ip_addresses'] ?? null),
                    'teaming_mode'          => null,
                    'load_balancing'        => null,
                    'team_members'          => null,
                ],
            ];
        }

        // The Hyper-V side of a host virtual adapter: this is where the VLAN
        // and the switch are, which the OS side does not report.
        foreach ($payload->hostVnics() as $vnic) {
            $adapterId = OfflinePayload::guid($vnic['id'] ?? null);
            $name      = OfflinePayload::text($vnic['name'] ?? null);
            $key       = $adapterId ?? strtolower((string) $name);

            if ($key === '') {
                continue;
            }

            $switchGuid = OfflinePayload::guid($vnic['switch_id'] ?? null);

            $out[] = [
                'moref'  => 'dev:' . $nodeMoref . ':host_vnic:' . $key,
                'raw'    => $vnic,
                'fields' => [
                    'node_moref'            => $nodeMoref,
                    'kind'                  => 'host_vnic',
                    'name'                  => $name,
                    'adapter_id'            => $adapterId,
                    'interface_description' => null,
                    'interface_index'       => null,
                    'mac_address'           => OfflinePayload::text($vnic['mac_address'] ?? null),
                    'device_status'         => OfflinePayload::text($vnic['status'] ?? null),
                    'link_speed'            => null,
                    'speed_bps'             => null,
                    'media_type'            => null,
                    'full_duplex'           => null,
                    'is_virtual'            => 1,
                    'driver_name'           => null,
                    'driver_version'        => null,
                    'switch_name'           => OfflinePayload::text($vnic['switch_name'] ?? null),
                    'switch_moref'          => $switchGuid === null ? null : 'vswitch:' . $nodeMoref . ':' . $switchGuid,
                    'vlan_mode'             => OfflinePayload::text($vnic['vlan_mode'] ?? null),
                    'vlan_access_id'        => OfflinePayload::int($vnic['vlan_access_id'] ?? null),
                    'vlan_native_id'        => OfflinePayload::int($vnic['vlan_native_id'] ?? null),
                    'vlan_allowed_list'     => OfflinePayload::joinList($vnic['vlan_allowed_list'] ?? null),
                    'ip_addresses'          => OfflinePayload::addressList($vnic['ip_addresses'] ?? null),
                    'teaming_mode'          => null,
                    'load_balancing'        => null,
                    'team_members'          => null,
                ],
            ];
        }

        // LBFO teams. Empty on hardware that uses switch embedded teaming,
        // which is recorded on the switch instead.
        foreach ($payload->nicTeams() as $team) {
            $name = OfflinePayload::text($team['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $out[] = [
                'moref'  => 'dev:' . $nodeMoref . ':team:' . strtolower($name),
                'raw'    => $team,
                'fields' => [
                    'node_moref'            => $nodeMoref,
                    'kind'                  => 'team',
                    'name'                  => $name,
                    'adapter_id'            => null,
                    'interface_description' => null,
                    'interface_index'       => null,
                    'mac_address'           => null,
                    'device_status'         => OfflinePayload::text($team['status'] ?? null),
                    'link_speed'            => null,
                    'speed_bps'             => null,
                    'media_type'            => null,
                    'full_duplex'           => null,
                    'is_virtual'            => 0,
                    'driver_name'           => null,
                    'driver_version'        => null,
                    'switch_name'           => null,
                    'switch_moref'          => null,
                    'vlan_mode'             => null,
                    'vlan_access_id'        => null,
                    'vlan_native_id'        => null,
                    'vlan_allowed_list'     => null,
                    'ip_addresses'          => null,
                    'teaming_mode'          => OfflinePayload::text($team['teaming_mode'] ?? null),
                    'load_balancing'        => OfflinePayload::text($team['load_balancing'] ?? null),
                    'team_members'          => OfflinePayload::joinList($team['members'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /** @return list<array{moref: string, fields: array<string, scalar|null>}> */
    private function vswitchRows(OfflinePayload $payload, string $nodeMoref): array
    {
        $out = [];

        foreach ($payload->virtualSwitches() as $switch) {
            $guid = OfflinePayload::guid($switch['id'] ?? null);

            if ($guid === null) {
                continue;
            }

            $out[] = [
                'moref'  => 'vswitch:' . $nodeMoref . ':' . $guid,
                'raw'    => $switch,
                'fields' => [
                    'node_moref'                 => $nodeMoref,
                    'name'                       => OfflinePayload::text($switch['name'] ?? null),
                    'switch_guid'                => $guid,
                    'switch_type'                => OfflinePayload::text($switch['switch_type'] ?? null),
                    'allow_management_os'        => OfflinePayload::flag($switch['allow_management_os'] ?? null),
                    'embedded_teaming'           => OfflinePayload::flag($switch['embedded_teaming'] ?? null),
                    'iov_enabled'                => OfflinePayload::flag($switch['iov_enabled'] ?? null),
                    'iov_support'                => OfflinePayload::flag($switch['iov_support'] ?? null),
                    'packet_direct_enabled'      => OfflinePayload::flag($switch['packet_direct_enabled'] ?? null),
                    'bandwidth_reservation_mode' => OfflinePayload::text($switch['bandwidth_reservation_mode'] ?? null),
                    'default_flow_min_absolute'  => OfflinePayload::int($switch['default_flow_min_bandwidth_absolute'] ?? null),
                    'default_flow_min_weight'    => OfflinePayload::int($switch['default_flow_min_bandwidth_weight'] ?? null),
                    'uplink_adapters'            => OfflinePayload::joinList(
                        $switch['net_adapter_interface_descriptions']
                            ?? $switch['net_adapter_interface_description']
                            ?? null
                    ),
                    'team_teaming_mode'          => OfflinePayload::text($switch['team_teaming_mode'] ?? null),
                    'team_load_balancing'        => OfflinePayload::text($switch['team_load_balancing'] ?? null),
                    'notes'                      => OfflinePayload::text($switch['notes'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vm
     *
     * @return array{moref: string, fields: array<string, scalar|null>}
     */
    private function vmRow(array $vm, string $moref, string $nodeMoref, OfflinePayload $payload): array
    {
        $disks       = $this->listOf($vm['disks'] ?? []);
        $nics        = $this->listOf($vm['network_adapters'] ?? []);
        $checkpoints = $this->listOf($vm['checkpoints'] ?? []);
        $firmware    = is_array($vm['firmware'] ?? null) ? $vm['firmware'] : [];
        $replication = is_array($vm['replication'] ?? null) ? $vm['replication'] : [];

        $capacity = 0;
        $onDisk   = 0;

        foreach ($disks as $disk) {
            $capacity += (int) (OfflinePayload::int($disk['size_bytes'] ?? null) ?? 0);
            $onDisk   += (int) (OfflinePayload::int($disk['file_size_bytes'] ?? null) ?? 0);
        }

        // Which integration services are switched on, as one string. The
        // whole list is in the raw payload; this is what the list page needs.
        $enabled = [];

        foreach ($this->listOf($vm['integration_services'] ?? []) as $service) {
            if ((bool) ($service['enabled'] ?? false)) {
                $name = OfflinePayload::text($service['name'] ?? null);

                if ($name !== null) {
                    $enabled[] = $name;
                }
            }
        }

        return [
            'moref'  => $moref,
            'raw'    => $vm,
            'fields' => [
                'name'                         => OfflinePayload::text($vm['name'] ?? null),
                'vm_state'                     => OfflinePayload::text($vm['state'] ?? null),
                'vm_status'                    => OfflinePayload::text($vm['status'] ?? null),
                'generation'                   => OfflinePayload::int($vm['generation'] ?? null),
                'config_version'               => OfflinePayload::text($vm['config_version'] ?? null),

                'owner_node_moref'             => $nodeMoref,
                'owner_node'                   => OfflinePayload::text($vm['owner_node'] ?? null) ?? $payload->hostname(),
                'is_clustered'                 => OfflinePayload::flag($vm['is_clustered'] ?? null),

                'processor_count'              => OfflinePayload::int($vm['processor_count'] ?? null),
                'cpu_usage_percent'            => OfflinePayload::int($vm['cpu_usage_percent'] ?? null),
                'cpu_reserve_percent'          => OfflinePayload::int($vm['cpu_reserve_percent'] ?? null),
                'cpu_limit_percent'            => OfflinePayload::int($vm['cpu_limit_percent'] ?? null),
                'cpu_relative_weight'          => OfflinePayload::int($vm['cpu_relative_weight'] ?? null),
                'cpu_compat_for_migration'     => OfflinePayload::flag($vm['cpu_compat_for_migration'] ?? null),
                'cpu_nested_virtualization'    => OfflinePayload::flag($vm['cpu_nested_virtualization'] ?? null),
                'cpu_threads_per_core'         => OfflinePayload::int($vm['cpu_threads_per_core'] ?? null),

                'memory_startup'               => OfflinePayload::int($vm['memory_startup'] ?? null),
                'memory_minimum'               => OfflinePayload::int($vm['memory_minimum'] ?? null),
                'memory_maximum'               => OfflinePayload::int($vm['memory_maximum'] ?? null),
                'dynamic_memory_enabled'       => OfflinePayload::flag($vm['dynamic_memory_enabled'] ?? null),
                'memory_buffer_percent'        => OfflinePayload::int($vm['memory_buffer_percent'] ?? null),
                'memory_priority'              => OfflinePayload::int($vm['memory_priority'] ?? null),
                'memory_assigned'              => OfflinePayload::int($vm['memory_assigned'] ?? null),
                'memory_demand'                => OfflinePayload::int($vm['memory_demand'] ?? null),

                'uptime_seconds'               => OfflinePayload::int($vm['uptime_seconds'] ?? null),

                'integration_services_version' => OfflinePayload::servicesVersion($vm['integration_services_version'] ?? null),
                'integration_services_enabled' => $enabled === [] ? null : implode(',', $enabled),
                'guest_os_name'                => OfflinePayload::text($vm['guest_os_name'] ?? null),
                'guest_os_version'             => OfflinePayload::text($vm['guest_os_version'] ?? null),

                // Named hostname, not FQDN: a guest that is not domain
                // joined reports a bare name such as WIN-QTELRNKGPKE.
                'guest_hostname'               => OfflinePayload::text($vm['guest_fqdn'] ?? null),

                'secure_boot'                  => OfflinePayload::text($firmware['secure_boot'] ?? null),
                'secure_boot_template'         => OfflinePayload::text($firmware['secure_boot_template'] ?? null),
                'boot_order'                   => OfflinePayload::joinList($firmware['boot_order'] ?? null),

                'automatic_start_action'        => OfflinePayload::text($vm['automatic_start_action'] ?? null),
                'automatic_start_delay'         => OfflinePayload::int($vm['automatic_start_delay'] ?? null),
                'automatic_stop_action'         => OfflinePayload::text($vm['automatic_stop_action'] ?? null),
                'automatic_critical_action'     => OfflinePayload::text($vm['automatic_critical_action'] ?? null),
                'automatic_checkpoints'         => OfflinePayload::flag($vm['automatic_checkpoints'] ?? null),
                'checkpoint_type'               => OfflinePayload::text($vm['checkpoint_type'] ?? null),

                'vm_path'                      => OfflinePayload::text($vm['path'] ?? null),
                'configuration_location'       => OfflinePayload::text($vm['configuration_location'] ?? null),
                'snapshot_file_location'       => OfflinePayload::text($vm['snapshot_file_location'] ?? null),
                'smart_paging_file_path'       => OfflinePayload::text($vm['smart_paging_file_path'] ?? null),

                'created_at_source'            => OfflinePayload::utc($vm['creation_time'] ?? null),
                'notes'                        => OfflinePayload::text($vm['notes'] ?? null),

                'disk_count'                   => count($disks),
                'disk_capacity_bytes'          => $capacity === 0 ? null : $capacity,
                'disk_file_bytes'              => $onDisk === 0 ? null : $onDisk,
                'nic_count'                    => count($nics),
                'checkpoint_count'             => count($checkpoints),

                'replication_state'            => OfflinePayload::text($replication['state'] ?? null),
                'replication_health'           => OfflinePayload::text($replication['health'] ?? null),
                'replication_mode'             => OfflinePayload::text($replication['mode'] ?? null),
                'replication_role'             => OfflinePayload::text($replication['role'] ?? null),
                'replication_primary_server'   => OfflinePayload::text($replication['primary_server'] ?? null),
                'replication_replica_server'   => OfflinePayload::text($replication['replica_server'] ?? null),
                'replication_frequency_sec'    => OfflinePayload::int($replication['frequency_seconds'] ?? null),
                'replication_recovery_history' => OfflinePayload::int($replication['recovery_history'] ?? null),
                'replicated_disk_count'        => OfflinePayload::int($replication['replicated_disk_count'] ?? null),
                'replication_last_time'        => OfflinePayload::utc($replication['last_replication_time'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $vm
     *
     * @return list<array{moref: string, fields: array<string, scalar|null>}>
     */
    private function diskRows(array $vm, string $vmMoref): array
    {
        $out = [];

        foreach ($this->listOf($vm['disks'] ?? []) as $disk) {
            $controller = OfflinePayload::text($disk['controller_type'] ?? null) ?? 'SCSI';
            $number     = OfflinePayload::int($disk['controller_number'] ?? null) ?? 0;
            $location   = OfflinePayload::int($disk['controller_location'] ?? null) ?? 0;
            $path       = OfflinePayload::text($disk['path'] ?? null);

            // Shared storage is read from the controller, not from the file
            // name — except for a VHD Set, where the extension is the only
            // thing that says so.
            $shared = OfflinePayload::flag($disk['supports_pr'] ?? null) === 1;

            if ($path !== null && str_ends_with(strtolower($path), '.vhds')) {
                $shared = true;
            }

            $out[] = [
                'moref'  => sprintf('disk:%s:%s:%d:%d', $vmMoref, strtolower($controller), $number, $location),
                'raw'    => $disk,
                'fields' => [
                    'vm_moref'             => $vmMoref,
                    'controller_type'      => $controller,
                    'controller_number'    => $number,
                    'controller_location'  => $location,
                    'disk_path'            => $path,
                    'disk_number'          => OfflinePayload::int($disk['disk_number'] ?? null),
                    'is_pass_through'      => OfflinePayload::flag($disk['is_pass_through'] ?? null) ?? 0,
                    'is_shared'            => $shared ? 1 : 0,
                    'supports_pr'          => OfflinePayload::flag($disk['supports_pr'] ?? null),
                    'vhd_format'           => OfflinePayload::text($disk['vhd_format'] ?? null),
                    'vhd_type'             => OfflinePayload::text($disk['vhd_type'] ?? null),
                    'size_bytes'           => OfflinePayload::int($disk['size_bytes'] ?? null),
                    'file_size_bytes'      => OfflinePayload::int($disk['file_size_bytes'] ?? null),
                    'parent_path'          => OfflinePayload::text($disk['parent_path'] ?? null),
                    'block_size'           => OfflinePayload::int($disk['block_size'] ?? null),
                    'logical_sector_size'  => OfflinePayload::int($disk['logical_sector_size'] ?? null),
                    'physical_sector_size' => OfflinePayload::int($disk['physical_sector_size'] ?? null),
                    'fragmentation_pct'    => OfflinePayload::int($disk['fragmentation_pct'] ?? null),
                    'disk_identifier'      => OfflinePayload::guid($disk['disk_identifier'] ?? null),
                    'maximum_iops'         => OfflinePayload::int($disk['maximum_iops'] ?? null),
                    'minimum_iops'         => OfflinePayload::int($disk['minimum_iops'] ?? null),
                    'write_hardening'      => OfflinePayload::flag($disk['write_hardening'] ?? null),
                    'vhd_error'            => OfflinePayload::text($disk['vhd_error'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vm
     *
     * @return list<array{moref: string, fields: array<string, scalar|null>}>
     */
    private function nicRows(array $vm, string $vmMoref, string $nodeMoref): array
    {
        $out = [];

        foreach ($this->listOf($vm['network_adapters'] ?? []) as $index => $nic) {
            // The adapter id Hyper-V reports already identifies the machine
            // and the adapter together, so it is the key as it stands.
            $adapterId = OfflinePayload::guid($nic['id'] ?? null);
            $moref     = $adapterId ?? sprintf('nic:%s:%d', $vmMoref, $index);

            $switchGuid = OfflinePayload::guid($nic['switch_id'] ?? null);

            $out[] = [
                'moref'  => $moref,
                'raw'    => $nic,
                'fields' => [
                    'vm_moref'               => $vmMoref,
                    'name'                   => OfflinePayload::text($nic['name'] ?? null),
                    'mac_address'            => OfflinePayload::text($nic['mac_address'] ?? null),
                    'dynamic_mac'            => OfflinePayload::flag($nic['dynamic_mac'] ?? null),
                    'switch_name'            => OfflinePayload::text($nic['switch_name'] ?? null),
                    'switch_moref'           => $switchGuid === null ? null : 'vswitch:' . $nodeMoref . ':' . $switchGuid,
                    'is_connected'           => OfflinePayload::flag($nic['connected'] ?? null),
                    'nic_status'             => OfflinePayload::text($nic['status'] ?? null),
                    'is_legacy'              => OfflinePayload::flag($nic['is_legacy'] ?? null),
                    'device_naming'          => OfflinePayload::text($nic['device_naming'] ?? null),
                    'mac_spoofing'           => OfflinePayload::text($nic['mac_spoofing'] ?? null),
                    'dhcp_guard'             => OfflinePayload::text($nic['dhcp_guard'] ?? null),
                    'router_guard'           => OfflinePayload::text($nic['router_guard'] ?? null),
                    'port_mirroring'         => OfflinePayload::text($nic['port_mirroring'] ?? null),
                    'vlan_mode'              => OfflinePayload::text($nic['vlan_mode'] ?? null),
                    'vlan_access_id'         => OfflinePayload::int($nic['vlan_access_id'] ?? null),
                    'vlan_native_id'         => OfflinePayload::int($nic['vlan_native_id'] ?? null),
                    'vlan_primary_id'        => OfflinePayload::int($nic['vlan_primary_id'] ?? null),
                    'vlan_allowed_list'      => OfflinePayload::joinList($nic['vlan_allowed_list'] ?? null),
                    'isolation_mode'         => OfflinePayload::text($nic['isolation_mode'] ?? null),
                    'isolation_vlan_id'      => OfflinePayload::int($nic['isolation_vlan_id'] ?? null),
                    'allow_untagged'         => OfflinePayload::flag($nic['allow_untagged'] ?? null),
                    'vmq_weight'             => OfflinePayload::int($nic['vmq_weight'] ?? null),
                    'iov_weight'             => OfflinePayload::int($nic['iov_weight'] ?? null),
                    'min_bandwidth_absolute' => OfflinePayload::int($nic['min_bandwidth_absolute'] ?? null),
                    'min_bandwidth_weight'   => OfflinePayload::int($nic['min_bandwidth_weight'] ?? null),
                    'max_bandwidth'          => OfflinePayload::int($nic['max_bandwidth'] ?? null),
                    'ip_addresses'           => OfflinePayload::joinList($nic['ip_addresses'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vm
     *
     * @return list<array{moref: string, fields: array<string, scalar|null>}>
     */
    private function checkpointRows(array $vm, string $vmMoref): array
    {
        $out = [];

        foreach ($this->listOf($vm['checkpoints'] ?? []) as $checkpoint) {
            $guid = OfflinePayload::guid($checkpoint['id'] ?? null);

            if ($guid === null) {
                continue;
            }

            $parent = OfflinePayload::guid($checkpoint['parent_checkpoint_id'] ?? null);

            $out[] = [
                'moref'  => 'cp:' . $guid,
                'raw'    => $checkpoint,
                'fields' => [
                    'vm_moref'                => $vmMoref,
                    'name'                    => OfflinePayload::text($checkpoint['name'] ?? null),
                    'checkpoint_type'         => OfflinePayload::text($checkpoint['checkpoint_type'] ?? null),
                    'created_at_source'       => OfflinePayload::utc($checkpoint['creation_time'] ?? null),
                    'parent_checkpoint_moref' => $parent === null ? null : 'cp:' . $parent,
                    'parent_checkpoint_name'  => OfflinePayload::text($checkpoint['parent_checkpoint'] ?? null),
                    'checkpoint_path'         => OfflinePayload::text($checkpoint['path'] ?? null),
                    'config_location'         => OfflinePayload::text($checkpoint['config_location'] ?? null),
                ],
            ];
        }

        return $out;
    }

    /**
     * A section that should be a list of objects.
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = array_is_list($value) ? $value : [$value];
        $out  = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
