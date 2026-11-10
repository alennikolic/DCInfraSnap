<?php
// src/Web/View/overview/index.php
//
// What has been collected, and from where.
//
// With no sources yet, the page says so plainly instead of showing a wall of
// zeroes — an empty database and a broken collection should not look alike.

/** @var callable $e */
/** @var callable $t */
/** @var array<string, int> $counts */
/** @var list<array<string, mixed>> $sources */

$number = static fn (int $n): string => number_format($n, 0, '.', ' ');
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('overview.title')) ?></h1>
        <p><?= $e($t('overview.subtitle')) ?></p>
    </div>
</div>

<?php if ($sources === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('overview.no_integrations')) ?></p>
        <p><?= $e($t('overview.add_first')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <h2><?= $e($t('overview.sources')) ?></h2>

        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th>Address</th>
                    <th><?= $e($t('common.status')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $source): ?>
                <tr>
                    <td><?= $e($source['name']) ?></td>
                    <td class="data"><?= $e($source['hostname']) ?>:<?= $e($source['port']) ?></td>
                    <td>
                        <?= (int) $source['is_active'] === 1
                            ? $e($t('common.yes'))
                            : $e($t('common.no')) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<div class="panel">
    <h2><?= $e($t('overview.counts')) ?></h2>

    <div class="counts">
        <?php
        $tiles = [
            'nav.clusters'   => $counts['clusters'],
            'nav.hosts'      => $counts['hosts'],
            'nav.vms'        => $counts['vms'],
            'nav.datastores' => $counts['datastores'],
            'nav.licenses'   => $counts['licenses'],
            'nav.runs'       => $counts['runs'],
        ];
        ?>

        <?php foreach ($tiles as $key => $value): ?>
            <div class="count-tile<?= $value === 0 ? ' is-zero' : '' ?>">
                <span class="count-value"><?= $e($number($value)) ?></span>
                <span class="count-label"><?= $e($t($key)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
