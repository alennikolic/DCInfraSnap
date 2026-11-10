<?php
// src/Web/View/hosts/show.php
//
// One host: its hardware, what it is running, and what has changed about it.

/** @var callable $e */
/** @var callable $t */
/** @var array<string,mixed> $host */
/** @var list<array<string,mixed>> $vms */
/** @var list<array<string,mixed>> $changes */

use DcInfraSnap\Web\Format;

$rows = static function (array $pairs) use ($e): void {
    foreach ($pairs as $label => $value) {
        [$text, $mono] = is_array($value) ? $value : [$value, false];

        echo '<tr><th>' . $e($label) . '</th><td' . ($mono ? ' class="data"' : '') . '>'
            . ($text === null || $text === '' ? '<span class="muted">—</span>' : $e($text))
            . '</td></tr>';
    }
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($host['name']) ?></h1>
        <p>
            <?= $e(Format::state($host['connection_state'])) ?>
            &middot; <?= $e($host['cluster_name'] ?? $t('hosts.standalone')) ?>
            &middot; <?= $e($host['integration_name']) ?>
        </p>
    </div>

    <a href="/hosts" class="btn btn-inline">&larr; <?= $e($t('common.back')) ?></a>
</div>

<div class="detail-grid">
    <div class="panel">
        <h2><?= $e($t('hosts.hardware')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('hosts.vendor')    => $host['vendor'],
                $t('hosts.model')     => $host['model'],
                $t('hosts.serial')    => [$host['serial_number'], true],
                $t('hosts.asset_tag') => [$host['asset_tag'], true],
                $t('hosts.hw_uuid')   => [$host['hardware_uuid'], true],
                $t('hosts.bios')      => $host['bios_version'],
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('hosts.capacity')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('hosts.cpu_model') => $host['cpu_model'],
                $t('hosts.sockets')   => Format::number($host['cpu_sockets']),
                $t('hosts.cores')     => Format::number($host['cpu_cores']),
                $t('hosts.threads')   => Format::number($host['cpu_threads']),
                $t('hosts.cpu_mhz')   => Format::number($host['cpu_mhz']),
                $t('hosts.memory')    => Format::bytes($host['memory_bytes']),
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('hosts.software')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('hosts.esxi')        => $host['esxi_version'],
                $t('hosts.build')       => [$host['esxi_build'], true],
                $t('hosts.uptime')      => Format::duration($host['uptime_seconds']),
                $t('hosts.maintenance') => Format::flag($host['in_maintenance']),
                $t('list.first_seen')   => $host['first_seen'] . ' UTC',
                $t('list.last_seen')    => $host['last_seen'] . ' UTC',
            ]); ?>
        </table>
    </div>
</div>

<div class="panel">
    <h2><?= $e($t('hosts.running_vms', ['count' => count($vms)])) ?></h2>

    <?php if ($vms === []): ?>
        <p class="muted"><?= $e($t('hosts.no_vms')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('vms.power')) ?></th>
                    <th><?= $e($t('vms.address')) ?></th>
                    <th><?= $e($t('vms.cpu')) ?></th>
                    <th><?= $e($t('vms.memory')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vms as $vm): ?>
                <tr>
                    <td><a href="/vms/<?= (int) $vm['id'] ?>"><?= $e($vm['name']) ?></a></td>
                    <td><?= $e(Format::state($vm['power_state'])) ?></td>
                    <td class="data"><?= $e($vm['guest_ip_address'] ?? '—') ?></td>
                    <td class="data"><?= $e(Format::number($vm['cpu_count'])) ?></td>
                    <td class="data"><?= $e(Format::mib($vm['memory_mib'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?= $e($t('journal.title')) ?></h2>

    <?php if ($changes === []): ?>
        <p class="muted"><?= $e($t('journal.empty')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('journal.when')) ?></th>
                    <th><?= $e($t('journal.field')) ?></th>
                    <th><?= $e($t('journal.from')) ?></th>
                    <th><?= $e($t('journal.to')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($changes as $change): ?>
                <?php
                $old = json_decode((string) $change['old_values'], true) ?: [];
                $new = json_decode((string) $change['new_values'], true) ?: [];
                $first = true;
                ?>
                <?php foreach ($new as $field => $after): ?>
                    <tr>
                        <td><?= $first ? $e($change['changed_at']) . ' UTC' : '' ?></td>
                        <td class="data"><?= $e($field) ?></td>
                        <td class="data old"><?= $e((string) ($old[$field] ?? '—')) ?></td>
                        <td class="data new"><?= $e((string) ($after ?? '—')) ?></td>
                    </tr>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
