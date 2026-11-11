<?php
// src/Web/View/integrations/index.php
//
// The list of sources, and everything you can do to one.
//
// Only "Add vCenter" is offered: it is the one source type that exists. When
// a second one arrives, this becomes a choice rather than a single button.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var list<\DcInfraSnap\Integration\Integration> $integrations */
/** @var array<int, list<string>> $projectNames */

$projectNames ??= [];

$state = static function (?bool $ok) use ($e, $t): string {
    if ($ok === null) {
        return '<span class="badge badge-idle">' . $e($t('integrations.untested')) . '</span>';
    }

    return $ok
        ? '<span class="badge badge-ok">' . $e($t('integrations.test_ok')) . '</span>'
        : '<span class="badge badge-error">' . $e($t('integrations.test_failed')) . '</span>';
};
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('nav.integrations')) ?></h1>
        <p><?= $e($t('integrations.subtitle')) ?></p>
    </div>

    <div class="head-actions">
        <a href="/integrations/add" class="btn btn-primary btn-inline">
            <?= $e($t('integrations.add_vcenter')) ?>
        </a>
        <a href="/integrations/add?type=brocade" class="btn btn-inline">
            <?= $e($t('integrations.add_brocade')) ?>
        </a>
    </div>
</div>

<?php if ($integrations === []): ?>

    <div class="panel empty">
        <p class="empty-title"><?= $e($t('integrations.none')) ?></p>
        <p><?= $e($t('integrations.none_hint')) ?></p>
    </div>

<?php else: ?>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th><?= $e($t('common.name')) ?></th>
                    <th><?= $e($t('integrations.address')) ?></th>
                    <th><?= $e($t('integrations.username')) ?></th>
                    <th><?= $e($t('integrations.version')) ?></th>
                    <th><?= $e($t('integrations.last_test')) ?></th>
                    <th><?= $e($t('nav.projects')) ?></th>
                    <th><?= $e($t('common.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($integrations as $i): ?>
                <tr<?= $i->isActive ? '' : ' class="is-inactive"' ?>>
                    <td>
                        <strong><?= $e($i->name) ?></strong>
                        <?php if (!$i->isActive): ?>
                            <span class="badge badge-idle"><?= $e($t('integrations.disabled')) ?></span>
                        <?php endif; ?>

                        <!-- Which kind of source. Worth saying on the row:
                             the two are collected differently and reached over
                             different protocols, and the address alone does
                             not tell them apart. -->
                        <div class="sub"><?= $e($t('integrations.kind_' . $i->type)) ?></div>
                    </td>

                    <td class="data"><?= $e($i->endpoint()) ?></td>
                    <td class="data"><?= $e($i->username) ?></td>

                    <td class="data">
                        <?= $i->productVersion === null
                            ? '<span class="muted">—</span>'
                            : $e($i->productVersion) ?>
                    </td>

                    <td>
                        <?= $state($i->lastTestOk) ?>
                        <?php if ($i->lastTestedAt !== null): ?>
                            <div class="sub data"><?= $e($i->lastTestedAt) ?> UTC</div>
                        <?php endif; ?>
                        <?php if ($i->lastTestOk === false && $i->lastTestMessage !== null): ?>
                            <div class="sub sub-error"><?= $e($i->lastTestMessage) ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- A source can belong to several projects, so this is a
                         list and not a single name. Empty is a normal state:
                         a source needs no project to be collected. -->
                    <td>
                        <?php $inProjects = $projectNames[$i->id] ?? []; ?>
                        <?= $inProjects === []
                            ? '<span class="muted">—</span>'
                            : $e(implode(', ', $inProjects)) ?>
                    </td>

                    <td class="actions">
                        <?php if ($i->isActive): ?>
                            <form method="post" action="/integrations/<?= (int) $i->id ?>/collect" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                <button type="submit" class="btn btn-small btn-primary btn-inline">
                                    <?= $e($t('integrations.collect')) ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="/integrations/<?= (int) $i->id ?>/test" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                            <button type="submit" class="btn btn-small">
                                <?= $e($t('integrations.test')) ?>
                            </button>
                        </form>

                        <a href="/integrations/<?= (int) $i->id ?>/edit" class="btn btn-small">
                            <?= $e($t('common.edit')) ?>
                        </a>

                        <a href="/integrations/<?= (int) $i->id ?>/password" class="btn btn-small">
                            <?= $e($t('integrations.change_password')) ?>
                        </a>

                        <form method="post" action="/integrations/<?= (int) $i->id ?>/toggle" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                            <button type="submit" class="btn btn-small">
                                <?= $i->isActive
                                    ? $e($t('integrations.disable'))
                                    : $e($t('integrations.enable')) ?>
                            </button>
                        </form>

                        <a href="/integrations/<?= (int) $i->id ?>/delete" class="btn btn-small btn-danger">
                            <?= $e($t('common.delete')) ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<p class="note-block"><?= $e($t('integrations.credentials_note')) ?></p>
