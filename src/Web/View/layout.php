<?php
// src/Web/View/layout.php
//
// The frame every page is rendered into: header, menu, language picker.
//
// The menu is handed in from index.php, grouped by where the data comes
// from, and runs down the left side. Pages that do not exist yet appear as
// disabled items rather than as links that lead nowhere.

/** @var callable $e */
/** @var callable $t */
/** @var string $locale */
/** @var string $body */
/** @var string $appName */
/** @var bool $isLoggedIn */
/** @var list<array{key: ?string, items: list<array{path: string, key: string, enabled: bool}>}> $nav */
/** @var string $currentPath */
/** @var array<string, string> $languages */
/** @var string $currentLocale */
/** @var string|null $csrfToken */
/** @var list<array{type: string, message: string}> $flashes */

use DcInfraSnap\Web\View;

$csrfToken ??= null;
$flashes ??= [];

// Which item is highlighted: the longest path that the current one starts
// with. Worked out once, because a plain "starts with" would light up both
// /hyperv and /hyperv/vms while looking at a machine.
$currentItem = '';

foreach ($nav as $group) {
    foreach ($group['items'] as $item) {
        $path = $item['path'];

        $isMatch = $path === $currentPath
            || ($path !== '/' && str_starts_with($currentPath, $path . '/'));

        if ($isMatch && strlen($path) > strlen($currentItem)) {
            $currentItem = $path;
        }
    }
}
?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($appName) ?></title>
    <link rel="stylesheet" href="<?= $e(View::asset('/assets/app.css')) ?>">
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/">
            <span class="brand-mark"><?= $e($appName) ?></span>
            <span class="brand-sub"><?= $e($t('app.tagline')) ?></span>
        </a>

        <div class="topbar-right">
            <?php if (count($languages) > 1): ?>
                <nav class="lang" aria-label="<?= $e($t('nav.language')) ?>">
                    <?php foreach ($languages as $code => $label): ?>
                        <a href="?lang=<?= $e($code) ?>"
                           class="lang-item<?= $code === $currentLocale ? ' is-current' : '' ?>"
                           title="<?= $e($label) ?>"><?= $e(strtoupper($code)) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php else: ?>
                <span class="lang-single" title="<?= $e(reset($languages) ?: 'English') ?>">
                    <?= $e(strtoupper($currentLocale)) ?>
                </span>
            <?php endif; ?>

            <?php if ($isLoggedIn && $csrfToken !== null): ?>
                <form method="post" action="/logout" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <button type="submit" class="btn btn-quiet"><?= $e($t('nav.logout')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="shell<?= $isLoggedIn ? '' : ' shell-plain' ?>">

    <?php if ($isLoggedIn): ?>
        <nav class="sidenav" aria-label="<?= $e($t('nav.overview')) ?>">
            <?php foreach ($nav as $group): ?>
                <div class="navgroup">
                    <?php if ($group['key'] !== null): ?>
                        <p class="navgroup-title"><?= $e($t($group['key'])) ?></p>
                    <?php endif; ?>

                    <?php foreach ($group['items'] as $item): ?>
                        <?php if ($item['enabled']): ?>
                            <a href="<?= $e($item['path']) ?>"
                               class="navitem<?= $item['path'] === $currentItem ? ' is-current' : '' ?>">
                                <?= $e($t($item['key'])) ?>
                            </a>
                        <?php else: ?>
                            <span class="navitem is-disabled"><?= $e($t($item['key'])) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <main class="content">
        <?php if (($flashes ?? []) !== []): ?>
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= $e($flash['type']) ?>"><?= $e($flash['message']) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?= $body ?>
    </main>

</div>

<footer class="footer">
    <span><?= $e($appName) ?></span>
</footer>

</body>
</html>
