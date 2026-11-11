<?php
// src/Web/View/projects/delete.php
//
// Confirm deleting a project.
//
// The warning says the opposite of the one on a source: nothing collected is
// lost here. A project owns no inventory, so deleting it removes the grouping
// and the sources carry on exactly as they were. Saying so plainly is the
// point of this page — otherwise it reads like the source delete, which does
// take everything with it.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Project\Project $project */
/** @var list<string> $sourceNames */
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('projects.delete_title', ['name' => $project->name])) ?></h1>
    </div>
</div>

<div class="panel form-panel">
    <?php if ($sourceNames === []): ?>
        <p><?= $e($t('projects.delete_empty')) ?></p>
    <?php else: ?>
        <p><?= $e($t('projects.delete_keeps', ['count' => count($sourceNames)])) ?></p>
        <p class="data"><?= $e(implode(', ', $sourceNames)) ?></p>
    <?php endif; ?>

    <p><?= $e($t('projects.delete_warning')) ?></p>

    <form method="post" action="/projects/<?= (int) $project->id ?>/delete">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

        <div class="form-actions">
            <button type="submit" class="btn btn-danger btn-inline">
                <?= $e($t('projects.delete_confirm')) ?>
            </button>
            <a href="/projects" class="btn"><?= $e($t('common.cancel')) ?></a>
        </div>
    </form>
</div>
