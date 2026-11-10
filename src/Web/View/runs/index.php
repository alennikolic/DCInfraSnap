<?php
// src/Web/View/runs/index.php
//
// The history of collections.
//
// The per-type outcome is the reason this page is worth having: a run marked
// "partial" is only useful if you can see WHICH type failed, because that
// decides whether the data you were about to rely on is current.

/** @var callable $e */
/** @var callable $t */
/** @var list<array<string,mixed>> $rows */
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

$cell = static function (array $row, string $prefix) use ($e): string {
    $status = (string) $row[$prefix . '_status'];

    return match ($status) {
        'ok'      => '<span class="data">' . $e(Format::number($row[$prefix . '_found'])) . '</span>'
                     . ((int) $row[$prefix . '_changed'] > 0
                        ? '<span class="delta">+' . $e($row[$prefix . '_changed']) . '</span>' : ''),
        'failed'  => '<span class="badge badge-error">!</span>',
        default   => '<span class="muted">—</span>',
    };
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
                    <th><?= $e($t('nav.clusters')) ?></th>
                    <th><?= $e($t('nav.hosts')) ?></th>
                    <th><?= $e($t('nav.vms')) ?></th>
                    <th><?= $e($t('nav.datastores')) ?></th>
                    <th><?= $e($t('nav.licenses')) ?></th>
                    <th><?= $e($t('runs.duration')) ?></th>
                    <th><?= $e($t('runs.calls')) ?></th>
                    <th><?= $e($t('runs.when')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
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
                    <td><?= $cell($row, 'clusters') ?></td>
                    <td><?= $cell($row, 'hosts') ?></td>
                    <td><?= $cell($row, 'vms') ?></td>
                    <td><?= $cell($row, 'datastores') ?></td>
                    <td><?= $cell($row, 'licenses') ?></td>
                    <td class="data"><?= $e($duration($row)) ?></td>
                    <td class="data"><?= $e(Format::number($row['api_calls'])) ?></td>
                    <td title="<?= $e((string) $row['queued_at']) ?> UTC"><?= $e(Format::ago($row['queued_at'])) ?></td>
                </tr>
                <?php if ($row['error_message'] !== null): ?>
                    <tr class="row-note">
                        <td colspan="11" class="sub-error"><?= $e($row['error_message']) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="note-block"><?= $e($t('runs.partial_note')) ?></p>
<?php endif; ?>
