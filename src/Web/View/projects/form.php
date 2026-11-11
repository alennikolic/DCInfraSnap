<?php
// src/Web/View/projects/form.php
//
// Add or edit a project.
//
// Only the name is required. The customer and the description exist for the
// report title page, and a project without them is perfectly usable.
//
// The sources are not chosen here. They are a list of their own, on their
// own page, so adding a source to a project does not mean re-saving the
// project's details.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Project\Project|null $project */
/** @var array<string, string> $values */
/** @var list<string> $errors */

$isNew = $project === null;
$value = static fn (string $key, string $default = ''): string => $values[$key] ?? $default;
?>

<div class="page-head">
    <div>
        <h1>
            <?= $isNew
                ? $e($t('projects.add'))
                : $e($t('projects.edit_title', ['name' => $project->name])) ?>
        </h1>
        <p><?= $e($t('projects.form_subtitle')) ?></p>
    </div>
</div>

<form method="post" class="panel form-panel"
      action="<?= $isNew ? '/projects/add' : '/projects/' . (int) $project->id . '/edit' ?>">

    <?php if ($errors !== []): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= $e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

    <label class="field">
        <span class="field-label"><?= $e($t('common.name')) ?></span>
        <input type="text" name="name" required maxlength="100"
               value="<?= $e($value('name')) ?>"
               placeholder="<?= $e($t('projects.name_placeholder')) ?>">
        <span class="field-hint"><?= $e($t('projects.name_hint')) ?></span>
    </label>

    <label class="field">
        <span class="field-label"><?= $e($t('projects.client')) ?></span>
        <input type="text" name="client" maxlength="150"
               value="<?= $e($value('client')) ?>"
               placeholder="<?= $e($t('projects.client_placeholder')) ?>">
        <span class="field-hint"><?= $e($t('projects.client_hint')) ?></span>
    </label>

    <label class="field">
        <span class="field-label"><?= $e($t('projects.description')) ?></span>
        <input type="text" name="description" maxlength="500"
               value="<?= $e($value('description')) ?>">
        <span class="field-hint"><?= $e($t('projects.description_hint')) ?></span>
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-inline"><?= $e($t('common.save')) ?></button>
        <a href="/projects" class="btn"><?= $e($t('common.cancel')) ?></a>
    </div>
</form>
