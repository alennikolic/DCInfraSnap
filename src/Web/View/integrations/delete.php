<?php
// src/Web/View/integrations/delete.php
//
// Confirm removing a source.
//
// It says what will go, counted from the database rather than described in
// general terms. "This will also delete 153 virtual machines" is a different
// decision from "this will delete related data".

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Integration\Integration $integration */
/** @var array<string, int> $counts */

$labels = [
    'vc_cluster'    => 'nav.clusters',
    'vc_host'       => 'nav.hosts',
    'vc_vm'         => 'nav.vms',
    'vc_datastore'  => 'nav.datastores',
    'vc_license'    => 'nav.licenses',
    'discovery_run' => 'nav.runs',
];

$total = array_sum($counts);
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('integrations.delete_title', ['name' => $integration->name])) ?></h1>
        <p class="data"><?= $e($integration->endpoint()) ?></p>
    </div>
</div>

<div class="panel panel-notice">

    <?php if ($total === 0): ?>
        <p><?= $e($t('integrations.delete_empty')) ?></p>
    <?php else: ?>
        <p><?= $e($t('integrations.delete_warning')) ?></p>

        <table class="table-compact">
            <tbody>
            <?php foreach ($labels as $table => $key): ?>
                <?php if (($counts[$table] ?? 0) > 0): ?>
                    <tr>
                        <td><?= $e($t($key)) ?></td>
                        <td class="data"><?= $e(number_format($counts[$table], 0, '.', ' ')) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p><?= $e($t('integrations.delete_irreversible')) ?></p>
    <?php endif; ?>

    <form method="post" action="/integrations/<?= (int) $integration->id ?>/delete" class="form-actions">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <button type="submit" class="btn btn-danger btn-inline"><?= $e($t('integrations.delete_confirm')) ?></button>
        <a href="/integrations" class="btn"><?= $e($t('common.cancel')) ?></a>
    </form>
</div>
