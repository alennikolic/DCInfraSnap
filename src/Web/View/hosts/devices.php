<?php
// src/Web/View/hosts/devices.php
//
// The device panels on a host page: VMkernel adapters, physical network
// cards, storage adapters, standard switches and port groups.
//
// Five kinds share one table, so each gets its own panel with only the
// columns that mean something for it. A single table with every column would
// be mostly empty cells.
//
// Included by hosts/show.php, so $devices and the helpers are already in
// scope.

/** @var callable $e */
/** @var callable $t */
/** @var array<string, list<array<string,mixed>>> $devices */

use DcInfraSnap\Web\Format;

$speed = static function (mixed $mb) use ($e): string {
    if ($mb === null || !is_numeric($mb)) {
        // No link speed means the link is down — an accurate description
        // of the state, not missing data.
        return '<span class="muted">—</span>';
    }

    $mb = (int) $mb;

    return '<span class="data">'
        . $e($mb >= 1000 ? intdiv($mb, 1000) . ' Gb/s' : $mb . ' Mb/s')
        . '</span>';
};

$vlan = static function (mixed $id) use ($e, $t): string {
    if ($id === null || $id === '') {
        return '<span class="muted">—</span>';
    }

    $id = (int) $id;

    return match (true) {
        $id === 0    => '<span class="muted">' . $e($t('devices.no_vlan')) . '</span>',
        $id === 4095 => '<span class="badge badge-idle">' . $e($t('devices.trunk')) . '</span>',
        default      => '<span class="data">' . $e($id) . '</span>',
    };
};

$cell = static fn (mixed $v): string => $v === null || $v === ''
    ? '<span class="muted">—</span>'
    : '<span class="data">' . htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';

$total = array_sum(array_map('count', $devices));
?>

<div class="panel">
    <h2><?= $e($t('devices.title')) ?></h2>

    <?php if ($total === 0): ?>

        <p class="muted"><?= $e($t('devices.none')) ?></p>

    <?php else: ?>

        <?php // --- VMkernel adapters ------------------------------------ ?>
        <?php if (($devices['vmk'] ?? []) !== []): ?>
            <h3 class="device-head"><?= $e($t('devices.vmk')) ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?= $e($t('devices.device')) ?></th>
                        <th><?= $e($t('devices.port_group')) ?></th>
                        <th><?= $e($t('vms.address')) ?></th>
                        <th><?= $e($t('devices.subnet')) ?></th>
                        <th><?= $e($t('devices.gateway')) ?></th>
                        <th><?= $e($t('devices.mtu')) ?></th>
                        <th><?= $e($t('devices.mac')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices['vmk'] as $d): ?>
                    <tr>
                        <td class="data"><strong><?= $e($d['device']) ?></strong></td>
                        <td><?= $e($d['port_group'] ?? '—') ?></td>
                        <td>
                            <?= $cell($d['ip_address']) ?>
                            <?php if ((int) ($d['dhcp'] ?? 0) === 1): ?>
                                <span class="badge badge-idle">DHCP</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $cell($d['subnet_mask']) ?></td>
                        <td><?= $cell($d['gateway']) ?></td>
                        <td><?= $cell($d['mtu']) ?></td>
                        <td><?= $cell($d['mac']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php // --- Physical network cards -------------------------------- ?>
        <?php if (($devices['pnic'] ?? []) !== []): ?>
            <h3 class="device-head"><?= $e($t('devices.pnic')) ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?= $e($t('devices.device')) ?></th>
                        <th><?= $e($t('devices.speed')) ?></th>
                        <th><?= $e($t('devices.mac')) ?></th>
                        <th><?= $e($t('devices.driver')) ?></th>
                        <th><?= $e($t('devices.firmware')) ?></th>
                        <th><?= $e($t('devices.pci')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices['pnic'] as $d): ?>
                    <tr>
                        <td class="data">
                            <strong><?= $e($d['device']) ?></strong>
                            <?php if ($d['speed_mb'] === null): ?>
                                <span class="badge badge-idle"><?= $e($t('devices.link_down')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $speed($d['speed_mb']) ?></td>
                        <td><?= $cell($d['mac']) ?></td>
                        <td>
                            <?= $cell($d['driver']) ?>
                            <?php if (($d['driver_version'] ?? '') !== ''): ?>
                                <span class="muted"><?= $e($d['driver_version']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $cell($d['firmware_version']) ?></td>
                        <td><?= $cell($d['pci']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php // --- Storage adapters -------------------------------------- ?>
        <?php if (($devices['hba'] ?? []) !== []): ?>
            <h3 class="device-head"><?= $e($t('devices.hba')) ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?= $e($t('devices.device')) ?></th>
                        <th><?= $e($t('devices.type')) ?></th>
                        <th><?= $e($t('hosts.model')) ?></th>
                        <th><?= $e($t('common.status')) ?></th>
                        <th><?= $e($t('devices.identifier')) ?></th>
                        <th><?= $e($t('devices.driver')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices['hba'] as $d): ?>
                    <tr>
                        <td class="data"><strong><?= $e($d['device']) ?></strong></td>
                        <td><?= $e($d['hba_type'] ?? '—') ?></td>
                        <td><?= $e($d['model'] ?? '—') ?></td>
                        <td>
                            <?php $ok = ($d['status'] ?? '') === 'online'; ?>
                            <span class="badge <?= $ok ? 'badge-ok' : 'badge-idle' ?>">
                                <?= $e($d['status'] ?? '—') ?>
                            </span>
                        </td>
                        <td>
                            <?php if (($d['wwpn'] ?? '') !== ''): ?>
                                <div><span class="muted">WWPN</span> <?= $cell($d['wwpn']) ?></div>
                                <div><span class="muted">WWNN</span> <?= $cell($d['wwnn']) ?></div>
                            <?php elseif (($d['iscsi_name'] ?? '') !== ''): ?>
                                <?= $cell($d['iscsi_name']) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $cell($d['driver']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php // --- Standard switches ------------------------------------- ?>
        <?php if (($devices['vswitch'] ?? []) !== []): ?>
            <h3 class="device-head"><?= $e($t('devices.vswitch')) ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?= $e($t('devices.device')) ?></th>
                        <th><?= $e($t('devices.ports')) ?></th>
                        <th><?= $e($t('devices.ports_free')) ?></th>
                        <th><?= $e($t('devices.mtu')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices['vswitch'] as $d): ?>
                    <tr>
                        <td class="data"><strong><?= $e($d['device']) ?></strong></td>
                        <td class="data"><?= $e(Format::number($d['num_ports'])) ?></td>
                        <td class="data"><?= $e(Format::number($d['num_ports_available'])) ?></td>
                        <td><?= $cell($d['mtu']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php // --- Port groups ------------------------------------------- ?>
        <?php if (($devices['portgroup'] ?? []) !== []): ?>
            <h3 class="device-head"><?= $e($t('devices.portgroup')) ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?= $e($t('common.name')) ?></th>
                        <th><?= $e($t('devices.vlan')) ?></th>
                        <th><?= $e($t('devices.vswitch_single')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices['portgroup'] as $d): ?>
                    <tr>
                        <td><strong><?= $e($d['device']) ?></strong></td>
                        <td><?= $vlan($d['vlan_id']) ?></td>
                        <td class="data"><?= $e($d['vswitch_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>
</div>
