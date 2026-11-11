<?php
// src/Source/Brocade/InventoryCollector.php
//
// Turns one collected switch into inventory objects.
//
// The client holds parsed FOS output; this turns it into the shape the
// inventory layer stores: a moref that identifies the object on the next
// collection, the fields that go into columns, and the raw array kept in the
// raw layer for whatever turns out to matter later.
//
// WHY EACH MOREF IS WHAT IT IS. The moref has one job: to be the same string
// next week for the same thing. So each is taken from what the switch itself
// assigns and does not reassign:
//
//   fc_switch         the switch WWN     burned in at the factory
//   fc_fabric_member  the member WWN     likewise, per member
//   fc_port           port:<index>       a port's index is its position
//   fc_isl            isl:<local port>   the port the link leaves from
//   fc_device         the device WWPN    burned into the HBA port
//   fc_zone_object    section:kind:name  zoning objects are named, not numbered
//   fc_zone_member    the above + member
//
// Deliberately NOT used: islshow's row number, which renumbers when a link
// goes away; a device's FCID, which the switch hands out on login and can
// differ after a reboot; and a zone member's position in its list.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

final class InventoryCollector
{
    /** A WWN as FOS prints it: 23 characters of colon-separated hex. */
    private const WWN_PATTERN = '/^[0-9a-f]{2}(:[0-9a-f]{2}){7}$/';

    public function __construct(
        private readonly FosClient $client,
    ) {
    }

