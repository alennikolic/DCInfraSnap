<?php
// src/Web/View/runs/index.php
//
// The history of collections.
//
// The per-type outcome is the reason this page is worth having: a run marked
// "partial" is only useful if you can see WHICH type failed, because that
// decides whether the data you were about to rely on is current.
//
// WHY THE TYPES ARE NOT COLUMNS. They were, one column per type, which worked
// while every run collected the same six things. A switch run collects seven
// entirely different ones, so a fixed set of columns would be half empty on
// every row whichever source it came from. Each run now lists the types it
// actually recorded, read from discovery_run_type.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
/** @var array<int, list<array<string,mixed>>> $runTypes */
/** @var string $csrfToken */

use DcInfraSnap\Web\Format;

$badge = static function (string $status) use ($e, $t): string {
    $class = match ($status) {
        'success'           => 'badge-ok',
        'partial'           => 'badge-warn',
        'failed'            => 'badge-error',
        'running', 'queued' => 'badge-idle',
        default             => 'badge-idle',
    };

    return '<span class="badge ' . $class . '">' . $e($t('runs.status.' . $status)) . '</span>';
};

/**
 * One type's outcome, as a small labelled figure.
 *
 * The label comes from the translations by type key, so a type added later
 * appears here as soon as it has a name.
 */
$typeCell = static function (array $type) use ($e, $t): string {
    $label = $e($t('type.' . $type['type_key']));

    if ((string) $type['status'] === 'failed') {
        return '<span class="type-pill type-pill-failed" title="'
            . $e((string) ($type['error_message'] ?? '')) . '">'
            . $label . ' <span class="badge badge-error">!</span></span>';
    }

    $changed = (int) $type['changed'] > 0
        ? '<span class="delta">+' . $e((string) $type['changed']) . '</span>'
        : '';

    return '<span class="type-pill">' . $label
        . ' <span class="data">' . $e(Format::number($type['found'])) . '</span>'
        . $changed . '</span>';
};

$duration = static function (array $row): string {
    if ($row['started_at'] === null || $row['finished_at'] === null) {
        return '—';
    }

    $seconds = strtotime((string) $row['finished_at']) - strtotime((string) $row['started_at']);

    return $seconds < 1 ? '< 1s' : Format::duration($seconds);
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.runs')) ?></h1>
        <p><?= $e($t('runs.subtitle')) ?></p>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="panel empty">
        <p class="empty-title"><?= $e($t('runs.none')) ?></p>
        <p><?= $e($t('runs.none_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('runs.run')) ?></th>
                    <th><?= $e($t('runs.source')) ?></th>
                    <th><?= $e($t('common.status')) ?></th>
                    <th><?= $e($t('runs.collected')) ?></th>
                    <th><?= $e($t('runs.duration')) ?></th>
                    <th><?= $e($t('runs.calls')) ?></th>
                    <th><?= $e($t('runs.when')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $types = $runTypes[(int) $row['id']] ?? []; ?>
                <tr>
                    <td class="data">#<?= (int) $row['id'] ?></td>
                    <td><?= $e($row['integration_name']) ?></td>
                    <td>
                        <?= $badge((string) $row['status']) ?>
                        <?php if (in_array($row['status'], ['queued', 'running'], true)): ?>
                            <form method="post" action="/runs/<?= (int) $row['id'] ?>/reset" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                <button type="submit" class="btn btn-small"><?= $e($t('runs.release')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="type-list">
                        <?php if ($types === []): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?php foreach ($types as $type): ?>
                                <?= $typeCell($type) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="data"><?= $e($duration($row)) ?></td>
                    <td class="data"><?= $e(Format::number($row['api_calls'])) ?></td>
                    <td title="<?= $e((string) $row['queued_at']) ?> UTC"><?= $e(Format::ago($row['queued_at'])) ?></td>
                </tr>
                <?php if ($row['error_message'] !== null): ?>
                    <tr class="row-note">
                        <td colspan="7" class="sub-error"><?= $e($row['error_message']) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="note-block"><?= $e($t('runs.partial_note')) ?></p>
<?php endif; ?>
