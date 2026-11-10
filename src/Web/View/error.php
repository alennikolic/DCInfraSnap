<?php
// src/Web/View/error.php
//
// Not found, and anything that went wrong.
//
// The detail is passed in only when APP_ENV=dev. In production the visitor
// gets the headline and the log gets the rest.

/** @var callable $e */
/** @var callable $t */
/** @var string $title */
/** @var string|null $message */
?>

<div class="panel panel-notice">
    <h1><?= $e($title) ?></h1>

    <?php if ($message !== null && $message !== ''): ?>
        <pre class="cmd"><?= $e($message) ?></pre>
    <?php endif; ?>

    <p><a href="/" class="btn"><?= $e($t('nav.overview')) ?></a></p>
</div>
