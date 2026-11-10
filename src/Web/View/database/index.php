<?php
// src/Web/View/database/index.php
//
// What is actually in the database, with real row counts.

/** @var callable $e */
/** @var callable $t */
/** @var string $database */
/** @var list<array<string,mixed>> $tables */

use DcInfraSnap\Web\Format;

$total = array_sum(array_column($tables, 'rows'));
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.database')) ?></h1>
        <p>
            <?= $e($t('database.summary', [
                'tables' => count($tables),
                'rows'   => Format::number($total),
            ])) ?>
            &middot; <span class="data"><?= $e($database) ?></span>
        </p>
    </div>
</div>

<div class="panel">
    <table>
        <thead>
            <tr>
                <th><?= $e($t('database.table')) ?></th>
                <th><?= $e($t('database.rows')) ?></th>
                <th><?= $e($t('database.size')) ?></th>
                <th><?= $e($t('database.purpose')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tables as $table): ?>
            <tr>
                <td>
                    <a href="/database/<?= $e(rawurlencode((string) $table['name'])) ?>" class="data">
                        <?= $e($table['name']) ?>
                    </a>
                </td>
                <td class="data"><?= $e(Format::number($table['rows'])) ?></td>
                <td class="data"><?= $e(Format::bytes((int) $table['data_bytes'] + (int) $table['index_bytes'])) ?></td>
                <td><?= $table['comment'] === '' ? '<span class="muted">—</span>' : $e($table['comment']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="note-block"><?= $e($t('database.readonly_note')) ?></p>
