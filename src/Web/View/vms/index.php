<?php
// src/Web/View/vms/index.php
//
// Virtual machines: search, filters, paging, export.
//
// The red "not in the last collection" mark compares the row's last_run_id
// against the last COMPLETE run of its source. After a partial or failed
// run, an object being absent may only mean collection never reached it, so
// those runs are not used as the reference — see InventoryQuery.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array<string,string> $filters */
/** @var array<string,mixed> $options */
/** @var array<int,int> $lastRuns */

use DcInfraSnap\Web\Format;

$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($v): bool => $v !== '' && $v !== null
    );

    return $params === [] ? '' : '?' . http_build_query($params);
};

$missing = static function (array $row) use ($lastRuns): bool {
    $reference = $lastRuns[(int) $row['integration_id']] ?? null;

    return $reference !== null && (int) $row['last_run_id'] < $reference;
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.vms')) ?></h1>
        <p><?= $e($t('list.showing', ['count' => Format::number($total)])) ?></p>
    </div>

    <?php if ($total > 0): ?>
        <a href="/vms/export<?= $e($query([])) ?>" class="btn btn-inline">
            <?= $e($t('list.export_csv')) ?>
        </a>
    <?php endif; ?>
</div>

<form method="get" class="panel filters">
    <input type="search" name="q" value="<?= $e($filters['q'] ?? '') ?>"
           placeholder="<?= $e($t('vms.search_placeholder')) ?>" class="filter-search">

    <?php if (count($options['integrations']) > 1): ?>
        <select name="integration">
            <option value=""><?= $e($t('filter.all_sources')) ?></option>
            <?php foreach ($options['integrations'] as $o): ?>
                <option value="<?= (int) $o['id'] ?>"
                    <?= (string) ($filters['integration'] ?? '') === (string) $o['id'] ? 'selected' : '' ?>>
                    <?= $e($o['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <select name="power">
        <option value=""><?= $e($t('filter.any_power')) ?></option>
        <?php foreach ($options['powers'] as $power): ?>
            <option value="<?= $e($power) ?>"
                <?= ($filters['power'] ?? '') === $power ? 'selected' : '' ?>>
                <?= $e(Format::state($power)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="cluster">
        <option value=""><?= $e($t('filter.any_cluster')) ?></option>
        <?php foreach ($options['clusters'] as $o): ?>
            <option value="<?= $e($o['moref']) ?>"
                <?= ($filters['cluster'] ?? '') === (string) $o['moref'] ? 'selected' : '' ?>>
                <?= $e($o['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="host">
        <option value=""><?= $e($t('filter.any_host')) ?></option>
        <?php foreach ($options['hosts'] as $o): ?>
            <option value="<?= $e($o['moref']) ?>"
                <?= ($filters['host'] ?? '') === (string) $o['moref'] ? 'selected' : '' ?>>
                <?= $e($o['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="template">
        <option value=""><?= $e($t('filter.any_kind')) ?></option>
        <option value="0" <?= ($filters['template'] ?? '') === '0' ? 'selected' : '' ?>>
            <?= $e($t('vms.machines_only')) ?>
        </option>
        <option value="1" <?= ($filters['template'] ?? '') === '1' ? 'selected' : '' ?>>
            <?= $e($t('vms.templates_only')) ?>
        </option>
    </select>

    <label class="check check-inline">
        <input type="checkbox" name="missing" value="1" <?= ($filters['missing'] ?? '') === '1' ? 'checked' : '' ?>>
        <span><?= $e($t('filter.missing_only')) ?></span>
    </label>

    <button type="submit" class="btn btn-inline"><?= $e($t('common.search')) ?></button>

    <?php if (array_filter($filters) !== []): ?>
        <a href="/vms" class="btn btn-inline"><?= $e($t('filter.clear')) ?></a>
    <?php endif; ?>
</form>

<?php if ($rows === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('list.nothing_found')) ?></p>
        <p><?= $e($t('list.nothing_found_hint')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('vms.power')) ?></th>
                    <th><?= $e($t('vms.guest')) ?></th>
                    <th><?= $e($t('vms.address')) ?></th>
                    <th><?= $e($t('vms.cpu')) ?></th>
                    <th><?= $e($t('vms.memory')) ?></th>
                    <th><?= $e($t('nav.hosts')) ?></th>
                    <th><?= $e($t('list.last_seen')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="/vms/<?= (int) $row['id'] ?>"><?= $e($row['name']) ?></a>
                        <?php if ((int) $row['is_template'] === 1): ?>
                            <span class="badge badge-idle"><?= $e($t('vms.template')) ?></span>
                        <?php endif; ?>
                        <?php if ($missing($row)): ?>
                            <span class="badge badge-error" title="<?= $e($t('list.missing_hint')) ?>">
                                <?= $e($t('list.missing')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= $e(Format::state($row['power_state'])) ?></td>
                    <td><?= $e($row['guest_full_name'] ?? $row['guest_os'] ?? '—') ?></td>
                    <td class="data"><?= $e($row['guest_ip_address'] ?? '—') ?></td>
                    <td class="data"><?= $e(Format::number($row['cpu_count'])) ?></td>
                    <td class="data"><?= $e(Format::mib($row['memory_mib'])) ?></td>
                    <td><?= $e($row['host_name'] ?? '—') ?></td>
                    <td title="<?= $e((string) $row['last_seen']) ?> UTC">
                        <?= $e(Format::ago($row['last_seen'])) ?>
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
