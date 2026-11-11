<?php
// src/Web/View/projects/sources.php
//
// Which sources a project covers.
//
// The whole set is posted at once: what is ticked when this is saved is what
// the project consists of afterwards. That matches how the list reads — there
// is no separate "remove" action to look for.
//
// Disabled sources are offered too. A switch that has been taken out of
// collection is still part of the environment the report describes, and what
// it last reported is still stored.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Project\Project $project */
/** @var list<array<string, mixed>> $sources */
/** @var list<int> $selected */
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('projects.sources_title', ['name' => $project->name])) ?></h1>
        <p><?= $e($t('projects.sources_subtitle')) ?></p>
    </div>
</div>

<?php if ($sources === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('projects.no_sources')) ?></p>
        <p><?= $e($t('projects.no_sources_hint')) ?></p>
        <p><a href="/integrations" class="btn btn-inline"><?= $e($t('nav.integrations')) ?></a></p>
    </div>

<?php else: ?>

    <form method="post" class="panel form-panel"
          action="/projects/<?= (int) $project->id ?>/sources">

        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

        <?php foreach ($sources as $source): ?>
            <?php $sourceId = (int) $source['id']; ?>
            <label class="check">
                <input type="checkbox" name="sources[]" value="<?= $sourceId ?>"
                    <?= in_array($sourceId, $selected, true) ? 'checked' : '' ?>>
                <span>
                    <?= $e((string) $source['name']) ?>
                    <?php if (!(bool) $source['is_active']): ?>
                        <span class="badge badge-idle"><?= $e($t('integrations.disabled')) ?></span>
                    <?php endif; ?>
                    <span class="field-hint">
                        <?= $e($t('integrations.kind_' . (string) $source['type'])) ?>
                        · <?= $e((string) $source['hostname'] . ':' . (int) $source['port']) ?>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-inline"><?= $e($t('common.save')) ?></button>
            <a href="/projects" class="btn"><?= $e($t('common.cancel')) ?></a>
        </div>
    </form>

<?php endif; ?>

<p class="note-block"><?= $e($t('projects.shared_note')) ?></p>
