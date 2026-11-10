<?php
// src/Web/View/integrations/password.php
//
// Change the password of one vCenter account.
//
// A page of its own, so the edit form never has to carry the stored
// credential. The field starts empty and the old value is never shown.

/** @var callable $e */
/** @var callable $t */
/** @var string $csrfToken */
/** @var \DcInfraSnap\Integration\Integration $integration */
/** @var list<string> $errors */
?>

<div class="page-head">
    <div>
        <h1><?= $e($t('integrations.password_title', ['name' => $integration->name])) ?></h1>
        <p class="data"><?= $e($integration->username) ?> @ <?= $e($integration->endpoint()) ?></p>
    </div>
</div>

<form method="post" action="/integrations/<?= (int) $integration->id ?>/password" class="panel form-panel">

    <?php if ($errors !== []): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= $e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

    <label class="field">
        <span class="field-label"><?= $e($t('integrations.new_password')) ?></span>
        <input type="password" name="password" required autocomplete="new-password" autofocus>
    </label>

    <label class="field">
        <span class="field-label"><?= $e($t('integrations.repeat_password')) ?></span>
        <input type="password" name="password_repeat" required autocomplete="new-password">
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-inline"><?= $e($t('common.save')) ?></button>
        <a href="/integrations" class="btn"><?= $e($t('common.cancel')) ?></a>
    </div>

    <p class="field-hint"><?= $e($t('integrations.password_change_note')) ?></p>
</form>
