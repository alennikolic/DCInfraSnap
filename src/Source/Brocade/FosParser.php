<?php
// src/Source/Brocade/FosParser.php
//
// Turns FOS command output into plain arrays.
//
// SECTIONS ARE FOUND BY CONTENT, NOT BY A DELIMITER. When several commands
// are sent in one login, FOS prints their output back to back with nothing in
// between — no prompt, no blank line, no marker. Measured on FOS 9.2.2d:
// the last line of switchshow is immediately followed by fabricshow's header.
// So each command is recognised by a line only its own output contains.
//
// The signature is the TABLE HEADER rather than the first line, because
// "switchshow" and "switchshow -portname" both begin with "switchName:" and
// would otherwise land in the same section.
//
// Every parser here is tolerant of missing fields. FOS output differs between
// releases, between fixed-port switches and directors, and between licensed
// and unlicensed ports; a parser that insists on a full line throws away the
// three quarters of it that parsed.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

final class FosParser
{
    /**
     * Commands sent in one batch, in this order.
     *
     * portshow is deliberately absent: it is one command per port, and its
     * error counters only ever increase, so collecting them would fill the
     * change journal with numbers nobody asked about.
     */
    public const COMMANDS = [
        'version',
        'switchshow',
        'switchshow -portname',
        'fabricshow',
        'nsshow',
        'cfgshow',
        'islshow',
        'trunkshow',
        'chassisshow',
    ];

    /**
     * A line that appears in one command's output and no other's.
     */
    private const SIGNATURES = [
        'version'             => '/^Kernel:\s/',
        'switchshow'          => '/^Index\s+Port\s+Address\s+Media/',
        'switchshow_portname' => '/^Index\s+Port\s+PortWWN\s+Name/',
        'fabricshow'          => '/^Switch ID\s+Worldwide Name/',
        'nsshow'              => '/^\s*Type\s+Pid\s+COS\s+PortName/',
        'cfgshow'             => '/^Defined configuration:/',
        'islshow'             => '/^\s*\d+:\s*\d+->\s*\d+\s+[0-9a-f:]{23}\s+\d+\s+\S+\s+sp:/',
        'trunkshow'           => '/^\s*\d+:\s*\d+->\s*\d+\s+[0-9a-f:]{23}\s+\d+\s+deskew/',
        'chassisshow'         => '/^Chassis State:/',
    ];

    /**
     * The header block of switchshow, which comes BEFORE the table and so
     * cannot be found by a table-header signature.
     */
    private const SWITCH_HEADER_KEYS = [
        'switchName', 'switchType', 'switchState', 'switchMode', 'switchRole',
        'switchDomain', 'switchId', 'switchWwn', 'zoning', 'switchBeacon',
        'FC Router', 'HIF Mode', 'Allow XISL Use', 'LS Attributes',
    ];

    /**
     * Split combined output into one block of lines per command.
     *
     * @return array<string, list<string>>
     */
    public static function sections(string $raw): array
    {
        $sections = [];
        $current  = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            // The switchshow header block has no table header of its own, so
            // it is recognised by its key: value lines and attached to the
            // plain switchshow section.
            if (preg_match('/^(switchName|switchWwn|switchDomain):\s/', $line)) {
                $current = 'switchshow';
                $sections[$current] ??= [];
            }

            foreach (self::SIGNATURES as $name => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $current = $name;
                    $sections[$current] ??= [];
                    break;
                }
            }