    /**
     * The switch itself: what switchshow, version, fabricshow and
     * chassisshow say about the box this run connected to.
     *
     * One object, in a list, because the inventory layer takes lists.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function switchObject(): array
    {
        $info    = $this->client->switchInfo();
        $fabric  = $this->client->fabric();
        $chassis = $this->client->chassis();
        $ports   = $this->client->ports();

        $wwn = $info['switch_wwn'] ?? null;

        if ($wwn === null) {
            return [];
        }

        // chassisshow reports several units; the identity of the box is on
        // the one FOS labels CHASSIS/WWN.
        $identity = [];

        foreach ($chassis['units'] as $unit) {
            if (($unit['kind'] ?? null) === 'CHASSIS/WWN') {
                $identity = $unit;
                break;
            }
        }

        $licensed = 0;

        foreach ($ports as $port) {
            if (($port['is_licensed'] ?? 0) === 1) {
                $licensed++;
            }
        }

        return [[
            'moref'  => (string) $wwn,
            'fields' => [
                'name'                => $info['name'] ?? null,
                'switch_wwn'          => $wwn,
                'domain_id'           => $info['domain_id'] ?? null,
                'switch_id'           => $info['switch_id'] ?? null,
                'switch_type'         => $info['switch_type'] ?? null,
                'state'               => $info['state'] ?? null,

                // The parser reports these as mode and role; the columns are
                // switch_mode and switch_role, because MODE and ROLE read as
                // keywords in a query and invite quoting everywhere.
                'switch_mode'         => $info['mode'] ?? null,
                'switch_role'         => $info['role'] ?? null,

                'zoning_enabled'      => $info['zoning_enabled'] ?? null,
                'effective_config'    => $info['effective_config'] ?? null,
                'vf_id'               => $info['vf_id'] ?? null,
                'is_base_switch'      => $info['is_base_switch'] ?? null,
                'is_default_switch'   => $info['is_default_switch'] ?? null,
                'fc_router'           => $info['fc_router'] ?? null,

                'fos_version'         => $info['fos_version'] ?? null,
                'kernel_version'      => $info['kernel_version'] ?? null,
                'bootprom'            => $info['bootprom'] ?? null,

                'fabric_name'         => $fabric['fabric_name'] ?? null,
                'principal_wwn'       => $fabric['principal_wwn'] ?? null,
                'fabric_member_count' => count($fabric['members']),

                'serial_number'       => $identity['Factory Serial Num'] ?? null,
                'oem_serial_number'   => $identity['Serial Num'] ?? null,
                'part_number'         => $identity['Factory Part Num'] ?? null,
                'oem_part_number'     => $identity['Part Num'] ?? null,
                'oem_id'              => $identity['ID'] ?? null,

                'port_count'          => count($ports),
                'licensed_port_count' => $licensed,
            ],
            'raw' => [
                'switch'  => $info,
                'fabric'  => $fabric,
                'chassis' => $chassis,
            ],
        ]];
    }

    /**
     * Every switch in the fabric, as this switch sees it.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function fabricMembers(): array
    {
        $fabric = $this->client->fabric();
        $ownWwn = $this->client->switchInfo()['switch_wwn'] ?? null;

        $objects = [];

        foreach ($fabric['members'] as $member) {
            $wwn = $member['switch_wwn'] ?? null;

            if ($wwn === null) {
                continue;
            }

            $objects[] = [
                'moref'  => (string) $wwn,
                'fields' => [
                    'domain_id'    => $member['domain_id'] ?? null,
                    'switch_id'    => $member['switch_id'] ?? null,
                    'switch_wwn'   => $wwn,
                    'switch_name'  => $member['switch_name'] ?? null,
                    'ip_address'   => $member['ip_address'] ?? null,
                    'fc_ip'        => $member['fc_ip'] ?? null,
                    'is_principal' => $member['is_principal'] ?? null,

                    // fabricshow marks the local switch with a > in some
                    // releases and not in others, so it is decided here by
                    // comparing WWNs rather than trusted to the output.
                    'is_local'     => $ownWwn !== null && $wwn === $ownWwn ? 1 : 0,
                ],
                'raw' => $member,
            ];
        }

        return $objects;
    }

    /**
     * Every physical port, licensed or not, up or down.
     *
     * Unlicensed and dark ports are kept deliberately: "port 14 has no
     * module" and "port 14 is not licensed" are both answers somebody will
     * want, and neither is available from a list of live ports only.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function ports(): array
    {
        $objects = [];

        foreach ($this->client->ports() as $port) {
            $index = $port['port_index'] ?? null;

            if ($index === null) {
                continue;
            }

            $objects[] = [
                'moref'  => 'port:' . $index,
                'fields' => [
                    'port_index'           => $index,
                    'slot'                 => self::nullIfBlank($port['slot'] ?? null),
                    'port_wwn'             => $port['port_wwn'] ?? null,
                    'port_name'            => $port['port_name'] ?? null,
                    'name_is_default'      => ($port['name_is_default'] ?? true) ? 1 : 0,
                    'fcid'                 => self::nullIfBlank($port['fcid'] ?? null),
                    'media'                => self::nullIfBlank($port['media'] ?? null),
                    'speed_gb'             => $port['speed_gb'] ?? null,
                    'speed_mode'           => $port['speed_mode'] ?? null,
                    'state'                => $port['state'] ?? null,
                    'protocol'             => $port['protocol'] ?? null,
                    'port_type'            => $port['port_type'] ?? null,
                    'attached_wwpn'        => $port['attached_wwpn'] ?? null,
                    'npiv_count'           => $port['npiv_count'] ?? null,
                    'neighbor_switch_wwn'  => $port['neighbor_switch_wwn'] ?? null,
                    'neighbor_switch_name' => $port['neighbor_switch_name'] ?? null,
                    'trunk_master_port'    => $port['trunk_master_port'] ?? null,
                    'is_trunk_master'      => $port['is_trunk_master'] ?? 0,
                    'is_licensed'          => $port['is_licensed'] ?? 1,
                ],
                'raw' => $port,
            ];
        }

        return $objects;
    }

    /**
     * Links to other switches, with their trunk members.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function isls(): array
    {
        $objects = [];

        foreach ($this->client->isls() as $isl) {
            $local = $isl['local_port'] ?? null;

            if ($local === null) {
                continue;
            }

            $members = $isl['member_ports'] ?? [];

            $objects[] = [
                'moref'  => 'isl:' . $local,
                'fields' => [
                    'isl_index'          => $isl['isl_index'] ?? null,
                    'local_port'         => $local,
                    'remote_port'        => $isl['remote_port'] ?? null,
                    'remote_switch_wwn'  => $isl['remote_switch_wwn'] ?? null,
                    'remote_domain_id'   => $isl['remote_domain_id'] ?? null,
                    'remote_switch_name' => $isl['remote_switch_name'] ?? null,
                    'speed_gb'           => $isl['speed_gb'] ?? null,
                    'bandwidth_gb'       => $isl['bandwidth_gb'] ?? null,
                    'flags'              => $isl['flags'] ?? null,
                    'is_trunk'           => $isl['is_trunk'] ?? 0,
                    'trunk_group'        => $isl['trunk_group'] ?? null,
                    'member_count'       => $isl['member_count'] ?? 1,

                    // Stored as text rather than as a second table: a trunk
                    // has two to eight members and they are only ever read
                    // together with the link itself.
                    'member_ports'       => $members === [] ? null : implode(',', $members),
                ],
                'raw' => $isl,
            ];
        }

        return $objects;
    }

    /**
     * Devices logged in to the fabric, from the name server.
     *
     * Keyed by WWPN rather than FCID: the FCID is assigned at login and can
     * change, while the WWPN is the HBA port.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function devices(): array
    {
        $objects = [];

        foreach ($this->client->nameServer()['devices'] as $device) {
            $wwpn = $device['wwpn'] ?? null;

            if ($wwpn === null) {
                continue;
            }

            $objects[] = [
                'moref'  => (string) $wwpn,
                'fields' => [
                    'entry_type'      => $device['entry_type'] ?? null,
                    'fcid'            => $device['fcid'] ?? null,
                    'port_index'      => $device['port_index'] ?? null,
                    'wwpn'            => $wwpn,
                    'wwnn'            => $device['wwnn'] ?? null,
                    'permanent_wwpn'  => $device['permanent_wwpn'] ?? null,
                    'fabric_port_wwn' => $device['fabric_port_wwn'] ?? null,

                    // Vendor-specific and stored verbatim. A QLogic HBA
                    // registers "QLE2772CL FW:v9.15.12 DVR:v5.4.86.0"; an
                    // array registers something else entirely. Splitting it
                    // per vendor would be guesswork that rots silently.
                    'node_symb'       => self::clip($device['node_symb'] ?? null, 255),
                    'port_symb'       => self::clip($device['port_symb'] ?? null, 255),

                    'device_type'     => $device['device_type'] ?? null,
                    'fc4_types'       => $device['fc4_types'] ?? null,
                    'link_speed'      => $device['link_speed'] ?? null,
                    'cos'             => self::clip($device['cos'] ?? null, 16),
                    'scr'             => self::clip($device['scr'] ?? null, 16),
                    'is_npiv'         => $device['is_npiv'] ?? 0,
                ],
                'raw' => $device,
            ];
        }

        return $objects;
    }

    /**
     * Zone configurations, zones and aliases, from both sections.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function zoneObjects(): array
    {
        $zoning  = $this->client->zoning();
        $objects = [];

        foreach ($zoning['objects'] as $object) {
            $key     = self::memberKey($object);
            $members = $zoning['members'][$key] ?? [];

            $objects[] = [
                'moref'  => self::zoneMoref($object),
                'fields' => [
                    'section'      => $object['section'],
                    'kind'         => $object['kind'],
                    'name'         => $object['name'],
                    'member_count' => count($members),
                ],
                'raw' => $object + ['members' => $members],
            ];
        }

        return $objects;
    }

    /**
     * What each zoning object contains, one row per membership.
     *
     * @return list<array{moref: string, fields: array<string, mixed>, raw: array<string, mixed>}>
     */
    public function zoneMembers(): array
    {
        $zoning  = $this->client->zoning();
        $objects = [];

        foreach ($zoning['objects'] as $object) {
            $members = $zoning['members'][self::memberKey($object)] ?? [];

            foreach ($members as $position => $member) {
                $objects[] = [
                    'moref'  => self::zoneMoref($object) . ':' . $member,
                    'fields' => [
                        'section'     => $object['section'],
                        'kind'        => $object['kind'],
                        'object_name' => $object['name'],
                        'member_name' => self::clip($member, 128),
                        'member_kind' => self::classify($member, $object['kind']),
                        'sort_order'  => $position + 1,
                    ],
                    'raw' => [
                        'section'  => $object['section'],
                        'kind'     => $object['kind'],
                        'object'   => $object['name'],
                        'member'   => $member,
                        'position' => $position + 1,
                    ],
                ];
            }
        }

        return $objects;
    }

