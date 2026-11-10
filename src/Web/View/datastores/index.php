<?php
// src/Web/View/datastores/index.php
//
// Datastores, with a bar for how full each one is.
//
// Uncommitted is shown next to free space because the two together are what
// decides whether thin provisioning is about to become everyone's problem:
// a datastore with room left can still be oversubscribed well past it.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.datastores')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number(count($rows))])) ?></p>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title"><?= $e($t('list.nothing_yet')) ?></p>
        <p><?= $e($t('list.collect_first')) ?></p>
    </div>
<?php else: ?>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('datastores.type')) ?></th>
                    <th><?= $e($t('datastores.used')) ?></th>
                    <th><?= $e($t('datastores.free')) ?></th>
                    <th><?= $e($t('datastores.capacity')) ?></th>
                    <th><?= $e($t('datastores.uncommitted')) ?></th>
                    <th><?= $e($t('nav.hosts')) ?></th>
                    <th><?= $e($t('nav.vms')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $used = Format::usedPercent($row['capacity_bytes'], $row['free_bytes']); ?>
                <tr>
                    <td>
                        <strong><?= $e($row['name']) ?></strong>
                        <?php if ((int) ($row['is_accessible'] ?? 1) === 0): ?>
                            <span class="badge badge-error"><?= $e($t('datastores.inaccessible')) ?></span>
                        <?php endif; ?>
                        <?php if (($row['nfs_remote_host'] ?? '') !== ''): ?>
                            <div class="sub data"><?= $e($row['nfs_remote_host']) ?>:<?= $e($row['nfs_remote_path']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $e($row['type'] ?? '—') ?>
                        <?php if (($row['vmfs_version'] ?? '') !== ''): ?>
                            <span class="muted"><?= $e($row['vmfs_version']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="bar-cell">
                        <?php if ($used !== null): ?>
                            <div class="bar" title="<?= $e($used) ?>%">
                                <div class="bar-fill<?= $used >= 90 ? ' is-high' : ($used >= 75 ? ' is-warn' : '') ?>"
                                     style="width: <?= $e($used) ?>%"></div>
                            </div>
                            <span class="data"><?= $e($used) ?>%</span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e(Format::bytes($row['free_bytes'])) ?></td>
                    <td class="data"><?= $e(Format::bytes($row['capacity_bytes'])) ?></td>
                    <td class="data"><?= $e(Format::bytes($row['uncommitted_bytes'])) ?></td>
                    <td class="data"><?= $e(Format::number($row['host_count'])) ?></td>
                    <td class="data"><?= $e(Format::number($row['vm_count'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
