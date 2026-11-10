<?php
// public/index.php
//
// The only file nginx will execute. Everything the browser asks for lands
// here and is routed from this one place.
//
// Step 1 covers: signing in, choosing a language, and an overview that shows
// an empty database honestly. The remaining pages are already in the menu,
// shown as not yet available, so the shape of the application is visible
// from the start.

declare(strict_types=1);

use DcInfraSnap\Core\Database;
use DcInfraSnap\I18n\Translator;
use DcInfraSnap\Web\Auth;
use DcInfraSnap\Web\View;

$config = require dirname(__DIR__) . '/config/bootstrap.php';

// -----------------------------------------------------------------------
// Request
// -----------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rtrim($path, '/') ?: '/';

$auth = new Auth((string) $config['password_hash'], (bool) $config['is_dev']);
$auth->startSession();

// -----------------------------------------------------------------------
// Language
//
// ?lang=de switches language and is remembered for the session. An unknown
// code is ignored rather than treated as an error — a stray value in a URL
// should not break the page. After switching, redirect to the same path
// without the parameter, so the choice does not ride along in every link.
// -----------------------------------------------------------------------
$requestedLocale = isset($_GET['lang']) ? (string) $_GET['lang'] : null;

if ($requestedLocale !== null) {
    // Only a language that actually exists is remembered. Anything else is
    // dropped and the current choice stays as it was — a mangled link should
    // not quietly put the interface back into English.
    if (isset(Translator::available($config['lang_dir'])[$requestedLocale])) {
        $_SESSION['locale'] = $requestedLocale;
    }

    header('Location: ' . $path, true, 303);
    exit;
}

$locale = Translator::resolve(
    $config['lang_dir'],
    isset($_SESSION['locale']) ? (string) $_SESSION['locale'] : null,
    (string) $config['locale'],
    (string) $config['fallback_locale']
);

$translator = new Translator(
    $config['lang_dir'],
    $locale,
    (string) $config['fallback_locale']
);

// -----------------------------------------------------------------------
// The menu.
//
// Pages arrive over the following steps; until then they appear disabled
// rather than as links that lead nowhere.
// -----------------------------------------------------------------------
$nav = [
    ['path' => '/',             'key' => 'nav.overview',     'enabled' => true],
    ['path' => '/vms',          'key' => 'nav.vms',          'enabled' => false],
    ['path' => '/hosts',        'key' => 'nav.hosts',        'enabled' => false],
    ['path' => '/clusters',     'key' => 'nav.clusters',     'enabled' => false],
    ['path' => '/datastores',   'key' => 'nav.datastores',   'enabled' => false],
    ['path' => '/licenses',     'key' => 'nav.licenses',     'enabled' => false],
    ['path' => '/runs',         'key' => 'nav.runs',         'enabled' => false],
    ['path' => '/integrations', 'key' => 'nav.integrations', 'enabled' => false],
    ['path' => '/database',     'key' => 'nav.database',     'enabled' => false],
];

$view = new View($config['views_dir'], $translator);
$view->share('nav', $nav)
     ->share('currentPath', $path)
     ->share('languages', Translator::available($config['lang_dir']))
     ->share('currentLocale', $locale)
     ->share('appName', (string) $config['app_name'])
     ->share('isLoggedIn', $auth->isLoggedIn());

/** Send a response and stop. */
$respond = static function (string $html, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
};

$redirect = static function (string $to): never {
    header('Location: ' . $to, true, 303);
    exit;
};

// =======================================================================
// Routes that do not require being signed in
// =======================================================================

// --- Sign in -----------------------------------------------------------
if ($path === '/login') {
    if ($auth->isLoggedIn()) {
        $redirect('/');
    }

    $error = null;

    if ($method === 'POST') {
        if (!$auth->csrfValid($_POST['csrf_token'] ?? null)) {
            $error = 'error.csrf';
        } elseif ($auth->lockedForSeconds() > 0) {
            $error = 'login.rate_limited';
        } elseif ($auth->attempt((string) ($_POST['password'] ?? ''))) {
            $redirect('/');
        } else {
            $error = $auth->lockedForSeconds() > 0
                ? 'login.rate_limited'
                : 'login.failed';
        }
    }

    $respond($view->render('login', [
        'configured' => $auth->isConfigured(),
        'error'      => $error,
        'csrfToken'  => $auth->csrfToken(),
    ]));
}

// --- Sign out ----------------------------------------------------------
if ($path === '/logout' && $method === 'POST') {
    if ($auth->csrfValid($_POST['csrf_token'] ?? null)) {
        $auth->logout();
    }

    $redirect('/login');
}

// =======================================================================
// Everything below requires being signed in
// =======================================================================
if (!$auth->isLoggedIn()) {
    $redirect('/login');
}

$view->share('isLoggedIn', true)
     ->share('csrfToken', $auth->csrfToken());

// -----------------------------------------------------------------------
// Unknown address: answered here, before the database is touched. A typo in
// a URL has no business opening a connection.
// -----------------------------------------------------------------------
$routes = ['/'];

if (!in_array($path, $routes, true)) {
    $respond($view->render('error', [
        'title'   => $translator->t('error.not_found'),
        'message' => null,
    ]), 404);
}

// -----------------------------------------------------------------------
// Database. A missing schema is a normal state right after installation,
// so it gets an explanation and the command to run, not a stack trace.
// -----------------------------------------------------------------------
try {
    $pdo       = Database::connect($config['db']);
    $isMigrated = Database::isMigrated($pdo);
} catch (Throwable $e) {
    $respond($view->render('error', [
        'title'   => $translator->t('error.server'),
        'message' => $config['is_dev'] ? $e->getMessage() : null,
    ]), 500);
}

if (!$isMigrated) {
    $respond($view->render('setup'));
}

// --- Overview ----------------------------------------------------------
if ($path === '/') {
    $counts = [
        'integrations' => (int) $pdo->query('SELECT COUNT(*) FROM integration')->fetchColumn(),
        'runs'         => (int) $pdo->query('SELECT COUNT(*) FROM discovery_run')->fetchColumn(),
        'clusters'     => (int) $pdo->query('SELECT COUNT(*) FROM vc_cluster')->fetchColumn(),
        'hosts'        => (int) $pdo->query('SELECT COUNT(*) FROM vc_host')->fetchColumn(),
        'vms'          => (int) $pdo->query('SELECT COUNT(*) FROM vc_vm')->fetchColumn(),
        'datastores'   => (int) $pdo->query('SELECT COUNT(*) FROM vc_datastore')->fetchColumn(),
        'licenses'     => (int) $pdo->query('SELECT COUNT(*) FROM vc_license')->fetchColumn(),
    ];

    $sources = $pdo->query(
        'SELECT id, name, hostname, port, is_active, last_tested_at, last_test_ok
           FROM integration
          ORDER BY name'
    )->fetchAll();

    $respond($view->render('overview/index', [
        'counts'  => $counts,
        'sources' => $sources,
    ]));
}

// Unreachable: $routes above is the single list of what exists, and anything
// not on it was answered with a 404 before the database was opened. If this
// line is ever reached, a route was added to that list without a handler.
$respond($view->render('error', [
    'title'   => $translator->t('error.server'),
    'message' => $config['is_dev'] ? 'Route "' . $path . '" is listed but has no handler.' : null,
]), 500);
