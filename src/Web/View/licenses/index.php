<?php
// src/Web/View/licenses/index.php
//
// Licences. Keys are stored and shown masked — only the last block is real.
//
// "Unlimited" rather than 0 for the total: vCenter reports no limit as zero,
// and a column of zeroes next to real usage reads as a fault.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */

use DcInfraSnap\Web\Format;

$expiry = static function (?string $expires) use ($e, $t): string {
    if ($expires === null || $expires === '') {
        return '<span class="muted">' . $e($t('licenses.perpetual')) . '</span>';
    }

    $days = (int) floor((strtotime($expires . ' UTC') - time()) / 86400);

    $class = $days < 0 ? 'badge-error' : ($days < 60 ? 'badge-warn' : 'badge-idle');
    $label = $days < 0
        ? $t('licenses.expired')
        : $t('licenses.days_left', ['days' => $days]);

    return '<span class="badge ' . $class . '">' . $e($label) . '</span>'
        . '<div class="sub data">' . $e(substr($expires, 0, 10)) . '</div>';
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.licenses')) ?></h1>
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
                    <th><?= $e($t('licenses.key')) ?></th>
                    <th><?= $e($t('licenses.product')) ?></th>
                    <th><?= $e($t('licenses.used')) ?></th>
                    <th><?= $e($t('licenses.total')) ?></th>
                    <th><?= $e($t('licenses.unit')) ?></th>
                    <th><?= $e($t('licenses.expires')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $total = (int) ($row['total_units'] ?? 0); ?>
                <tr>
                    <td><strong><?= $e($row['name']) ?></strong></td>
                    <td class="data"><?= $e($row['moref']) ?></td>
                    <td>
                        <?= $e($row['product_name'] ?? '—') ?>
                        <?php if (($row['product_version'] ?? '') !== ''): ?>
                            <span class="muted"><?= $e($row['product_version']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e(Format::number($row['used_units'])) ?></td>
                    <td class="data">
                        <?= $total === 0 ? $e($t('licenses.unlimited')) : $e(Format::number($total)) ?>
                    </td>
                    <td><?= $e($row['cost_unit'] ?? '—') ?></td>
                    <td><?= $expiry($row['expires_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p class="note-block"><?= $e($t('licenses.masked_note')) ?></p>
