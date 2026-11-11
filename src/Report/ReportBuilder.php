<?php
// src/Report/ReportBuilder.php
//
// Turns what was collected into a Document: two chapters, one per kind of
// source, with a section for every source and a subsection for every group
// of objects under it.
//
// WHY PER SOURCE AND NOT PER OBJECT TYPE. A report of two vCenters could be
// one hosts table with a "source" column, or two sections each with their
// own. It is written as the second, because this document is read as a
// description of an environment: somebody looking up what is in the Dell
// cluster wants that part of the document to be about the Dell cluster, not
// to filter a combined table by eye.
//
// EVERY VALUE IS FORMATTED HERE. The writers receive display strings and
// never a byte count or a raw timestamp, so HTML and Word cannot drift apart
// in how they render a number. Nothing in here produces markup.
//
// WHAT IS LEFT OUT, AND WHY:
//   - the full machine table. 243 rows of virtual machines is most of the
//     document and almost never what it is read for; the totals and the
//     power states say what the environment runs. The machine list is a page
//     in the application, and a CSV export, for the times it is wanted.
//   - the ports with no port_type. Sixty-four rows of empty slots per switch,
//     with the counts above the table already saying how many there are.
//   - the change journals. This document describes one moment; what moved
//     between two collections is a question the application answers.

declare(strict_types=1);

namespace DcInfraSnap\Report;

use DcInfraSnap\I18n\Translator;
use DcInfraSnap\Project\Project;
use DcInfraSnap\Web\Format;

final class ReportBuilder
{
    /** Sections of chapter 1, in the order they appear. */
    public const VMWARE_SECTIONS = ['clusters', 'hosts', 'host_devices', 'datastores', 'vms', 'licenses'];

    /** Sections of chapter 2, in the order they appear. */
    public const FC_SECTIONS = ['fabric', 'ports', 'isls', 'fc_devices', 'zoning'];

    /**
     * The heading each section gets, as a translation key.
     *
     * Declared here rather than in the template so the form that offers the
     * sections and the document that prints them cannot end up calling the
     * same section two different things.
     */
    public const SECTION_LABELS = [
        'clusters'     => 'nav.clusters',
        'hosts'        => 'nav.hosts',
        'host_devices' => 'devices.title',
        'datastores'   => 'nav.datastores',
        'vms'          => 'nav.vms',
        'licenses'     => 'nav.licenses',
        'fabric'       => 'san.fabric',
        'ports'        => 'san.ports',
        'isls'         => 'san.isls',
        'fc_devices'   => 'san.devices',
        'zoning'       => 'san.zoning',
    ];

    /**
     * The device kinds of a host, in the order they are printed, with the
     * heading each table gets.
     */
    private const DEVICE_KINDS = [
        'pnic'      => 'devices.pnic',
        'vmk'       => 'devices.vmk',
        'hba'       => 'devices.hba',
        'vswitch'   => 'devices.vswitch',
        'portgroup' => 'devices.portgroup',
    ];

