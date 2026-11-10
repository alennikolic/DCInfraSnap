<?php
// src/Web/View/database/show.php
//
// One table, as it is. Read only — a SELECT and nothing else.
//
// The encrypted credential column is never read into the page; it is excluded
// from the query, not merely hidden here.

/** @var callable $e */
/** @var callable $t */
/** @var string $table */
/** @var list<string> $columns */
/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pages */

use DcInfraSnap\Web\Format;
?>

<div class="page-head">
    <div>
        <h1 class="data"><?= $e($table) ?></h1>
        <p><?= $e($t('database.row_count', ['rows' => Format::number($total)])) ?></p>
    </div>

    <a href="/database" class="btn btn-inline">&larr; <?= $e($t('common.back')) ?></a>
</div>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title"><?= $e($t('database.empty_table')) ?></p>
    </div>
<?php else: ?>
    <div class="panel panel-scroll">
        <table class="table-raw">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th class="data"><?= $e($column) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <?php $value = $row[$column] ?? null; ?>
                        <td class="data">
                            <?php if ($value === null): ?>
                                <span class="muted">NULL</span>
                            <?php else: ?>
                                <?= $e(mb_strimwidth((string) $value, 0, 120, '…')) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn btn-small">&larr; <?= $e($t('list.previous')) ?></a>
            <?php endif; ?>
            <span class="pager-state"><?= $e($t('list.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
            <?php if ($page < $pages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn btn-small"><?= $e($t('list.next')) ?> &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
