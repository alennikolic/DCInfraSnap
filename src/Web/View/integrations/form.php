<?php
// src/Web/View/integrations/form.php
//
// Add or edit a source: a vCenter over HTTPS, or a Brocade switch over SSH.
//
// The type is chosen only when adding. Changing it afterwards would mean the
// collected data belonged to a kind of source the row no longer claims to be,
// so an existing source keeps the type it was created with.
//
// The password field appears only when adding. On edit there is a separate
// page for it, so the stored credential is never sent back to the browser —
// not even as a masked value a form could echo back.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Integration\Integration|null $integration */
/** @var array<string, string> $values */
/** @var list<string> $errors */

use DcInfraSnap\Integration\Integration;

$isNew = $integration === null;
$value = static fn (string $key, string $default = ''): string => $values[$key] ?? $default;

$type      = $isNew ? $value('type', Integration::TYPE_VCENTER) : $integration->type;
$isBrocade = $type === Integration::TYPE_BROCADE;
?>

<div class="page-head">
    <div>
        <h1>
            <?= $isNew
                ? $e($t($isBrocade ? 'integrations.add_brocade' : 'integrations.add_vcenter'))
                : $e($t('integrations.edit_title', ['name' => $integration->name])) ?>
        </h1>
        <p><?= $e($t('integrations.form_subtitle')) ?></p>
    </div>
</div>

<form method="post" class="panel form-panel"
      action="<?= $isNew ? '/integrations/add' : '/integrations/' . (int) $integration->id . '/edit' ?>">

    <?php if ($errors !== []): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= $e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

    <?php if ($isNew): ?>
        <label class="field">
            <span class="field-label"><?= $e($t('integrations.type')) ?></span>
            <select name="type">
                <option value="<?= $e(Integration::TYPE_VCENTER) ?>"
                    <?= $isBrocade ? '' : 'selected' ?>><?= $e($t('integrations.type_vcenter')) ?></option>
                <option value="<?= $e(Integration::TYPE_BROCADE) ?>"
                    <?= $isBrocade ? 'selected' : '' ?>><?= $e($t('integrations.type_brocade')) ?></option>
            </select>
            <span class="field-hint"><?= $e($t('integrations.type_hint')) ?></span>
        </label>
    <?php else: ?>
        <input type="hidden" name="type" value="<?= $e($type) ?>">
    <?php endif; ?>

    <label class="field">
        <span class="field-label"><?= $e($t('common.name')) ?></span>
        <input type="text" name="name" required maxlength="100"
               value="<?= $e($value('name')) ?>"
               placeholder="<?= $e($t('integrations.name_placeholder')) ?>">
        <span class="field-hint"><?= $e($t('integrations.name_hint')) ?></span>
    </label>

    <div class="field-row">
        <label class="field field-grow">
            <span class="field-label"><?= $e($t('integrations.hostname')) ?></span>
            <input type="text" name="hostname" required maxlength="255"
                   value="<?= $e($value('hostname')) ?>"
                   placeholder="<?= $e($isBrocade ? 'san-switch-a.example.local' : 'vcenter.example.local') ?>">
        </label>

        <label class="field field-port">
            <span class="field-label"><?= $e($t('integrations.port')) ?></span>
            <input type="number" name="port" required min="1" max="65535"
                   value="<?= $e($value('port', (string) Integration::defaultPort($type))) ?>">
        </label>
    </div>

    <label class="field">
        <span class="field-label"><?= $e($t('integrations.username')) ?></span>
        <input type="text" name="username" required maxlength="190"
               autocomplete="off"
               value="<?= $e($value('username')) ?>"
               placeholder="<?= $e($isBrocade ? 'admin' : 'readonly@vsphere.local') ?>">
        <span class="field-hint"><?= $e($t('integrations.username_hint')) ?></span>
    </label>

    <?php if ($isNew): ?>
        <label class="field">
            <span class="field-label"><?= $e($t('integrations.password')) ?></span>
            <input type="password" name="password" required autocomplete="new-password">
            <span class="field-hint"><?= $e($t('integrations.password_hint')) ?></span>
        </label>
    <?php else: ?>
        <p class="field-hint">
            <?= $e($t('integrations.password_separate')) ?>
            <a href="/integrations/<?= (int) $integration->id ?>/password">
                <?= $e($t('integrations.change_password')) ?>
            </a>
        </p>
    <?php endif; ?>

    <label class="check">
        <input type="checkbox" name="verify_peer" value="1"
            <?= $value('verify_peer') === '1' ? 'checked' : '' ?>>
        <span>
            <?= $e($t($isBrocade ? 'integrations.verify_host_key' : 'integrations.verify_peer')) ?>
            <span class="field-hint"><?= $e($t($isBrocade
                ? 'integrations.verify_host_key_hint'
                : 'integrations.verify_peer_hint')) ?></span>
        </span>
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-inline"><?= $e($t('common.save')) ?></button>
        <a href="/integrations" class="btn"><?= $e($t('common.cancel')) ?></a>
    </div>
</form>
