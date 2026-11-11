<?php
// src/Web/View/san/index.php
//
// Fibre Channel switches: what they are and how much of them is in use.
//
// Ports are shown as "in use / licensed / present" because on a switch with
// Ports on Demand those three are different numbers, and the gap between the
// last two is a purchase rather than a cabling job.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
/** @var array<int,int> $lastRuns */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.san')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number(count($rows))])) ?></p>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title"><?= $e($t('list.nothing_yet')) ?></p>
        <p><?= $e($t('san.none_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('san.switch')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                    <th><?= $e($t('san.fabric')) ?></th>
                    <th><?= $e($t('san.ports')) ?></th>
                    <th><?= $e($t('san.devices')) ?></th>
                    <th><?= $e($t('san.isls')) ?></th>
                    <th><?= $e($t('san.zoning')) ?></th>
                    <th><?= $e($t('san.fos')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $reference = $lastRuns[(int) $row['integration_id']] ?? null;
                $isMissing = $reference !== null && (int) $row['last_run_id'] < $reference;
                $state     = (string) ($row['state'] ?? '');
                ?>
                <tr>
                    <td>
                        <a href="/san/<?= (int) $row['id'] ?>"><?= $e($row['name']) ?></a>
                        <div class="sub data"><?= $e($row['switch_wwn']) ?></div>
                        <?php if ($isMissing): ?>
                            <span class="badge badge-error"><?= $e($t('list.missing')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= strcasecmp($state, 'Online') === 0 ? 'badge-ok' : 'badge-error' ?>">
                            <?= $e($state !== '' ? $state : '—') ?>
                        </span>
                        <?php if ((string) ($row['switch_role'] ?? '') !== ''): ?>
                            <div class="sub"><?= $e($row['switch_role']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $e($row['fabric_name'] ?? $t('san.fabric_unnamed')) ?>
                        <div class="sub">
                            <?= $e($t('san.domain')) ?> <?= (int) $row['domain_id'] ?>
                            <span class="sep">/</span>
                            <?= $e($t('san.switches_in_fabric', [
                                'count' => Format::number($row['fabric_member_count']),
                            ])) ?>
                        </div>
                    </td>
                    <td class="data">
                        <?= $e(Format::number($row['port_online_count'])) ?>
                        <span class="muted">/ <?= $e(Format::number($row['licensed_port_count'])) ?></span>
                        <span class="sep">/</span>
                        <?= $e(Format::number($row['port_count'])) ?>
                        <div class="sub"><?= $e($t('san.ports_legend')) ?></div>
                    </td>
                    <td class="data"><?= $e(Format::number($row['device_count'])) ?></td>
                    <td class="data"><?= $e(Format::number($row['isl_count'])) ?></td>
                    <td class="data">
                        <?= $e(Format::number($row['zone_count'])) ?>
                        <span class="muted"><?= $e($t('san.zones_short')) ?></span>
                        <span class="sep">/</span>
                        <?= $e(Format::number($row['alias_count'])) ?>
                        <span class="muted"><?= $e($t('san.aliases_short')) ?></span>
                    </td>
                    <td class="data"><?= $e($row['fos_version'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