    // -----------------------------------------------------------------

    /**
     * What a member string actually is.
     *
     * cfgshow does not say. A configuration contains zones; a zone contains
     * aliases or WWPNs directly; an alias contains WWPNs, or a domain and
     * port pair for a device addressed by where it is plugged in.
     *
     * @param array<string, mixed> $member
     */
    private static function classify(string $member, string $containerKind): string
    {
        if (preg_match(self::WWN_PATTERN, $member) === 1) {
            return 'wwpn';
        }

        // "1,14" means domain 1, port 14 — a device identified by position
        // rather than by name.
        if (preg_match('/^\d+,\d+$/', $member) === 1) {
            return 'domain_port';
        }

        return $containerKind === 'cfg' ? 'zone' : 'alias';
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function zoneMoref(array $object): string
    {
        return $object['section'] . ':' . $object['kind'] . ':' . $object['name'];
    }

    /**
     * The key the parser files members under, which uses | rather than : so
     * that a name containing a colon cannot collide.
     *
     * @param array<string, mixed> $object
     */
    private static function memberKey(array $object): string
    {
        return $object['section'] . '|' . $object['kind'] . '|' . $object['name'];
    }

    /**
     * FOS pads its columns, and a field that is only padding is absent
     * rather than empty.
     */
    private static function nullIfBlank(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' || $trimmed === '--' ? null : $trimmed;
    }

    /**
     * Keep a string within its column.
     *
     * Symbolic names are whatever a vendor chose to register, and a device
     * that registers 300 characters should not fail the whole collection.
     */
    private static function clip(mixed $value, int $length): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
