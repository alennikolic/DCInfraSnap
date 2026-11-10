<?php
// src/Web/View/vms/show.php
//
// One machine: everything stored about it, and everything that has changed.
//
// The two operating system fields are shown side by side on purpose. One is
// what was configured in vCenter, the other what the guest actually reports.
// On neglected machines they drift apart, and the drift is the point.

/** @var callable $e */
/** @var callable $t */
/** @var array<string,mixed> $vm */
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
        <h1><?= $e($vm['name']) ?></h1>
        <p>
            <?= $e(Format::state($vm['power_state'])) ?>
            <?php if ((int) $vm['is_template'] === 1): ?>
                &middot; <?= $e($t('vms.template')) ?>
            <?php endif; ?>
            &middot; <?= $e($vm['integration_name']) ?>
        </p>
    </div>

    <a href="/vms" class="btn btn-inline">&larr; <?= $e($t('common.back')) ?></a>
</div>

<div class="detail-grid">

    <div class="panel">
        <h2><?= $e($t('vms.identity')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('vms.instance_uuid') => [$vm['instance_uuid'], true],
                $t('vms.bios_uuid')     => [$vm['bios_uuid'], true],
                $t('vms.moref')         => [$vm['moref'], true],
                $t('vms.guest_configured') => $vm['guest_os'],
                $t('vms.guest_reported')   => $vm['guest_full_name'],
                $t('vms.hardware_version') => $vm['hardware_version'],
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('vms.resources')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('vms.cpu')        => Format::number($vm['cpu_count']),
                $t('vms.cores_per_socket') => Format::number($vm['cpu_cores_per_socket']),
                $t('vms.memory')     => Format::mib($vm['memory_mib']),
                $t('vms.disks')      => Format::number($vm['disk_count']),
                $t('vms.disk_space') => Format::bytes($vm['disk_capacity_bytes']),
                $t('vms.nics')       => Format::number($vm['nic_count']),
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('vms.guest')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('vms.hostname')     => [$vm['guest_hostname'], true],
                $t('vms.address')      => [$vm['guest_ip_address'], true],
                $t('vms.guest_state')  => $vm['guest_state'],
                $t('vms.tools')        => $vm['guest_tools_status'],
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('vms.placement')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('nav.hosts')     => $vm['host_name'],
                $t('nav.clusters')  => $vm['cluster_name'],
                $t('vms.folder')    => [$vm['folder_path'], true],
                $t('list.first_seen') => $vm['first_seen'] . ' UTC',
                $t('list.last_seen')  => $vm['last_seen'] . ' UTC',
            ]); ?>
        </table>
    </div>
</div>

<?php if (($vm['notes'] ?? '') !== ''): ?>
    <div class="panel">
        <h2><?= $e($t('vms.notes')) ?></h2>
        <pre class="notes"><?= $e($vm['notes']) ?></pre>
    </div>
<?php endif; ?>

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