    public function __construct(
        private readonly ReportQuery $query,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @return list<string> every section key, both chapters
     */
    public static function allSections(): array
    {
        return array_merge(self::VMWARE_SECTIONS, self::FC_SECTIONS);
    }

    /**
     * @param list<int>    $sourceIds the sources to describe
     * @param list<string> $sections  which section keys to include
     */
    public function build(array $sourceIds, array $sections, ?Project $project = null): Document
    {
        $vcenters = $this->query->vcenterSources($sourceIds);
        $switches = $this->query->brocadeSources($sourceIds);

        $chapters = [];
        $number   = 0;

        // A chapter needs both: sources of that kind, and at least one of its
        // sections ticked. Unticking every Fibre Channel section means the
        // reader does not want the fabric described, and a chapter holding
        // nothing but the switch's serial number is not what they asked for.
        //
        // Chapters are numbered as they appear, so a switch-only report has
        // chapter 1 rather than a chapter 2 with nothing before it.
        if ($vcenters !== [] && array_intersect(self::VMWARE_SECTIONS, $sections) !== []) {
            $number++;
            $chapters[] = new Chapter(
                $number,
                $this->t('report.chapter_vmware'),
                $this->vmwareSections($number, $vcenters, $sourceIds, $sections)
            );
        }

        if ($switches !== [] && array_intersect(self::FC_SECTIONS, $sections) !== []) {
            $number++;
            $chapters[] = new Chapter(
                $number,
                $this->t('report.chapter_fc'),
                $this->fcSections($number, $switches, $sourceIds, $sections)
            );
        }

        $generatedAt = gmdate('Y-m-d H:i') . ' UTC';

        $titleFacts = [];

        if ($project !== null && $project->client !== null) {
            $titleFacts[] = [$this->t('projects.client'), $project->client];
        }

        if ($project !== null && $project->description !== null) {
            $titleFacts[] = [$this->t('projects.description'), $project->description];
        }

        $sourceNames = array_merge(
            array_map(static fn (array $row): string => (string) $row['name'], $vcenters),
            array_map(static fn (array $row): string => (string) $row['name'], $switches),
        );

        $titleFacts[] = [$this->t('projects.sources'), implode(', ', $sourceNames)];
        $titleFacts[] = [$this->t('report.generated_at'), $generatedAt];

        return new Document(
            title: $project?->name ?? $this->t('report.title_adhoc'),
            subtitle: $this->t('report.document_subtitle'),
            titleFacts: $titleFacts,
            chapters: $chapters,
            locale: $this->translator->locale(),
            contentsHeading: $this->t('report.contents'),
            contentsHint: $this->t('report.contents_hint'),
            generatedAt: $generatedAt,
            footer: $this->t('report.footer'),
        );
    }

    // =================================================================
    // Chapter 1 — the VMware environment
    // =================================================================

    /**
     * @param list<array<string, mixed>> $vcenters
     * @param list<int>                  $sourceIds
     * @param list<string>               $sections
     *
     * @return list<Section>
     */
    private function vmwareSections(int $chapter, array $vcenters, array $sourceIds, array $sections): array
    {
        // Only the vCenters, so a project holding a switch as well does not
        // make the switch's empty vCenter tables appear in chapter 1.
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $vcenters);

        $clusters    = in_array('clusters', $sections, true) ? $this->groupBySource($this->query->clusters($ids)) : [];
        $hosts       = in_array('hosts', $sections, true) || in_array('host_devices', $sections, true)
            ? $this->groupBySource($this->query->hosts($ids))
            : [];
        $devices     = in_array('host_devices', $sections, true) ? $this->query->hostDevices($ids) : [];
        $datastores  = in_array('datastores', $sections, true) ? $this->groupBySource($this->query->datastores($ids)) : [];
        $vmTotals    = in_array('vms', $sections, true) ? $this->query->vmTotals($ids) : [];
        $vmStates    = in_array('vms', $sections, true) ? $this->query->vmPowerStates($ids) : [];
        $licenses    = in_array('licenses', $sections, true) ? $this->groupBySource($this->query->licenses($ids)) : [];

        $result = [];
        $index  = 0;

        foreach ($vcenters as $source) {
            $id = (int) $source['id'];
            $index++;

            $number = $chapter . '.' . $index;
            $sub    = 0;

            $result[] = new Section(2, $number, (string) $source['name'], [
                Block::facts([
                    $this->t('integrations.address')        => (string) $source['hostname'] . ':' . (int) $source['port'],
                    $this->t('integrations.version')        => $this->orNull($source['product_version']),
                    $this->t('overview.last_collection')    => $this->collectedAt($source['last_success_at']),
                ]),
            ]);

            if (in_array('clusters', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('clusters'), [
                    $this->clusterTable($clusters[$id] ?? []),
                ]);
            }

            if (in_array('hosts', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('hosts'), [
                    $this->hostTable($hosts[$id] ?? []),
                ]);
            }

            if (in_array('host_devices', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('host_devices'),
                    $this->deviceBlocks($hosts[$id] ?? [], $devices[$id] ?? [])
                );
            }

            if (in_array('datastores', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('datastores'), [
                    $this->datastoreTable($datastores[$id] ?? []),
                ]);
            }

            if (in_array('vms', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('vms'),
                    $this->vmBlocks($vmTotals[$id] ?? [], $vmStates[$id] ?? [])
                );
            }

            if (in_array('licenses', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('licenses'), [
                    $this->licenseTable($licenses[$id] ?? []),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function clusterTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.nothing'));
        }

        $yes = $this->t('common.yes');
        $no  = $this->t('common.no');

        return Block::table(
            [
                $this->t('common.name'),
                $this->t('clusters.datacenter'),
                $this->t('clusters.ha'),
                $this->t('clusters.drs'),
                $this->t('nav.hosts'),
                $this->t('nav.vms'),
            ],
            array_map(
                static fn (array $row): array => [
                    (string) $row['name'],
                    (string) ($row['datacenter_name'] ?? '—'),
                    Format::flag($row['ha_enabled'], $yes, $no),
                    Format::flag($row['drs_enabled'], $yes, $no)
                        . ($row['drs_behavior'] !== null ? ' (' . $row['drs_behavior'] . ')' : ''),
                    Format::number($row['host_count']),
                    Format::number($row['vm_count']),
                ],
                $rows
            )
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hostTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.nothing'));
        }

        $standalone = $this->t('hosts.standalone');

        return Block::table(
            [
                $this->t('common.name'),
                $this->t('common.cluster'),
                $this->t('hosts.state'),
                $this->t('hosts.hardware'),
                $this->t('hosts.serial'),
                $this->t('hosts.cpu_model'),
                $this->t('hosts.cpu'),
                $this->t('hosts.memory'),
                $this->t('hosts.esxi'),
            ],
            array_map(
                function (array $row) use ($standalone): array {
                    $state = Format::state($row['connection_state']);

                    // Maintenance mode is a different question from whether
                    // the host is connected, and a report that shows only
                    // "connected" for a host nobody is allowed to place a
                    // machine on is misleading.
                    if ((int) $row['in_maintenance'] === 1) {
                        $state .= ' · ' . $this->t('hosts.maintenance');
                    }

                    return [
                        (string) $row['name'],
                        $row['cluster_name'] !== null ? (string) $row['cluster_name'] : $standalone,
                        $state,
                        trim(((string) ($row['vendor'] ?? '')) . ' ' . ((string) ($row['model'] ?? ''))) ?: '—',
                        $this->orDash($row['serial_number']),
                        $this->orDash($row['cpu_model']),
                        Format::number($row['cpu_cores']) . ' / ' . Format::number($row['cpu_sockets']),
                        Format::bytes($row['memory_bytes']),
                        $this->orDash($row['esxi_version'])
                            . ($row['esxi_build'] !== null ? ' (' . $row['esxi_build'] . ')' : ''),
                    ];
                },
                $rows
            ),
            note: $this->t('report.hosts_note')
        );
    }

    /**
     * One table per host per device kind.
     *
     * Detailed on purpose: this is the part of the document that answers
     * which card is in which host and which WWPN belongs to which adapter,
     * and a summary cannot answer either.
     *
     * @param list<array<string, mixed>>                              $hosts
     * @param array<string, array<string, list<array<string, mixed>>>> $devices host moref -> kind -> rows
     *
     * @return list<Block>
     */
    private function deviceBlocks(array $hosts, array $devices): array
    {
        if ($hosts === [] || $devices === []) {
            return [Block::nothing($this->t('devices.none'))];
        }

        $blocks = [];

        foreach ($hosts as $host) {
            $moref    = (string) $host['moref'];
            $byKind   = $devices[$moref] ?? [];
            $hostName = (string) $host['name'];

            if ($byKind === []) {
                $blocks[] = Block::paragraph($hostName . ' — ' . $this->t('devices.none'));

                continue;
            }

            foreach (self::DEVICE_KINDS as $kind => $headingKey) {
                $rows = $byKind[$kind] ?? [];

                if ($rows === []) {
                    continue;
                }

                [$headings, $cells] = $this->deviceColumns($kind, $rows);

                $blocks[] = Block::table(
                    $headings,
                    $cells,
                    caption: $hostName . ' — ' . $this->t($headingKey)
                );
            }
        }

        return $blocks;
    }

    /**
     * The columns one kind of device is worth printing.
     *
     * Each kind carries its own fields — the table is a union of columns in
     * which most are NULL on any given row — so a single table of everything
     * would be mostly empty cells. One shape per kind instead.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function deviceColumns(string $kind, array $rows): array
    {
        $noVlan = $this->t('devices.no_vlan');
        $trunk  = $this->t('devices.trunk');
        $down   = $this->t('devices.link_down');

        return match ($kind) {
            'pnic' => [
                [$this->t('devices.device'), $this->t('devices.mac'), $this->t('devices.speed'),
                 $this->t('devices.driver'), $this->t('devices.firmware'), $this->t('devices.pci')],
                array_map(
                    fn (array $row): array => [
                        (string) $row['device'],
                        $this->orDash($row['mac']),

                        // speed_mb is absent while the link is down, which is
                        // worth saying rather than leaving blank.
                        $row['speed_mb'] !== null
                            ? Format::number($row['speed_mb']) . ' Mb/s'
                            : $down,
                        trim($this->orEmpty($row['driver']) . ' ' . $this->orEmpty($row['driver_version'])) ?: '—',
                        $this->orDash($row['firmware_version']),
                        $this->orDash($row['pci']),
                    ],
                    $rows
                ),
            ],

            'vmk' => [
                [$this->t('devices.device'), $this->t('devices.port_group'), $this->t('vms.address'),
                 $this->t('devices.subnet'), $this->t('devices.gateway'), $this->t('devices.mtu'),
                 $this->t('devices.mac')],
                array_map(
                    fn (array $row): array => [
                        (string) $row['device'],
                        $this->orDash($row['port_group']),
                        $this->orDash($row['ip_address']),
                        $this->orDash($row['subnet_mask']),
                        $this->orDash($row['gateway']),
                        $this->orDash($row['mtu']),
                        $this->orDash($row['mac']),
                    ],
                    $rows
                ),
            ],

            'hba' => [
                [$this->t('devices.device'), $this->t('hosts.model'), $this->t('devices.type'),
                 $this->t('common.status'), $this->t('devices.driver'), $this->t('san.wwpn'),
                 $this->t('san.wwnn'), $this->t('devices.pci')],
                array_map(
                    fn (array $row): array => [
                        (string) $row['device'],
                        $this->orDash($row['model']),
                        $this->orDash($row['hba_type']),
                        $row['status'] !== null ? Format::state($row['status']) : '—',
                        $this->orDash($row['driver']),

                        // An iSCSI adapter has an iqn and no WWPN, and a SAS
                        // one has neither. Whichever identifier exists is the
                        // one worth printing in that column.
                        $this->orDash($row['wwpn'] ?? $row['iscsi_name']),
                        $this->orDash($row['wwnn']),
                        $this->orDash($row['pci']),
                    ],
                    $rows
                ),
            ],

            'vswitch' => [
                [$this->t('devices.vswitch_single'), $this->t('devices.ports'),
                 $this->t('devices.ports_free'), $this->t('devices.mtu')],
                array_map(
                    fn (array $row): array => [
                        (string) $row['device'],
                        $this->orDash($row['num_ports']),
                        $this->orDash($row['num_ports_available']),
                        $this->orDash($row['mtu']),
                    ],
                    $rows
                ),
            ],

            default => [
                [$this->t('devices.port_group'), $this->t('devices.vswitch_single'), $this->t('devices.vlan')],
                array_map(
                    static function (array $row) use ($noVlan, $trunk): array {
                        $vlan = $row['vlan_id'] === null ? '—' : (int) $row['vlan_id'];

                        // 0 means no VLAN and 4095 means every VLAN. Printed
                        // as the numbers they are stored as, nobody reading
                        // the document would know which was which.
                        if ($vlan === 0) {
                            $vlan = $noVlan;
                        } elseif ($vlan === 4095) {
                            $vlan = $trunk;
                        }

                        return [
                            (string) $row['device'],
                            (string) ($row['vswitch_name'] ?? '—'),
                            (string) $vlan,
                        ];
                    },
                    $rows
                ),
            ],
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function datastoreTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.nothing'));
        }

        return Block::table(
            [
                $this->t('common.name'),
                $this->t('datastores.type'),
                $this->t('datastores.capacity'),
                $this->t('datastores.used'),
                $this->t('datastores.free'),
                $this->t('datastores.uncommitted'),
                $this->t('nav.hosts'),
                $this->t('nav.vms'),
            ],
            array_map(
                function (array $row): array {
                    $percent = Format::usedPercent($row['capacity_bytes'], $row['free_bytes']);
                    $used    = $row['capacity_bytes'] !== null && $row['free_bytes'] !== null
                        ? Format::bytes((int) $row['capacity_bytes'] - (int) $row['free_bytes'])
                        : '—';

                    return [
                        (string) $row['name']
                            . ((int) $row['is_accessible'] === 0 ? ' (' . $this->t('datastores.inaccessible') . ')' : ''),
                        $this->orDash($row['type']),
                        Format::bytes($row['capacity_bytes']),
                        $used . ($percent !== null ? ' (' . $percent . '%)' : ''),
                        Format::bytes($row['free_bytes']),
                        Format::bytes($row['uncommitted_bytes']),
                        Format::number($row['host_count']),
                        Format::number($row['vm_count']),
                    ];
                },
                $rows
            )
        );
    }

    /**
     * The machine summary: totals, then a count per power state.
     *
     * @param array<string, int> $totals
     * @param array<string, int> $states
     *
     * @return list<Block>
     */
    private function vmBlocks(array $totals, array $states): array
    {
        if ($totals === [] || ($totals['all_count'] ?? 0) === 0) {
            return [Block::nothing($this->t('report.nothing'))];
        }

        $blocks = [
            Block::facts([
                $this->t('report.machines')      => Format::number($totals['machines'] ?? 0),
                $this->t('report.templates')     => Format::number($totals['templates'] ?? 0),
                $this->t('report.total_vcpu')    => Format::number($totals['vcpu'] ?? 0),
                $this->t('report.total_memory')  => Format::mib($totals['memory_mib'] ?? 0),
                $this->t('report.total_disk')    => Format::bytes($totals['disk_bytes'] ?? 0),
                $this->t('vms.disks')            => Format::number($totals['disks'] ?? 0),
                $this->t('vms.nics')             => Format::number($totals['nics'] ?? 0),
            ]),
        ];

        if ($states !== []) {
            $blocks[] = Block::table(
                [$this->t('vms.power'), $this->t('report.count')],
                array_map(
                    static fn (string $state, int $count): array => [
                        Format::state($state),
                        Format::number($count),
                    ],
                    array_keys($states),
                    array_values($states)
                )
            );
        }

        $blocks[] = Block::paragraph($this->t('report.vms_note'));

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function licenseTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.nothing'));
        }

        $unlimited = $this->t('licenses.unlimited');
        $perpetual = $this->t('licenses.perpetual');

        return Block::table(
            [
                $this->t('licenses.product'),
                $this->t('common.name'),
                $this->t('licenses.key'),
                $this->t('licenses.used'),
                $this->t('licenses.total'),
                $this->t('licenses.unit'),
                $this->t('licenses.expires'),
            ],
            array_map(
                static fn (array $row): array => [
                    (string) ($row['product_name'] ?? '—'),
                    (string) $row['name'],

                    // Only the last block of a key is stored; see the note
                    // under the licences page.
                    (string) ($row['edition_key'] ?? '—'),
                    Format::number($row['used_units']),
                    (int) ($row['total_units'] ?? 0) === 0 ? $unlimited : Format::number($row['total_units']),
                    (string) ($row['cost_unit'] ?? '—'),
                    $row['expires_at'] !== null ? (string) $row['expires_at'] : $perpetual,
                ],
                $rows
            ),
            note: $this->t('licenses.masked_note')
        );
    }

    // =================================================================
    // Chapter 2 — the Fibre Channel environment
    // =================================================================

    /**
     * @param list<array<string, mixed>> $switchSources
     * @param list<int>                  $sourceIds
     * @param list<string>               $sections
     *
     * @return list<Section>
     */
    private function fcSections(int $chapter, array $switchSources, array $sourceIds, array $sections): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $switchSources);

        $switches  = $this->groupBySource($this->query->fcSwitches($ids));
        $members   = in_array('fabric', $sections, true) ? $this->query->fcFabricMembers($ids) : [];
        $counts    = in_array('ports', $sections, true) ? $this->query->fcPortCounts($ids) : [];
        $ports     = in_array('ports', $sections, true) ? $this->query->fcActivePorts($ids) : [];
        $isls      = in_array('isls', $sections, true) ? $this->query->fcIsls($ids) : [];
        $devices   = in_array('fc_devices', $sections, true) ? $this->query->fcDevices($ids) : [];

        // The defined configuration, not the effective one. A defined zone
        // lists the alias names somebody wrote, which is what a reader
        // recognises and what they would type again; the effective one lists
        // the WWPNs those aliases resolved to, and the alias table below
        // gives that mapping anyway. Both are stored, so the other section
        // is a one-line change if it is ever wanted.
        $zoning = in_array('zoning', $sections, true) ? $this->query->fcZoning($ids, 'defined') : [];

        $result = [];
        $index  = 0;

        foreach ($switchSources as $source) {
            $id = (int) $source['id'];
            $index++;

            $number = $chapter . '.' . $index;
            $sub    = 0;

            // One integration is one switch, so there is at most one row.
            $switch = ($switches[$id] ?? [])[0] ?? null;

            if ($switch === null) {
                $result[] = new Section(2, $number, (string) $source['name'], [
                    Block::facts([
                        $this->t('integrations.address')     => (string) $source['hostname'] . ':' . (int) $source['port'],
                        $this->t('overview.last_collection') => $this->collectedAt($source['last_success_at']),
                    ]),
                    Block::nothing($this->t('report.nothing')),
                ]);

                continue;
            }

            $result[] = new Section(2, $number, (string) $switch['name'], $this->switchBlocks($source, $switch));

            if (in_array('fabric', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('fabric'),
                    $this->fabricBlocks($switch, $members[$id] ?? [])
                );
            }

            if (in_array('ports', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('ports'),
                    $this->portBlocks($counts[$id] ?? [], $ports[$id] ?? [])
                );
            }

            if (in_array('isls', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('isls'), [
                    $this->islTable($isls[$id] ?? []),
                ]);
            }

            if (in_array('fc_devices', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('fc_devices'), [
                    $this->fcDeviceTable($devices[$id] ?? []),
                ]);
            }

            if (in_array('zoning', $sections, true)) {
                $result[] = new Section(3, $number . '.' . ++$sub, $this->sectionHeading('zoning'),
                    $this->zoningBlocks($switch, $zoning[$id] ?? [])
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $switch
     *
     * @return list<Block>
     */
    private function switchBlocks(array $source, array $switch): array
    {
        $yes = $this->t('common.yes');
        $no  = $this->t('common.no');

        return [
            Block::facts([
                $this->t('integrations.address')      => (string) $source['hostname'] . ':' . (int) $source['port'],
                $this->t('overview.last_collection')  => $this->collectedAt($source['last_success_at']),
                $this->t('san.wwn')                   => $this->orDash($switch['switch_wwn']),
                $this->t('san.domain')                => $this->orDash($switch['domain_id']),
                $this->t('san.switch_id')             => $this->orDash($switch['switch_id']),
                $this->t('san.switch_type')           => $this->orDash($switch['switch_type']),
                $this->t('common.status')              => $this->orDash($switch['state']),
                $this->t('san.mode')                  => $this->orDash($switch['switch_mode']),
                $this->t('san.role')                  => $this->orDash($switch['switch_role']),
                $this->t('san.fos')                   => $this->orDash($switch['fos_version']),
                $this->t('san.zoning_on')             => Format::flag($switch['zoning_enabled'], $yes, $no),
                $this->t('san.zone_config')           => $this->orDash($switch['effective_config']),
            ], caption: $this->t('san.identity')),

            Block::facts([
                $this->t('san.serial')      => $this->orDash($switch['serial_number']),
                $this->t('san.oem_serial')  => $this->orDash($switch['oem_serial_number']),
                $this->t('san.part')        => $this->orDash($switch['part_number']),
                $this->t('san.oem_id')      => $this->orDash($switch['oem_id']),
                $this->t('san.port_count')  => $this->orDash($switch['port_count']),
                $this->t('san.licensed')    => $this->orDash($switch['licensed_port_count']),
            ], caption: $this->t('san.hardware')),
        ];
    }

    /**
     * @param array<string, mixed>       $switch
     * @param list<array<string, mixed>> $members
     *
     * @return list<Block>
     */
    private function fabricBlocks(array $switch, array $members): array
    {
        $blocks = [
            Block::facts([
                $this->t('san.fabric_name')  => $switch['fabric_name'] !== null
                    ? (string) $switch['fabric_name']
                    : $this->t('san.fabric_unnamed'),
                $this->t('san.principal')    => $this->orDash($switch['principal_wwn']),
                $this->t('san.members')      => Format::number($switch['fabric_member_count']),
            ]),
        ];

        if ($members === []) {
            $blocks[] = Block::nothing($this->t('report.nothing'));

            return $blocks;
        }

        $yes  = $this->t('common.yes');
        $no   = $this->t('common.no');
        $self = $this->t('san.this_switch');

        $blocks[] = Block::table(
            [
                $this->t('san.domain'),
                $this->t('common.name'),
                $this->t('san.wwn'),
                $this->t('san.management_ip'),
                $this->t('san.principal'),
            ],
            array_map(
                static fn (array $row): array => [
                    (string) ($row['domain_id'] ?? '—'),
                    (string) ($row['switch_name'] ?? '—')
                        . ((int) $row['is_local'] === 1 ? ' (' . $self . ')' : ''),
                    (string) ($row['switch_wwn'] ?? '—'),
                    (string) ($row['ip_address'] ?? '—'),
                    Format::flag($row['is_principal'], $yes, $no),
                ],
                $members
            )
        );

        return $blocks;
    }

    /**
     * @param array{present?: int, licensed?: int, in_use?: int} $counts
     * @param list<array<string, mixed>>                         $ports
     *
     * @return list<Block>
     */
    private function portBlocks(array $counts, array $ports): array
    {
        $blocks = [
            Block::facts([
                $this->t('san.port_count') => Format::number($counts['present'] ?? 0),
                $this->t('san.licensed')   => Format::number($counts['licensed'] ?? 0),
                $this->t('report.in_use')  => Format::number($counts['in_use'] ?? 0),
            ]),
        ];

        if ($ports === []) {
            $blocks[] = Block::nothing($this->t('report.no_active_ports'));

            return $blocks;
        }

        $blocks[] = Block::table(
            [
                $this->t('san.port'),
                $this->t('san.port_name'),
                $this->t('san.port_id'),
                $this->t('san.speed'),
                $this->t('san.port_type'),
                $this->t('common.status'),
                $this->t('san.attached'),
                $this->t('san.hba'),
            ],
            array_map(
                function (array $row): array {
                    // Whatever the device registered about itself. Kept
                    // exactly as sent — the format differs by vendor and
                    // parsing it per vendor rots silently.
                    $hba = trim($this->orEmpty($row['node_symb']) . ' ' . $this->orEmpty($row['port_symb']));

                    $name = (string) ($row['port_name'] ?? '');

                    // A default port name is the switch's own placeholder and
                    // says nothing; blank reads better in a document.
                    if ((int) ($row['name_is_default'] ?? 0) === 1) {
                        $name = '';
                    }

                    return [
                        (string) $row['port_index']
                            . ((int) ($row['is_licensed'] ?? 1) === 0 ? ' (' . $this->t('san.unlicensed') . ')' : ''),
                        $name !== '' ? $name : '—',
                        $this->orDash($row['fcid']),
                        $row['speed_gb'] !== null ? $row['speed_gb'] . ' Gb/s' : '—',
                        $this->orDash($row['port_type']),
                        $row['state'] !== null ? Format::state($row['state']) : '—',
                        $this->orDash($row['attached_wwpn']),
                        $hba !== '' ? $hba : '—',
                    ];
                },
                $ports
            ),
            note: $this->t('report.ports_note')
        );

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function islTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.no_isls'));
        }

        return Block::table(
            [
                $this->t('san.local_port'),
                $this->t('san.remote_port'),
                $this->t('san.remote_switch'),
                $this->t('san.domain'),
                $this->t('san.speed'),
                $this->t('san.bandwidth'),
                $this->t('san.trunk'),
                $this->t('san.flags'),
            ],
            array_map(
                function (array $row): array {
                    $trunk = (int) ($row['is_trunk'] ?? 0) === 1
                        ? $this->t('san.trunk_members', [
                            'count'  => (int) ($row['member_count'] ?? 0),
                            'ports'  => (string) ($row['member_ports'] ?? ''),
                        ])
                        : '—';

                    return [
                        (string) $row['local_port'],
                        (string) ($row['remote_port'] ?? '—'),
                        (string) ($row['remote_switch_name'] ?? $row['remote_switch_wwn'] ?? '—'),
                        (string) ($row['remote_domain_id'] ?? '—'),
                        $row['speed_gb'] !== null ? $row['speed_gb'] . ' Gb/s' : '—',
                        $row['bandwidth_gb'] !== null ? $row['bandwidth_gb'] . ' Gb/s' : '—',
                        $trunk,
                        $this->orDash($row['flags']),
                    ];
                },
                $rows
            )
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fcDeviceTable(array $rows): Block
    {
        if ($rows === []) {
            return Block::nothing($this->t('report.nothing'));
        }

        return Block::table(
            [
                $this->t('san.port'),
                $this->t('san.port_name'),
                $this->t('san.wwpn'),
                $this->t('san.wwnn'),
                $this->t('san.device_type'),
                $this->t('san.link_speed'),
                $this->t('san.hba'),
            ],
            array_map(
                function (array $row): array {
                    $hba = trim($this->orEmpty($row['node_symb']) . ' ' . $this->orEmpty($row['port_symb']));

                    return [
                        (string) ($row['port_index'] ?? '—'),
                        $this->orDash($row['port_name']),
                        (string) $row['wwpn'],
                        $this->orDash($row['wwnn']),
                        $this->orDash($row['device_type']),
                        $this->orDash($row['link_speed']),
                        $hba !== '' ? $hba : '—',
                    ];
                },
                $rows
            ),
            note: $this->t('san.devices_note')
        );
    }

    /**
     * Zoning: the configurations, the zones with their members, the aliases.
     *
     * @param array<string, mixed>       $switch
     * @param list<array<string, mixed>> $objects each with a 'members' list
     *
     * @return list<Block>
     */
    private function zoningBlocks(array $switch, array $objects): array
    {
        if ($objects === []) {
            return [Block::nothing($this->t('zoning.none'))];
        }

        $yes = $this->t('common.yes');
        $no  = $this->t('common.no');

        $blocks = [
            Block::facts([
                $this->t('san.zoning_on')   => Format::flag($switch['zoning_enabled'], $yes, $no),
                $this->t('san.zone_config') => $this->orDash($switch['effective_config']),
            ]),
        ];

        $byKind = ['cfg' => [], 'zone' => [], 'alias' => []];

        foreach ($objects as $object) {
            $byKind[(string) $object['kind']][] = $object;
        }

        foreach (['cfg' => 'zoning.kind_cfg', 'zone' => 'zoning.kind_zone', 'alias' => 'zoning.kind_alias'] as $kind => $key) {
            if ($byKind[$kind] === []) {
                continue;
            }

            $blocks[] = Block::table(
                [$this->t('common.name'), $this->t('zoning.members')],
                array_map(
                    static fn (array $object): array => [
                        (string) $object['name'],

                        // One member per line inside the cell. A zone of
                        // eight WWPNs across one line is unreadable in print
                        // and the writers turn a newline into a line break.
                        implode("\n", array_map(
                            static fn (array $member): string => (string) $member['member_name'],
                            $object['members']
                        )),
                    ],
                    $byKind[$kind]
                ),
                caption: $this->t($key)
            );
        }

        $blocks[] = Block::paragraph($this->t('report.zoning_note'));

        return $blocks;
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    /** The heading of one section, from the one list of them. */
    private function sectionHeading(string $section): string
    {
        return $this->t(self::SECTION_LABELS[$section]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->t($key, $params);
    }

    /**
     * Rows keyed by the source they came from.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupBySource(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['integration_id']][] = $row;
        }

        return $grouped;
    }

    /** A stored UTC timestamp, or "never". */
    private function collectedAt(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? $value . ' UTC'
            : $this->t('common.never');
    }

    private function orDash(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    private function orEmpty(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function orNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
