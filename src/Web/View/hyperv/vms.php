<?php
// src/Web/View/hyperv/vms.php
//
// Hyper-V machines: search, filters, paging.
//
// Deliberately the same shape as the vCenter machine list, because it
// answers the same questions. What differs is what a Hyper-V machine is
// keyed and grouped by: the node that reported it, and whether it is a
// cluster resource at all — a cluster node happily runs machines that are
// not, and those cannot move.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $machines */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array<string,string> $filters */
/** @var array{integrations: list<array<string,mixed>>, states: list<string>, nodes: list<array<string,mixed>>} $options */

use DcInfraSnap\Web\Format;

$query = static function (array $overrides) use ($filters): string {
    $all = array_filter(array_merge($filters, $overrides), static fn ($v): bool => (string) $v !== '');

    return $all === [] ? '' : '?' . http_build_query($all);
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.hyperv_vms')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number($total)])) ?></p>
    </div>
</div>

<form method="get" class="panel filters">
    <input type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>"
           placeholder="<?= $e($t('hv.search_placeholder')) ?>" class="filter-search">

    <?php if (count($options['integrations']) > 1): ?>
        <select name="integration">
            <option value=""><?= $e($t('filter.all_sources')) ?></option>
            <?php foreach ($options['integrations'] as $o): ?>
                <option value="<?= (int) $o['id'] ?>"
                    <?= (string) ($filters['integration'] ?? '') === (string) $o['id'] ? 'selected' : '' ?>>
                    <?= $e((string) $o['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <select name="state">
        <option value=""><?= $e($t('hv.any_state')) ?></option>
        <?php foreach ($options['states'] as $state): ?>
            <option value="<?= $e($state) ?>" <?= ($filters['state'] ?? '') === $state ? 'selected' : '' ?>>
                <?= $e(Format::state($state)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="node">
        <option value=""><?= $e($t('hv.any_host')) ?></option>
        <?php foreach ($options['nodes'] as $o): ?>
            <option value="<?= $e((string) $o['moref']) ?>"
                <?= ($filters['node'] ?? '') === (string) $o['moref'] ? 'selected' : '' ?>>
                <?= $e((string) $o['hostname']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="clustered">
        <option value=""><?= $e($t('hv.any_clustered')) ?></option>
        <option value="1" <?= ($filters['clustered'] ?? '') === '1' ? 'selected' : '' ?>>
            <?= $e($t('hv.clustered_only')) ?>
        </option>
        <option value="0" <?= ($filters['clustered'] ?? '') === '0' ? 'selected' : '' ?>>
            <?= $e($t('hv.not_clustered_only')) ?>
        </option>
    </select>

    <button type="submit" class="btn btn-small btn-primary"><?= $e($t('filter.apply_label')) ?></button>

    <?php if (array_filter($filters) !== []): ?>
        <a href="/hyperv/vms" class="btn btn-small"><?= $e($t('filter.clear')) ?></a>
    <?php endif; ?>
</form>

<?php if ($machines === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('hv.no_machines')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                    <th><?= $e($t('hv.host')) ?></th>
                    <th class="numeric"><?= $e($t('hv.vcpu')) ?></th>
                    <th class="numeric"><?= $e($t('hv.memory')) ?></th>
                    <th class="numeric"><?= $e($t('hv.storage')) ?></th>
                    <th><?= $e($t('hv.network')) ?></th>
                    <th><?= $e($t('hv.guest')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($machines as $machine): ?>
                <tr>
                    <td>
                        <a href="/hyperv/vms/<?= (int) $machine['id'] ?>"><?= $e((string) $machine['name']) ?></a>
                        <?php if (!$machine['is_clustered']): ?>
                            <span class="muted" title="<?= $e($t('hv.not_clustered_hint')) ?>">·</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e(Format::state((string) $machine['vm_state'])) ?></td>
                    <td>
                        <?php if ($machine['node_id'] !== null): ?>
                            <a href="/hyperv/hosts/<?= (int) $machine['node_id'] ?>">
                                <?= $e((string) $machine['node_name']) ?>
                            </a>
                        <?php else: ?>
                            <?= $e((string) $machine['owner_node']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="numeric"><?= (int) $machine['processor_count'] ?></td>
                    <td class="numeric"><?= $e(Format::bytes($machine['memory_startup'])) ?></td>
                    <td class="numeric">
                        <?= (int) $machine['disk_count'] ?>
                        <span class="muted"><?= $e(Format::bytes($machine['disk_capacity_bytes'])) ?></span>
                    </td>
                    <td class="numeric"><?= (int) $machine['nic_count'] ?></td>
                    <td>
                        <?= $e((string) ($machine['guest_os_name'] ?? '')) ?>
                        <?php if ($machine['guest_hostname'] !== null): ?>
                            <span class="muted data"><?= $e((string) $machine['guest_hostname']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a href="<?= $e($query(['page' => $page - 1])) ?>" class="btn btn-small">
                    &larr; <?= $e($t('list.previous')) ?>
                </a>
            <?php endif; ?>

            <span class="pager-state">
                <?= $e($t('list.page_of', ['page' => $page, 'pages' => $pages])) ?>
            </span>

            <?php if ($page < $pages): ?>
                <a href="<?= $e($query(['page' => $page + 1])) ?>" class="btn btn-small">
                    <?= $e($t('list.next')) ?> &rarr;
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
