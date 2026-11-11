<?php
// src/Web/View/san/show.php
//
// One Fibre Channel switch: what it is, what it is connected to, and what is
// logged in through it.
//
// The port table carries the name server's view of whatever is attached, so
// that "port 3 has a device" and "that device is a QLogic HBA in this host"
// are one line rather than two pages.

/** @var callable $e */
/** @var callable $t */
/** @var array<string,mixed> $switch */
/** @var list<array<string,mixed>> $members */
/** @var list<array<string,mixed>> $ports */
/** @var list<array<string,mixed>> $devices */
/** @var list<array<string,mixed>> $isls */
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

/** A port that is up, versus down, versus not licensed at all. */
$portClass = static function (array $port): string {
    if ((int) $port['is_licensed'] === 0) {
        return 'row-muted';
    }

    return $port['port_type'] === null ? '' : 'row-active';
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($switch['name']) ?></h1>
        <p>
            <?= $e($switch['state'] ?? '') ?>
            &middot; <?= $e($t('san.domain')) ?> <?= (int) $switch['domain_id'] ?>
            &middot; FOS <?= $e($switch['fos_version'] ?? '?') ?>
            &middot; <?= $e($switch['hostname']) ?>
        </p>
    </div>

    <a href="/san" class="btn btn-inline">&larr; <?= $e($t('common.back')) ?></a>
</div>

<div class="detail-grid">
    <div class="panel">
        <h2><?= $e($t('san.identity')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('san.wwn')         => [$switch['switch_wwn'], true],
                $t('san.switch_id')   => [$switch['switch_id'], true],
                $t('san.switch_type') => $switch['switch_type'],
                $t('san.mode')        => $switch['switch_mode'],
                $t('san.role')        => $switch['switch_role'],
                $t('san.vf_id')       => $switch['vf_id'],
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('san.fabric')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('san.fabric_name') => $switch['fabric_name'] ?? $t('san.fabric_unnamed'),
                $t('san.principal')   => [$switch['principal_wwn'], true],
                $t('san.members')     => Format::number($switch['fabric_member_count']),
                $t('san.zone_config') => $switch['effective_config'],
                $t('san.zoning_on')   => Format::flag(
                    $switch['zoning_enabled'],
                    $t('common.yes'),
                    $t('common.no')
                ),
            ]); ?>
        </table>
    </div>

    <div class="panel">
        <h2><?= $e($t('san.hardware')) ?></h2>
        <table class="table-detail">
            <?php $rows([
                $t('san.serial')     => [$switch['serial_number'], true],
                $t('san.oem_serial') => [$switch['oem_serial_number'], true],
                $t('san.part')       => [$switch['part_number'], true],
                $t('san.oem_id')     => [$switch['oem_id'], true],
                $t('san.port_count') => Format::number($switch['port_count']),
                $t('san.licensed')   => Format::number($switch['licensed_port_count']),
            ]); ?>
        </table>
    </div>
</div>

