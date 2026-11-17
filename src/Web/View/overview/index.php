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
                    <th><?= $e($t('integrations.address')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $source): ?>
                <tr>
                    <td><?= $e($source['name']) ?></td>
                    <td class="data">
                        <?php /* An offline source has no port to show: it is a
                                cluster name, not an endpoint. */ ?>
                        <?= $e($source['hostname']) ?><?= (int) $source['port'] > 0
                            ? ':' . $e($source['port']) : '' ?>
                    </td>
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

    <?php
    /* Grouped the same way the menu is, and for the same reason: a cluster
       and a host mean different things under VMware and under Hyper-V, and
       one long row of tiles would invite adding the two together. A group
       with nothing in it is left out rather than shown as zeros. */
    $groups = [
        'nav.group_vmware' => [
            'nav.clusters'   => $counts['clusters'],
            'nav.hosts'      => $counts['hosts'],
            'nav.vms'        => $counts['vms'],
            'nav.datastores' => $counts['datastores'],
            'nav.licenses'   => $counts['licenses'],
        ],
        'nav.group_san' => [
            'overview.switches' => $counts['switches'],
            'overview.fc_ports' => $counts['fc_ports'],
            'overview.zones'    => $counts['zones'],
        ],
        'nav.group_hyperv' => [
            'overview.hv_clusters' => $counts['hv_clusters'],
            'overview.hv_hosts'    => $counts['hv_hosts'],
            'overview.hv_vms'      => $counts['hv_vms'],
            'overview.hv_shared'   => $counts['hv_shared'],
        ],
        'nav.group_system' => [
            'nav.runs' => $counts['runs'],
        ],
    ];
    ?>

    <?php foreach ($groups as $groupKey => $tiles): ?>
        <?php if (array_sum($tiles) === 0 && $groupKey !== 'nav.group_system') { continue; } ?>

        <h3 class="counts-group"><?= $e($t($groupKey)) ?></h3>

        <div class="counts">
            <?php foreach ($tiles as $key => $value): ?>
                <div class="count-tile<?= $value === 0 ? ' is-zero' : '' ?>">
                    <span class="count-value"><?= $e($number($value)) ?></span>
                    <span class="count-label"><?= $e($t($key)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
