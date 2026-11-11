<?php
// src/Source/Brocade/FosClient.php
//
// One switch, read in one login.
//
// The transport runs the batch, the parser turns the text into arrays, and
// this class holds the result and answers questions about it. Keeping the
// three apart means a FOS release that changes one command's layout is a
// change to one parser method, not to everything that reads it.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

final class FosClient
{
    /** @var array<string, list<string>>|null raw output per command */
    private ?array $sections = null;

    /** @var array<string, mixed> */
    private array $switch = [];

    /** @var array<string, string|null> */
    private array $version = [];

    public function __construct(
        private readonly SshTransport $transport,
    ) {
    }

    public function commandCount(): int
    {
        return $this->transport->commandCount();
    }

    public function usedLegacyAlgorithms(): bool
    {
        return $this->transport->usedLegacyAlgorithms();
    }

    /**
     * Log in, run everything, parse it.
     */
    public function collect(): void
    {
        $raw = $this->transport->run(FosParser::COMMANDS);

        $this->sections = FosParser::sections($raw);

        $this->version = FosParser::version($this->sections['version'] ?? []);
        $this->switch  = FosParser::switchHeader($this->sections['switchshow'] ?? []);

        // A login that succeeds against something that is not a switch is
        // worth catching here rather than letting every parser return empty.
        if (($this->switch['switch_wwn'] ?? null) === null || ($this->switch['name'] ?? null) === null) {
            throw BrocadeException::notBrocade('this endpoint');
        }
    }

    /**
     * Log in, read the version and the switch name, and stop.
     *
     * This is what the connection test runs. It uses the same batch as a full
     * collection minus the expensive commands, so a test that passes means a
     * collection will reach the switch — not merely that port 22 is open.
     *
     * @return array{name: string|null, fos_version: string|null, switch_wwn: string|null, domain_id: int|null, legacy: bool}
     */
    public function testConnection(): array
    {
        $raw      = $this->transport->run(['version', 'switchshow']);
        $sections = FosParser::sections($raw);

        $version = FosParser::version($sections['version'] ?? []);
        $switch  = FosParser::switchHeader($sections['switchshow'] ?? []);

        if (($switch['switch_wwn'] ?? null) === null) {
            throw BrocadeException::notBrocade('this endpoint');
        }

        return [
            'name'        => $switch['name'] ?? null,
            'fos_version' => $version['fos_version'] ?? null,
            'switch_wwn'  => $switch['switch_wwn'] ?? null,
            'domain_id'   => $switch['domain_id'] ?? null,
            'legacy'      => $this->transport->usedLegacyAlgorithms(),
        ];
    }

    // -----------------------------------------------------------------
    // What was collected
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function switchInfo(): array
    {
        return $this->switch + $this->version;
    }

    /**
     * Ports, with the WWN and name from the second switchshow merged in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ports(): array
    {
        $ports = FosParser::ports($this->sections['switchshow'] ?? []);
        $names = FosParser::portNames($this->sections['switchshow_portname'] ?? []);

        foreach ($ports as $index => $port) {
            $ports[$index] = $port + [
                'port_wwn'        => $names[$index]['port_wwn'] ?? null,
                'port_name'       => $names[$index]['port_name'] ?? null,
                'name_is_default' => $names[$index]['name_is_default'] ?? true,
            ];
        }

        return $ports;
    }

    /**
     * @return array{members: list<array<string, mixed>>, declared_count: int|null, fabric_name: string|null, principal_wwn: string|null}
     */
    public function fabric(): array
    {
        return FosParser::fabric($this->sections['fabricshow'] ?? []);
    }

    /**
     * @return array{devices: list<array<string, mixed>>, declared_count: int|null}
     */
    public function nameServer(): array
    {
        return FosParser::nameServer($this->sections['nsshow'] ?? []);
    }

    /**
     * @return array{objects: list<array<string, mixed>>, members: array<string, list<string>>}
     */
    public function zoning(): array
    {
        return FosParser::zoning($this->sections['cfgshow'] ?? []);
    }

    /**
     * ISLs, with their trunk members attached.
     *
     * islshow reports one row per link; trunkshow says which ports make it
     * up. They are joined here because a reader wants "this ISL is two 16G
     * ports", not two lists to reconcile.
     *
     * @return list<array<string, mixed>>
     */
    public function isls(): array
    {
        $isls   = FosParser::isls($this->sections['islshow'] ?? []);
        $trunks = FosParser::trunks($this->sections['trunkshow'] ?? []);

        foreach ($isls as $i => $isl) {
            $group = null;

            foreach ($trunks as $trunk) {
                if ($trunk['is_master'] === 1 && $trunk['local_port'] === $isl['local_port']) {
                    $group = $trunk['trunk_group'];
                    break;
                }
            }

            $memberPorts = [];

            if ($group !== null) {
                foreach ($trunks as $trunk) {
                    if ($trunk['trunk_group'] === $group) {
                        $memberPorts[] = $trunk['local_port'];
                    }
                }
            }

            sort($memberPorts);

            $isls[$i]['trunk_group']  = $group;
            $isls[$i]['member_ports'] = $memberPorts;
            $isls[$i]['member_count'] = $memberPorts === [] ? 1 : count($memberPorts);
        }

        return $isls;
    }

    /**
     * @return array{chassis: array<string, string>, units: list<array<string, mixed>>}
     */
    public function chassis(): array
    {
        return FosParser::chassis($this->sections['chassisshow'] ?? []);
    }

    /**
     * Which commands produced output, and which did not.
     *
     * A command that returns nothing is usually a permission problem on the
     * account rather than a parsing failure, and the dry run says so.
     *
     * @return array<string, int> command => lines of output
     */
    public function coverage(): array
    {
        // Keyed by section name rather than by command string, because
        // "switchshow" and "switchshow -portname" are two commands whose
        // output lands in two differently named sections.
        $coverage = [];

        foreach ([
            'version', 'switchshow', 'switchshow_portname', 'fabricshow',
            'nsshow', 'cfgshow', 'islshow', 'trunkshow', 'chassisshow',
        ] as $name) {
            $coverage[$name] = count($this->sections[$name] ?? []);
        }

        return $coverage;
    }

    /**
     * The raw text of one section, for diagnosing a parse that came back thin.
     *
     * @return list<string>
     */
    public function rawSection(string $name): array
    {
        return $this->sections[$name] ?? [];
    }
}