<!-- Fabric members ---------------------------------------------------- -->
<div class="panel">
    <h2><?= $e($t('san.members_title', ['count' => count($members)])) ?></h2>

    <table>
        <thead>
            <tr>
                <th><?= $e($t('san.domain')) ?></th>
                <th><?= $e($t('san.switch')) ?></th>
                <th><?= $e($t('san.wwn')) ?></th>
                <th><?= $e($t('san.management_ip')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $member): ?>
            <tr<?= (int) ($member['is_local'] ?? 0) === 1 ? ' class="row-active"' : '' ?>>
                <td class="data"><?= (int) $member['domain_id'] ?></td>
                <td>
                    <?= $e($member['switch_name'] ?? '—') ?>
                    <?php if ((int) ($member['is_local'] ?? 0) === 1): ?>
                        <span class="badge badge-idle"><?= $e($t('san.this_switch')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="data"><?= $e($member['switch_wwn']) ?></td>
                <td class="data"><?= $e($member['ip_address'] ?? '—') ?></td>
                <td>
                    <?php if ((int) ($member['is_principal'] ?? 0) === 1): ?>
                        <span class="badge badge-ok"><?= $e($t('san.principal')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Inter-switch links ----------------------------------------------- -->
<?php if ($isls !== []): ?>
    <div class="panel">
        <h2><?= $e($t('san.isls_title', ['count' => count($isls)])) ?></h2>

        <table>
            <thead>
                <tr>
                    <th><?= $e($t('san.local_port')) ?></th>
                    <th><?= $e($t('san.remote_switch')) ?></th>
                    <th><?= $e($t('san.remote_port')) ?></th>
                    <th><?= $e($t('san.speed')) ?></th>
                    <th><?= $e($t('san.bandwidth')) ?></th>
                    <th><?= $e($t('san.trunk')) ?></th>
                    <th><?= $e($t('san.flags')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($isls as $isl): ?>
                <tr>
                    <td class="data"><?= (int) $isl['local_port'] ?></td>
                    <td>
                        <?= $e($isl['remote_switch_name'] ?? '—') ?>
                        <div class="sub data"><?= $e($isl['remote_switch_wwn'] ?? '') ?></div>
                    </td>
                    <td class="data">
                        <?= $e($t('san.domain')) ?> <?= (int) $isl['remote_domain_id'] ?>
                        <span class="sep">/</span><?= (int) $isl['remote_port'] ?>
                    </td>
                    <td class="data"><?= $e($isl['speed_gb']) ?>G</td>
                    <td class="data"><?= $e($isl['bandwidth_gb']) ?>G</td>
                    <td class="data">
                        <?php if ((int) ($isl['is_trunk'] ?? 0) === 1): ?>
                            <?= $e($t('san.trunk_members', [
                                'count' => (string) (int) $isl['member_count'],
                                'ports' => (string) ($isl['member_ports'] ?? ''),
                            ])) ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="sub"><?= $e($isl['flags'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Ports ------------------------------------------------------------- -->
<div class="panel">
    <h2><?= $e($t('san.ports_title', ['count' => count($ports)])) ?></h2>
    <p class="note-block"><?= $e($t('san.ports_note')) ?></p>

    <table>
        <thead>
            <tr>
                <th><?= $e($t('san.port')) ?></th>
                <th><?= $e($t('san.port_id')) ?></th>
                <th><?= $e($t('san.port_name')) ?></th>
                <th><?= $e($t('common.status')) ?></th>
                <th><?= $e($t('san.speed')) ?></th>
                <th><?= $e($t('san.port_type')) ?></th>
                <th><?= $e($t('san.attached')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ports as $port): ?>
            <tr class="<?= $portClass($port) ?>">
                <td class="data"><?= (int) $port['port_index'] ?></td>
                <td class="data"><?= $e($port['fcid'] ?? '—') ?></td>
                <td>
                    <?php if ((int) $port['name_is_default'] === 1): ?>
                        <span class="muted"><?= $e($port['port_name'] ?? '—') ?></span>
                    <?php else: ?>
                        <?= $e($port['port_name']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $port['is_licensed'] === 0): ?>
                        <span class="badge badge-idle" title="<?= $e($t('san.unlicensed_hint')) ?>">
                            <?= $e($t('san.unlicensed')) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge <?= $port['port_type'] !== null ? 'badge-ok' : 'badge-idle' ?>">
                            <?= $e($port['state'] ?? '—') ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="data">
                    <?php if ($port['speed_gb'] !== null): ?>
                        <?= (int) $port['speed_gb'] ?>G
                        <span class="muted"><?= $e($port['speed_mode'] ?? '') ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $e($port['port_type'] ?? '—') ?></td>
                <td>
                    <?php if ($port['attached_wwpn'] !== null): ?>
                        <span class="data"><?= $e($port['attached_wwpn']) ?></span>
                        <?php if (($port['node_symb'] ?? null) !== null): ?>
                            <div class="sub"><?= $e($port['node_symb']) ?></div>
                        <?php endif; ?>
                        <?php if ($port['npiv_count'] !== null): ?>
                            <span class="badge badge-idle">NPIV &times;<?= (int) $port['npiv_count'] ?></span>
                        <?php endif; ?>
                    <?php elseif ($port['neighbor_switch_wwn'] !== null): ?>
                        <?= $e($port['neighbor_switch_name'] ?? $t('san.other_switch')) ?>
                        <div class="sub data"><?= $e($port['neighbor_switch_wwn']) ?></div>
                    <?php elseif ($port['trunk_master_port'] !== null): ?>
                        <span class="sub"><?= $e($t('san.trunk_member', [
                            'port' => (string) (int) $port['trunk_master_port'],
                        ])) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Devices ----------------------------------------------------------- -->
<div class="panel">
    <h2><?= $e($t('san.devices_title', ['count' => count($devices)])) ?></h2>
    <p class="note-block"><?= $e($t('san.devices_note')) ?></p>

    <table>
        <thead>
            <tr>
                <th><?= $e($t('san.port')) ?></th>
                <th><?= $e($t('san.port_id')) ?></th>
                <th><?= $e($t('san.wwpn')) ?></th>
                <th><?= $e($t('san.wwnn')) ?></th>
                <th><?= $e($t('san.device_type')) ?></th>
                <th><?= $e($t('san.hba')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $device): ?>
            <tr>
                <td class="data">
                    <?= $device['port_index'] === null ? '—' : (int) $device['port_index'] ?>
                    <?php if (($device['port_name'] ?? '') !== ''): ?>
                        <div class="sub"><?= $e($device['port_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="data"><?= $e($device['fcid'] ?? '—') ?></td>
                <td class="data">
                    <?= $e($device['wwpn']) ?>
                    <?php if ((int) ($device['is_npiv'] ?? 0) === 1): ?>
                        <span class="badge badge-idle">NPIV</span>
                    <?php endif; ?>
                </td>
                <td class="data"><?= $e($device['wwnn'] ?? '—') ?></td>
                <td>
                    <?= $e($device['device_type'] ?? '—') ?>
                    <?php if (($device['fc4_types'] ?? '') !== ''): ?>
                        <div class="sub"><?= $e($device['fc4_types']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($device['node_symb'] ?? null) !== null): ?>
                        <?= $e($device['node_symb']) ?>
                    <?php endif; ?>
                    <?php if (($device['port_symb'] ?? null) !== null): ?>
                        <div class="sub"><?= $e($device['port_symb']) ?></div>
                    <?php endif; ?>
                    <?php if (($device['node_symb'] ?? null) === null && ($device['port_symb'] ?? null) === null): ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                    <div class="sub">
                        <?= $e($t('san.link_speed')) ?>: <?= $e($device['link_speed'] ?? '—') ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- What has changed -------------------------------------------------- -->
<div class="panel">
    <h2><?= $e($t('journal.title')) ?></h2>

    <?php if ($changes === []): ?>
        <p class="muted"><?= $e($t('journal.none')) ?></p>
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
                ?>
                <?php foreach ($new as $field => $after): ?>
                    <tr>
                        <td title="<?= $e((string) $change['changed_at']) ?> UTC">
                            <?= $e(Format::ago($change['changed_at'])) ?>
                        </td>
                        <td class="data"><?= $e($field) ?></td>
                        <td class="old"><?= $e((string) ($old[$field] ?? '—')) ?></td>
                        <td class="new"><?= $e((string) ($after ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
