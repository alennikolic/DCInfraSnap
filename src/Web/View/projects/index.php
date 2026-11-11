<?php
// src/Web/View/projects/index.php
//
// The list of projects and the sources each one covers.
//
// The source names are listed rather than counted. A project is only useful
// if you can see at a glance what is in it, and the counts are small.

/** @var callable $e */
/** @var callable $t */
/** @var list<\DcInfraSnap\Project\Project> $projects */
/** @var array<int, list<string>> $sourceNames */
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.projects')) ?></h1>
        <p><?= $e($t('projects.subtitle')) ?></p>
    </div>

    <div class="head-actions">
        <a href="/projects/add" class="btn btn-primary btn-inline">
            <?= $e($t('projects.add')) ?>
        </a>
    </div>
</div>

<?php if ($projects === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('projects.none')) ?></p>
        <p><?= $e($t('projects.none_hint')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('projects.client')) ?></th>
                    <th><?= $e($t('projects.sources')) ?></th>
                    <th><?= $e($t('common.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <?php $names = $sourceNames[$project->id] ?? []; ?>
                <tr>
                    <td>
                        <strong><?= $e($project->name) ?></strong>
                        <?php if ($project->description !== null && $project->description !== ''): ?>
                            <div class="sub"><?= $e($project->description) ?></div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= $project->client === null || $project->client === ''
                            ? '<span class="muted">—</span>'
                            : $e($project->client) ?>
                    </td>

                    <td>
                        <?php if ($names === []): ?>
                            <span class="badge badge-idle"><?= $e($t('projects.sources_none')) ?></span>
                        <?php else: ?>
                            <?= $e($t('projects.sources_count', ['count' => count($names)])) ?>
                            <div class="sub"><?= $e(implode(', ', $names)) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="actions">
                        <a href="/projects/<?= (int) $project->id ?>/sources" class="btn btn-small btn-primary btn-inline">
                            <?= $e($t('projects.sources')) ?>
                        </a>

                        <a href="/projects/<?= (int) $project->id ?>/edit" class="btn btn-small">
                            <?= $e($t('common.edit')) ?>
                        </a>

                        <a href="/projects/<?= (int) $project->id ?>/delete" class="btn btn-small btn-danger">
                            <?= $e($t('common.delete')) ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<p class="note-block"><?= $e($t('projects.note')) ?></p>
