<?php
// src/Web/View/clusters/index.php
//
// Clusters, with the host and machine counts worked out from the inventory
// rather than stored — a count that is derived should not be a column that
// can disagree with the rows it counts.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.clusters')) ?></h1>
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
                    <th><?= $e($t('clusters.datacenter')) ?></th>
                    <th><?= $e($t('clusters.ha')) ?></th>
                    <th><?= $e($t('clusters.drs')) ?></th>
                    <th><?= $e($t('nav.hosts')) ?></th>
                    <th><?= $e($t('nav.vms')) ?></th>
                    <th><?= $e($t('list.last_seen')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= $e($row['name']) ?></strong></td>
                    <td><?= $e($row['datacenter_name'] ?? '—') ?></td>
                    <td><?= $e(Format::flag($row['ha_enabled'], $t('common.yes'), $t('common.no'))) ?></td>
                    <td>
                        <?= $e(Format::flag($row['drs_enabled'], $t('common.yes'), $t('common.no'))) ?>
                        <?php if (($row['drs_behavior'] ?? '') !== ''): ?>
                            <span class="muted"><?= $e($row['drs_behavior']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e(Format::number($row['host_count'])) ?></td>
                    <td class="data"><?= $e(Format::number($row['vm_count'])) ?></td>
                    <td title="<?= $e((string) $row['last_seen']) ?> UTC"><?= $e(Format::ago($row['last_seen'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
