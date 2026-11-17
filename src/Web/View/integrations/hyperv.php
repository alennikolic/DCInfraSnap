<?php
// src/Web/View/integrations/hyperv.php
//
// Collecting from a Hyper-V source: download the script, upload what it
// produced, import the set.
//
// The waiting list is the point of this page. A host reports only its own
// machines, so the import needs every node's file at once; until the set is
// complete the files sit here and the page says which node is still missing.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Integration\Integration $integration */
/** @var list<array<string, mixed>> $staged */
/** @var list<string> $missingNodes */
/** @var list<string> $problems */
/** @var list<string> $notes */
/** @var array<string, mixed>|null $lastImported */
/** @var string $uploadLimit */
/** @var bool $scriptAvailable */
?>

<div class="page-head">
    <div>
        <h1><?= $e($integration->name) ?></h1>
        <p><?= $e($t('hyperv.subtitle')) ?></p>
    </div>

    <div class="head-actions">
        <a href="/integrations" class="btn btn-inline"><?= $e($t('common.back')) ?></a>
    </div>
</div>

<!-- Step one: the script. -->
<div class="panel">
    <h2><?= $e($t('hyperv.script_title')) ?></h2>
    <p><?= $e($t('hyperv.script_hint')) ?></p>

    <?php if ($scriptAvailable): ?>
        <p>
            <a href="/integrations/<?= (int) $integration->id ?>/script" class="btn btn-primary btn-inline">
                <?= $e($t('hyperv.download_script')) ?>
            </a>
        </p>

        <pre class="data"><?= $e($t('hyperv.run_command')) ?></pre>
        <p class="muted"><?= $e($t('hyperv.script_readonly')) ?></p>
    <?php else: ?>
        <p class="error"><?= $e($t('hyperv.script_missing')) ?></p>
    <?php endif; ?>
</div>

<!-- Step two: the files. -->
<div class="panel">
    <h2><?= $e($t('hyperv.upload_title')) ?></h2>
    <p><?= $e($t('hyperv.upload_hint')) ?></p>

    <form method="post"
          action="/integrations/<?= (int) $integration->id ?>/upload"
          enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

        <div class="field">
            <input type="file" name="collector_file[]" accept=".json,application/json" multiple required>
            <p class="hint"><?= $e($t('hyperv.upload_limit', ['limit' => $uploadLimit])) ?></p>
        </div>

        <button type="submit" class="btn btn-primary"><?= $e($t('hyperv.upload')) ?></button>
    </form>
</div>

<!-- Step three: the set, and what is still missing from it. -->
<div class="panel">
    <h2><?= $e($t('hyperv.staged_title')) ?></h2>

    <?php if ($staged === []): ?>

        <p class="muted"><?= $e($t('hyperv.staged_none')) ?></p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th><?= $e($t('hyperv.col_host')) ?></th>
                    <th><?= $e($t('hyperv.col_cluster')) ?></th>
                    <th><?= $e($t('hyperv.col_generated')) ?></th>
                    <th><?= $e($t('hyperv.col_machines')) ?></th>
                    <th><?= $e($t('hyperv.col_script')) ?></th>
                    <th><?= $e($t('hyperv.col_size')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staged as $file): ?>
                <tr>
                    <td class="data"><?= $e((string) $file['collector_host']) ?></td>
                    <td>
                        <?= $file['cluster_name'] === null
                            ? '<span class="muted">' . $e($t('hyperv.standalone')) . '</span>'
                            : $e((string) $file['cluster_name']) ?>
                    </td>
                    <td class="data"><?= $e((string) $file['generated_at']) ?></td>
                    <td class="numeric"><?= (int) $file['vm_count'] ?></td>
                    <td class="data">
                        <?= $e((string) $file['script_version']) ?>
                        <?php if ((string) $file['source_token'] === \DcInfraSnap\Source\Hyperv\ImportToken::MANUAL): ?>
                            <span class="muted"><?= $e($t('hyperv.token_manual')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="numeric"><?= $e(\DcInfraSnap\Web\Format::bytes($file['byte_size'])) ?></td>
                    <td class="actions">
                        <form method="post"
                              action="/integrations/<?= (int) $integration->id ?>/staged/<?= (int) $file['id'] ?>/discard"
                              class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                            <button type="submit" class="btn btn-small btn-danger">
                                <?= $e($t('common.delete')) ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($problems !== []): ?>
            <ul class="error">
                <?php foreach ($problems as $problem): ?>
                    <li><?= $e($problem) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($missingNodes !== []): ?>
            <p class="error">
                <?= $e($t('hyperv.missing_nodes', ['nodes' => implode(', ', $missingNodes)])) ?>
            </p>
            <p><?= $e($t('hyperv.missing_nodes_hint')) ?></p>
        <?php endif; ?>

        <?php if ($notes !== []): ?>
            <ul class="muted">
                <?php foreach ($notes as $note): ?>
                    <li><?= $e($note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($problems === []): ?>
            <form method="post" action="/integrations/<?= (int) $integration->id ?>/import">
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                <?php if ($missingNodes !== []): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="force" value="1">
                        <?= $e($t('hyperv.force')) ?>
                    </label>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <?= $e($t('hyperv.import')) ?>
                </button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php if ($lastImported !== null): ?>
    <p class="note-block">
        <?= $e($t('hyperv.last_import', [
            'at'  => (string) $lastImported['uploaded_at'],
            'run' => (string) $lastImported['run_id'],
        ])) ?>
    </p>
<?php endif; ?>
