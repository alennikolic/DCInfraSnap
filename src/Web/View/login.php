<?php
// src/Web/View/login.php
//
// The sign-in form, and the message shown when no password is configured.
//
// The "no password" case gets the exact commands to run rather than a bare
// refusal: it is the normal state on a fresh install, not a fault.

/** @var callable $e */
/** @var callable $t */
/** @var bool $configured */
/** @var string|null $error */
/** @var string $csrfToken */
?>

<div class="login-wrap">

    <?php if (!$configured): ?>

        <div class="panel panel-notice">
            <h1><?= $e($t('login.not_configured')) ?></h1>
            <p><?= $e($t('login.setup_hint')) ?></p>

            <pre class="cmd">docker compose run --rm app php bin/dcinfrasnap password:hash
docker compose up -d</pre>
        </div>

    <?php else: ?>

        <form method="post" action="/login" class="panel login-panel">
            <h1><?= $e($t('login.title')) ?></h1>

            <?php if ($error !== null): ?>
                <p class="alert alert-error"><?= $e($t($error)) ?></p>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

            <label class="field">
                <span class="field-label"><?= $e($t('login.password')) ?></span>
                <input type="password"
                       name="password"
                       autocomplete="current-password"
                       autofocus
                       required>
            </label>

            <button type="submit" class="btn btn-primary"><?= $e($t('login.submit')) ?></button>
        </form>

    <?php endif; ?>

</div>
