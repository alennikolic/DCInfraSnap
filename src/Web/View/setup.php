<?php
// src/Web/View/setup.php
//
// Shown when the schema is missing. Normal right after installation, and
// right after a "docker compose down -v" — so it carries the command rather
// than a complaint.

/** @var callable $e */
/** @var callable $t */
?>

<div class="panel panel-notice">
    <h1><?= $e($t('setup.not_migrated.title')) ?></h1>
    <p><?= $e($t('setup.not_migrated.body')) ?></p>

    <pre class="cmd">docker compose exec app php bin/dcinfrasnap migrate</pre>
</div>