            // A shell prompt ends the current section. Only seen when the
            // commands are typed interactively rather than batched, but
            // tolerating it means pasted output parses too.
            if (preg_match('/^[\w.\-]+:(?:FID\d+:)?\w+>/', $line) === 1) {
                $current = null;
                continue;
            }

            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    // =================================================================
    // version
    // =================================================================

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    public static function version(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^(Kernel|Fabric OS|Made on|Flash|BootProm):\s*(.+?)\s*$/', $line, $m) === 1) {
                $out[$m[1]] = $m[2];
            }
        }

        return [
            'fos_version'     => isset($out['Fabric OS']) ? ltrim($out['Fabric OS'], 'v') : null,
            'kernel_version'  => $out['Kernel'] ?? null,
            'bootprom'        => $out['BootProm'] ?? null,
        ];
    }

    // =================================================================
    // switchshow
    // =================================================================

    /**
     * The switch itself, from the header block above the port table.
     *
     * @param list<string> $lines
     *
     * @return array<string, mixed>
     */
    public static function switchHeader(array $lines): array
    {
        $raw = [];

        foreach ($lines as $line) {
            if (preg_match('/^Index\s+Port/', $line) === 1) {
                break;
            }

            if (preg_match('/^([\w ]+?):\s+(.*?)\s*$/', $line, $m) === 1
                && in_array($m[1], self::SWITCH_HEADER_KEYS, true)) {
                $raw[$m[1]] = $m[2];
            }
        }

        // "ON (config)" — the effective zone configuration, straight from
        // switchshow, so it needs no inferring from cfgshow.
        $effectiveConfig = null;
        $zoningEnabled   = null;

        if (isset($raw['zoning'])) {
            $zoningEnabled = str_starts_with($raw['zoning'], 'ON');

            if (preg_match('/\(([^)]+)\)/', $raw['zoning'], $m) === 1) {
                $effectiveConfig = $m[1];
            }
        }

        // LS Attributes: [FID: 128, Base Switch: No, Default Switch: Yes, ...]
        // Truncated mid-word by "switchshow -portname", which is why this is
        // only read from the plain switchshow section.
        $vfId          = null;
        $isBaseSwitch  = null;
        $isDefaultSwitch = null;

        if (isset($raw['LS Attributes'])) {
            if (preg_match('/FID:\s*(\d+)/', $raw['LS Attributes'], $m) === 1) {
                $vfId = (int) $m[1];
            }

            if (preg_match('/Base Switch:\s*(\w+)/', $raw['LS Attributes'], $m) === 1) {
                $isBaseSwitch = strcasecmp($m[1], 'yes') === 0 ? 1 : 0;
            }

            if (preg_match('/Default Switch:\s*(\w+)/', $raw['LS Attributes'], $m) === 1) {
                $isDefaultSwitch = strcasecmp($m[1], 'yes') === 0 ? 1 : 0;
            }
        }

        return [
            'name'              => $raw['switchName'] ?? null,
            'switch_wwn'        => $raw['switchWwn'] ?? null,
            'switch_type'       => $raw['switchType'] ?? null,
            'state'             => $raw['switchState'] ?? null,
            'mode'              => $raw['switchMode'] ?? null,
            'role'              => $raw['switchRole'] ?? null,
            'domain_id'         => isset($raw['switchDomain']) ? (int) $raw['switchDomain'] : null,
            'switch_id'         => $raw['switchId'] ?? null,
            'zoning_enabled'    => $zoningEnabled === null ? null : ($zoningEnabled ? 1 : 0),
            'effective_config'  => $effectiveConfig,
            'vf_id'             => $vfId,
            'is_base_switch'    => $isBaseSwitch,
            'is_default_switch' => $isDefaultSwitch,
            'fc_router'         => isset($raw['FC Router']) ? (int) str_starts_with($raw['FC Router'], 'ON') : null,
        ];
    }

    /**
     * The port table.
     *
     * Directors put a slot number in front of the address, fixed-port
     * switches do not, so the slot is optional in the pattern. The payload
     * after the protocol column carries a different shape per port:
     *
     *   F-Port  21:00:f4:c7:aa:a4:51:19          one attached device
     *   F-Port  1 N Port + 1 NPIV public        NPIV, no WWPN shown at all
     *   E-Port  10:00:…:00 "hp-gornji" (downstream)(Trunk master)
     *   E-Port  (Trunk port, master is Port 23  )
     *   (Ports on Demand license not assigned or reserved yet)
     *
     * @param list<string> $lines
     *
     * @return array<int, array<string, mixed>> keyed by port index
     */
    public static function ports(array $lines): array
    {
        $ports   = [];
        $inTable = false;

        foreach ($lines as $line) {
            if (preg_match('/^Index\s+Port\s+Address/', $line) === 1) {
                $inTable = true;
                continue;
            }

            if (!$inTable || str_starts_with($line, '=====')) {
                continue;
            }

            $matched = preg_match(
                '/^\s*(\d+)\s+(\d+)\s+(?:(\d+)\s+)?([0-9a-f]{6})\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s*(.*)$/',
                $line,
                $m
            );

            if ($matched !== 1) {
                continue;
            }

            [, $index, $port, $slot, $address, $media, $speed, $state, $proto] = $m;
            $payload = trim($m[9]);

            $ports[(int) $index] = [
                'port_index'     => (int) $port,
                'slot'           => $slot === '' ? null : (int) $slot,
                'fcid'           => $address,
                'media'          => $media === '--' ? null : $media,
                'speed_gb'       => preg_match('/(\d+)/', $speed, $s) === 1 ? (int) $s[1] : null,

                // "N32" is auto-negotiated to 32G; "32G" is fixed.
                'speed_mode'     => str_starts_with($speed, 'N') ? 'auto' : 'fixed',

                'state'          => $state,
                'protocol'       => $proto,
            ] + self::portPayload($payload);
        }

        return $ports;
    }

    /**
     * The free-form tail of a switchshow port line.
     *
     * @return array<string, mixed>
     */
    private static function portPayload(string $payload): array
    {
        $out = [
            'port_type'            => null,
            'attached_wwpn'        => null,
            'npiv_count'           => null,
            'neighbor_switch_wwn'  => null,
            'neighbor_switch_name' => null,
            'trunk_master_port'    => null,
            'is_trunk_master'      => 0,
            'is_licensed'          => 1,
        ];

        // An unlicensed port reports no type at all, which would otherwise
        // read as 55 broken ports on a switch with Ports on Demand.
        if (stripos($payload, 'Ports on Demand license not assigned') !== false) {
            $out['is_licensed'] = 0;

            return $out;
        }

        if (preg_match('/^([A-Z]{1,2}X?-Port)\s*(.*)$/', $payload, $m) !== 1) {
            return $out;
        }

        $out['port_type'] = $m[1];
        $tail             = trim($m[2]);

        $isExpansion = in_array($m[1], ['E-Port', 'EX-Port'], true);

        if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){7})/', $tail, $w) === 1) {
            if ($isExpansion) {
                $out['neighbor_switch_wwn'] = $w[1];
            } else {
                $out['attached_wwpn'] = $w[1];
            }
        }

        if ($isExpansion && preg_match('/"([^"]+)"/', $tail, $n) === 1) {
            $out['neighbor_switch_name'] = $n[1];
        }

        // NPIV ports show a count instead of a WWPN; the actual WWPNs come
        // from the name server, matched on port index.
        if (preg_match('/(\d+)\s+N\s+Port\s*\+\s*(\d+)\s+NPIV/i', $tail, $np) === 1) {
            $out['npiv_count'] = (int) $np[2];
        }

        if (preg_match('/master is Port\s+(\d+)/i', $tail, $tm) === 1) {
            $out['trunk_master_port'] = (int) $tm[1];
        }

        if (stripos($tail, 'Trunk master') !== false) {
            $out['is_trunk_master'] = 1;
        }

        return $out;
    }

    /**
     * Port WWNs and names, from "switchshow -portname".
     *
     * @param list<string> $lines
     *
     * @return array<int, array{port_wwn: string, port_name: string|null}>
     */
    public static function portNames(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+([0-9a-f:]{23})\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $index = (int) $m[1];
            $name  = trim($m[4]);

            // FOS names every port "port<index>" until someone changes it.
            // Stored as given, but recognised here so the interface can show
            // an unnamed port as unnamed rather than as data.
            $isDefault = $name === 'port' . $index;

            $out[$index] = [
                'port_wwn'   => $m[3],
                'port_name'  => $name === '' ? null : $name,
                'name_is_default' => $isDefault,
            ];
        }

        return $out;
    }

    // =================================================================
    // fabricshow
    // =================================================================

    /**
     * The switches in the fabric.
     *
     * The principal switch is marked with ">" immediately before its quoted
     * name. There is no fabric WWN and no fabric name in this output — on
     * FOS 9.2.2d a fabric name appears only when one has been configured —
     * so the principal's WWN is what identifies the fabric.
     *
     * @param list<string> $lines
     *
     * @return array{members: list<array<string, mixed>>, declared_count: int|null, fabric_name: string|null, principal_wwn: string|null}
     */
    public static function fabric(array $lines): array
    {
        $members       = [];
        $declaredCount = null;
        $fabricName    = null;
        $principalWwn  = null;

        foreach ($lines as $line) {
            if (preg_match(
                '/^\s*(\d+):\s*([0-9a-f]{6})\s+([0-9a-f:]{23})\s+(\S+)\s+(\S+)\s+(>?)"([^"]*)"/',
                $line,
                $m
            ) === 1) {
                $isPrincipal = $m[6] === '>';

                if ($isPrincipal) {
                    $principalWwn = $m[3];
                }

                $members[] = [
                    'domain_id'    => (int) $m[1],
                    'switch_id'    => $m[2],
                    'switch_wwn'   => $m[3],
                    'ip_address'   => $m[4] === '0.0.0.0' ? null : $m[4],
                    'fc_ip'        => $m[5] === '0.0.0.0' ? null : $m[5],
                    'switch_name'  => $m[7] === '' ? null : $m[7],
                    'is_principal' => $isPrincipal ? 1 : 0,
                ];

                continue;
            }

            if (preg_match('/Fabric has (\d+) switch/i', $line, $m) === 1) {
                $declaredCount = (int) $m[1];
            }

            if (preg_match('/^Fabric Name:\s*(.+?)\s*$/', $line, $m) === 1) {
                $fabricName = $m[1];
            }
        }

        return [
            'members'        => $members,
            'declared_count' => $declaredCount,
            'fabric_name'    => $fabricName,
            'principal_wwn'  => $principalWwn,
        ];
    }

    // =================================================================
    // nsshow
    // =================================================================

    /**
     * Name server entries: the devices logged in to this switch.
     *
     * One entry per WWPN, so an NPIV port contributes several entries that
     * share a port index. The symbolic names are kept whole: every vendor
     * writes them differently — QLogic puts the card model, firmware and
     * driver in NodeSymb, Emulex uses HN: and OS: tags, Dell PowerStore uses
     * a "::"-delimited form with the array serial — and a parser that
     * insisted on one shape would lose the other two.
     *
     * @param list<string> $lines
     *
     * @return array{devices: list<array<string, mixed>>, declared_count: int|null}
     */
    public static function nameServer(array $lines): array
    {
        $devices       = [];
        $current       = null;
        $declaredCount = null;

        $flush = static function () use (&$current, &$devices): void {
            if ($current !== null) {
                $devices[] = $current;
                $current   = null;
            }
        };

        foreach ($lines as $line) {
            // N 0c0000;  3;21:00:…:19;20:00:…:19; 0x00000003
            if (preg_match(
                '/^\s*(\w+)\s+([0-9a-f]{6});\s*(\d+);([0-9a-f:]{23});([0-9a-f:]{23});\s*(\S*)/',
                $line,
                $m
            ) === 1) {
                $flush();

                $current = [
                    'entry_type'      => $m[1],
                    'fcid'            => $m[2],
                    'cos'             => $m[3],
                    'wwpn'            => $m[4],
                    'wwnn'            => $m[5],
                    'scr'             => $m[6] === '' ? null : $m[6],
                    'port_symb'       => null,
                    'node_symb'       => null,
                    'device_type'     => null,
                    'port_index'      => null,
                    'fc4_types'       => null,
                    'link_speed'      => null,
                    'fabric_port_wwn' => null,
                    'permanent_wwpn'  => null,
                    'is_npiv'         => 0,
                ];

                continue;
            }

            if (preg_match('/has (\d+) entries/i', $line, $m) === 1) {
                $declaredCount = (int) $m[1];
                continue;
            }

            if ($current === null) {
                continue;
            }

            $trimmed = trim($line);

            if (preg_match('/^PortSymb:\s*\[\d+\]\s*"(.*)"$/', $trimmed, $m) === 1) {
                // Padded to a fixed width with dots by some HBA firmware.
                $current['port_symb'] = rtrim($m[1], '. ') ?: null;
            } elseif (preg_match('/^NodeSymb:\s*\[\d+\]\s*"(.*)"$/', $trimmed, $m) === 1) {
                $current['node_symb'] = rtrim($m[1], '. ') ?: null;
            } elseif (preg_match('/^Device type:\s*(.+)$/', $trimmed, $m) === 1) {
                $current['device_type'] = trim($m[1]);
                $current['is_npiv']     = stripos($m[1], 'NPIV') !== false ? 1 : 0;
            } elseif (preg_match('/^Port Index:\s*(\d+)$/', $trimmed, $m) === 1) {
                $current['port_index'] = (int) $m[1];
            } elseif (preg_match('/^FC4s:\s*(.+)$/', $trimmed, $m) === 1) {
                $current['fc4_types'] = trim($m[1]);
            } elseif (preg_match('/^Device link speed:\s*(.+)$/', $trimmed, $m) === 1) {
                $current['link_speed'] = trim($m[1]);
            } elseif (preg_match('/^Fabric Port Name:\s*([0-9a-f:]{23})$/', $trimmed, $m) === 1) {
                $current['fabric_port_wwn'] = $m[1];
            } elseif (preg_match('/^Permanent Port Name:\s*([0-9a-f:]{23})$/', $trimmed, $m) === 1) {
                $current['permanent_wwpn'] = $m[1];
            }
        }

        $flush();

        return ['devices' => $devices, 'declared_count' => $declaredCount];
    }

    // =================================================================
    // cfgshow
    // =================================================================

    /**
     * Zoning: the defined configuration and the effective one.
     *
     * Both are worth keeping. The defined section is what someone configured,
     * with zone members given as alias names; the effective section is what
     * the fabric is actually enforcing, with the aliases already resolved to
     * WWPNs. The difference is the interesting part — a zone edited but never
     * enabled shows up as a zone whose two sections disagree.
     *
     * Long member lists wrap onto continuation lines indented under the
     * object they belong to.
     *
     * @param list<string> $lines
     *
     * @return array{objects: list<array<string, mixed>>, members: array<string, list<string>>}
     */
    public static function zoning(array $lines): array
    {
        $section = null;
        $kind    = null;
        $name    = null;

        $objects = [];
        $members = [];

        foreach ($lines as $line) {
            if (preg_match('/^Defined configuration:/', $line) === 1) {
                $section = 'defined';
                continue;
            }

            if (preg_match('/^Effective configuration:/', $line) === 1) {
                $section = 'effective';
                continue;
            }

            if ($section === null) {
                continue;
            }

            if (preg_match('/^\s(cfg|zone|alias):\s+(\S+)\s*(.*)$/', $line, $m) === 1) {
                $kind = $m[1];
                $name = $m[2];
                $key  = $section . '|' . $kind . '|' . $name;

                $objects[$key] ??= [
                    'section' => $section,
                    'kind'    => $kind,
                    'name'    => $name,
                ];

                self::addMembers($members, $key, $m[3]);

                continue;
            }

            if ($kind !== null && preg_match('/^\s{4,}(\S.*)$/', $line, $m) === 1) {
                self::addMembers($members, $section . '|' . $kind . '|' . $name, $m[1]);
            }
        }

        return ['objects' => array_values($objects), 'members' => $members];
    }

    /**
     * @param array<string, list<string>> $members
     */
    private static function addMembers(array &$members, string $key, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        foreach (explode(';', $text) as $part) {
            $part = trim($part);

            if ($part !== '') {
                $members[$key][] = $part;
            }
        }
    }

    // =================================================================
    // islshow and trunkshow
    // =================================================================

    /**
     * Inter-switch links, one row per link.
     *
     * Format: <index>: <local port>-> <remote port> <remote wwn> <remote
     * domain> <remote name> sp: <speed> bw: <bandwidth> <flags>
     *
     * The bandwidth exceeds the speed when the link is trunked — 16G of speed
     * with 32G of bandwidth means two 16G members.
     *
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    public static function isls(array $lines): array
    {
        $isls = [];

        foreach ($lines as $line) {
            if (preg_match(
                '/^\s*(\d+):\s*(\d+)->\s*(\d+)\s+([0-9a-f:]{23})\s+(\d+)\s+(\S+)\s+'
                . 'sp:\s*([\d.]+)G\s+bw:\s*([\d.]+)G\s*(.*)$/',
                $line,
                $m
            ) !== 1) {
                continue;
            }

            $flags = trim($m[9]);

            $isls[] = [
                'isl_index'            => (int) $m[1],
                'local_port'           => (int) $m[2],
                'remote_port'          => (int) $m[3],
                'remote_switch_wwn'    => $m[4],
                'remote_domain_id'     => (int) $m[5],
                'remote_switch_name'   => $m[6],
                'speed_gb'             => (int) round((float) $m[7]),
                'bandwidth_gb'         => (int) round((float) $m[8]),
                'flags'                => $flags === '' ? null : $flags,
                'is_trunk'             => stripos($flags, 'TRUNK') !== false ? 1 : 0,
            ];
        }

        return $isls;
    }

    /**
     * Trunk groups and their member ports.
     *
     * The master line carries the group number and "MASTER"; the member lines
     * that follow are indented and belong to the group above them.
     *
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    public static function trunks(array $lines): array
    {
        $trunks = [];
        $group  = null;

        foreach ($lines as $line) {
            if (preg_match(
                '/^\s*(\d+):\s*(\d+)->\s*(\d+)\s+([0-9a-f:]{23})\s+(\d+)\s+deskew\s+(\d+)\s+(\S+)\s+MASTER/',
                $line,
                $m
            ) === 1) {
                $group = (int) $m[1];

                $trunks[] = [
                    'trunk_group'       => $group,
                    'local_port'        => (int) $m[2],
                    'remote_port'       => (int) $m[3],
                    'remote_switch_wwn' => $m[4],
                    'remote_domain_id'  => (int) $m[5],
                    'deskew'            => (int) $m[6],
                    'is_master'         => 1,
                ];

                continue;
            }

            if (preg_match(
                '/^\s+(\d+)->\s*(\d+)\s+([0-9a-f:]{23})\s+(\d+)\s+deskew\s+(\d+)/',
                $line,
                $m
            ) === 1) {
                $trunks[] = [
                    'trunk_group'       => $group,
                    'local_port'        => (int) $m[1],
                    'remote_port'       => (int) $m[2],
                    'remote_switch_wwn' => $m[3],
                    'remote_domain_id'  => (int) $m[4],
                    'deskew'            => (int) $m[5],
                    'is_master'         => 0,
                ];
            }
        }

        return $trunks;
    }

    // =================================================================
    // chassisshow
    // =================================================================

    /**
     * The chassis, and the units inside it.
     *
     * Serial numbers come in pairs: "Factory Serial Num" is Brocade's and
     * "Serial Num" is the OEM's rebranding of it. Both are kept, because a
     * support contract may quote either and there is no telling which from
     * here.
     *
     * @param list<string> $lines
     *
     * @return array{chassis: array<string, string>, units: list<array<string, mixed>>}
     */
    public static function chassis(array $lines): array
    {
        $chassis = [];
        $units   = [];
        $unit    = null;

        $flush = static function () use (&$unit, &$units): void {
            if ($unit !== null) {
                $units[] = $unit;
                $unit    = null;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^(FAN|BLOWER|POWER SUPPLY|CHASSIS\/WWN|WWN|SENSOR)\s+Unit:\s*(\d+)/', $line, $m) === 1) {
                $flush();

                $unit = ['kind' => $m[1], 'unit' => (int) $m[2]];

                continue;
            }

            if (preg_match('/^\s*([\w\/ ]+?):\s+(.+?)\s*$/', $line, $m) === 1) {
                $key   = trim($m[1]);
                $value = trim($m[2]);

                if ($unit === null) {
                    $chassis[$key] = $value;
                } else {
                    $unit[$key] = $value;
                }
            }
        }

        $flush();

        return ['chassis' => $chassis, 'units' => $units];
    }
}
