<?php
// public/index.php
//
// The only file nginx will execute. Everything the browser asks for lands
// here and is routed from this one place.
//
// Steps so far: signing in, language, overview, and sources (step 2).
// The inventory pages arrive in step 4 and are shown in the menu as not yet
// available, so the shape of the application is visible from the start.

declare(strict_types=1);

use DcInfraSnap\Core\Database;
use DcInfraSnap\Core\Secret;
use DcInfraSnap\I18n\Translator;
use DcInfraSnap\Integration\IntegrationRepository;
use DcInfraSnap\Inventory\InventoryQuery;
use DcInfraSnap\Inventory\ObjectType;
use DcInfraSnap\Run\RunRepository;
use DcInfraSnap\Source\VCenter\ClientFactory;
use DcInfraSnap\Source\VCenter\VCenterException;
use DcInfraSnap\Web\Auth;
use DcInfraSnap\Web\Csv;
use DcInfraSnap\Web\Flash;
use DcInfraSnap\Web\Format;
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
// ?lang=de switches language and is remembered for the session. Only a
// language that actually exists is remembered; anything else is dropped and
// the current choice stays as it was.
// -----------------------------------------------------------------------
$requestedLocale = isset($_GET['lang']) ? (string) $_GET['lang'] : null;

if ($requestedLocale !== null) {
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
// The menu. Pages arrive over the following steps; until then they appear
// disabled rather than as links that lead nowhere.
// -----------------------------------------------------------------------
$nav = [
    ['path' => '/',             'key' => 'nav.overview',     'enabled' => true],
    ['path' => '/vms',          'key' => 'nav.vms',          'enabled' => true],
    ['path' => '/hosts',        'key' => 'nav.hosts',        'enabled' => true],
    ['path' => '/clusters',     'key' => 'nav.clusters',     'enabled' => true],
    ['path' => '/datastores',   'key' => 'nav.datastores',   'enabled' => true],
    ['path' => '/licenses',     'key' => 'nav.licenses',     'enabled' => true],
    ['path' => '/runs',         'key' => 'nav.runs',         'enabled' => true],
    ['path' => '/integrations', 'key' => 'nav.integrations', 'enabled' => true],
    ['path' => '/database',     'key' => 'nav.database',     'enabled' => true],
];

$view = new View($config['views_dir'], $translator);
$view->share('nav', $nav)
     ->share('currentPath', $path)
     ->share('languages', Translator::available($config['lang_dir']))
     ->share('currentLocale', $locale)
     ->share('appName', (string) $config['app_name'])
     ->share('isLoggedIn', $auth->isLoggedIn())
     ->share('flashes', []);

/**
 * The machine filters, read from the query string.
 *
 * Defined once because the list and the export must agree: an export that
 * quietly ignored a filter would hand someone a file that does not match
 * what they were looking at.
 *
 * @return array<string, string>
 */
function self_vm_filters(): array
{
    $filters = [];

    foreach (['q', 'integration', 'power', 'cluster', 'host', 'template', 'missing'] as $name) {
        $value = trim((string) ($_GET[$name] ?? ''));

        if ($value !== '') {
            $filters[$name] = $value;
        }
    }

    return $filters;
}

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
            $error = $auth->lockedForSeconds() > 0 ? 'login.rate_limited' : 'login.failed';
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

$csrfToken = $auth->csrfToken();

$view->share('isLoggedIn', true)
     ->share('csrfToken', $csrfToken)
     ->share('flashes', Flash::take());

// -----------------------------------------------------------------------
// Match the address before the database is opened. A typo in a URL has no
// business making a connection.
//
// $route ends up as a name, and $id as the source it refers to, if any.
// -----------------------------------------------------------------------
$route = null;
$id    = null;

if ($path === '/') {
    $route = 'overview';
} elseif ($path === '/integrations') {
    $route = 'integrations.index';
} elseif ($path === '/integrations/add') {
    $route = 'integrations.add';
} elseif (preg_match('#^/integrations/(\d+)/(edit|password|delete|test|toggle|collect)$#', $path, $m) === 1) {
    $route = 'integrations.' . $m[2];
    $id    = (int) $m[1];
} elseif ($path === '/vms/export') {
    $route = 'vms.export';
} elseif (preg_match('#^/(vms|hosts)/(\d+)$#', $path, $m) === 1) {
    $route = $m[1] . '.show';
    $id    = (int) $m[2];
} elseif (preg_match('#^/runs/(\d+)/reset$#', $path, $m) === 1) {
    $route = 'runs.reset';
    $id    = (int) $m[1];
} elseif (preg_match('#^/database/(\w+)$#', $path, $m) === 1) {
    $route = 'database.show';
    $table = $m[1];
} elseif (in_array($path, ['/vms', '/hosts', '/clusters', '/datastores', '/licenses', '/runs', '/database'], true)) {
    $route = ltrim($path, '/') . '.index';
}

if ($route === null) {
    $respond($view->render('error', [
        'title'   => $translator->t('error.not_found'),
        'message' => null,
    ]), 404);
}

// Actions that change something are POST only, so they cannot be triggered
// by a link, a prefetch or an image tag.
if (in_array($route, ['integrations.test', 'integrations.toggle', 'integrations.collect', 'runs.reset'], true)
    && $method !== 'POST') {
    $respond($view->render('error', [
        'title'   => $translator->t('error.not_found'),
        'message' => null,
    ]), 405);
}

// Every POST carries the session token.
if ($method === 'POST' && !$auth->csrfValid($_POST['csrf_token'] ?? null)) {
    Flash::error($translator->t('error.csrf'));
    $redirect($path === '/integrations/add' ? '/integrations' : '/integrations');
}

// -----------------------------------------------------------------------
// Database. A missing schema is a normal state right after installation, so
// it gets an explanation and the command to run, not a stack trace.
// -----------------------------------------------------------------------
try {
    $pdo        = Database::connect($config['db']);
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

// -----------------------------------------------------------------------
// Overview
// -----------------------------------------------------------------------
if ($route === 'overview') {
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

// =======================================================================
// Inventory
// =======================================================================
$inventory = new InventoryQuery($pdo);

if (str_starts_with($route, 'vms.')) {
    // --- Export ---------------------------------------------------------
    if ($route === 'vms.export') {
        $filters = self_vm_filters();

        Csv::send(
            Csv::filename('virtual-machines'),
            [
                'name', 'power_state', 'is_template', 'guest_configured', 'guest_reported',
                'guest_hostname', 'guest_ip_address', 'instance_uuid', 'bios_uuid',
                'cpu_count', 'cpu_cores_per_socket', 'memory_mib',
                'disk_count', 'disk_capacity_bytes', 'nic_count',
                'host', 'cluster', 'folder_path', 'source',
                'first_seen_utc', 'last_seen_utc', 'notes',
            ],
            $inventory->vmsForExport($filters),
            static fn (array $r): array => [
                $r['name'], $r['power_state'], $r['is_template'],
                $r['guest_os'], $r['guest_full_name'],
                $r['guest_hostname'], $r['guest_ip_address'],
                $r['instance_uuid'], $r['bios_uuid'],
                $r['cpu_count'], $r['cpu_cores_per_socket'], $r['memory_mib'],
                $r['disk_count'], $r['disk_capacity_bytes'], $r['nic_count'],
                $r['host_name'], $r['cluster_name'], $r['folder_path'], $r['integration_name'],
                $r['first_seen'], $r['last_seen'], $r['notes'],
            ]
        );
    }

    // --- One machine -----------------------------------------------------
    if ($route === 'vms.show') {
        $vm = $inventory->vm((int) $id);

        if ($vm === null) {
            $respond($view->render('error', [
                'title'   => $translator->t('error.not_found'),
                'message' => null,
            ]), 404);
        }

        $respond($view->render('vms/show', [
            'vm'      => $vm,
            'changes' => $inventory->changes(ObjectType::get('vm'), (int) $id),
        ]));
    }

    // --- The list --------------------------------------------------------
    $filters = self_vm_filters();
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $result  = $inventory->vms($filters, $page);

    $respond($view->render('vms/index', [
        'rows'     => $result['rows'],
        'total'    => $result['total'],
        'page'     => $page,
        'pages'    => max(1, (int) ceil($result['total'] / InventoryQuery::PER_PAGE)),
        'filters'  => $filters,
        'options'  => $inventory->vmFilterOptions(),
        'lastRuns' => $inventory->lastSuccessfulRuns(),
    ]));
}

if ($route === 'hosts.show') {
    $host = $inventory->host((int) $id);

    if ($host === null) {
        $respond($view->render('error', [
            'title'   => $translator->t('error.not_found'),
            'message' => null,
        ]), 404);
    }

    $respond($view->render('hosts/show', [
        'host'    => $host,
        'vms'     => $inventory->vmsOnHost((int) $host['integration_id'], (string) $host['moref']),
        'changes' => $inventory->changes(ObjectType::get('host'), (int) $id),
    ]));
}

if ($route === 'hosts.index') {
    $respond($view->render('hosts/index', [
        'rows'     => $inventory->hosts(),
        'lastRuns' => $inventory->lastSuccessfulRuns(),
    ]));
}

if ($route === 'clusters.index') {
    $respond($view->render('clusters/index', ['rows' => $inventory->clusters()]));
}

if ($route === 'datastores.index') {
    $respond($view->render('datastores/index', ['rows' => $inventory->datastores()]));
}

if ($route === 'licenses.index') {
    $respond($view->render('licenses/index', ['rows' => $inventory->licenses()]));
}

// --- Collection history ---------------------------------------------
if ($route === 'runs.index') {
    $respond($view->render('runs/index', [
        'rows' => (new RunRepository($pdo))->recent(100),
    ]));
}

if ($route === 'runs.reset') {
    $runs = new RunRepository($pdo);

    if ($runs->reset((int) $id)) {
        Flash::success($translator->t('runs.released', ['run' => (int) $id]));
    } else {
        Flash::error($translator->t('runs.not_releasable', ['run' => (int) $id]));
    }

    $redirect('/runs');
}

// --- The database ----------------------------------------------------
if ($route === 'database.index') {
    $respond($view->render('database/index', [
        'database' => (string) $config['db']['name'],
        'tables'   => $inventory->tables((string) $config['db']['name']),
    ]));
}

if ($route === 'database.show') {
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $result = $inventory->tableRows((string) $config['db']['name'], $table ?? '', $page);

    if ($result === null) {
        $respond($view->render('error', [
            'title'   => $translator->t('error.not_found'),
            'message' => null,
        ]), 404);
    }

    $respond($view->render('database/show', [
        'table'   => $table,
        'columns' => $result['columns'],
        'rows'    => $result['rows'],
        'total'   => $result['total'],
        'page'    => $page,
        'pages'   => max(1, (int) ceil($result['total'] / 50)),
    ]));
}

// =======================================================================
// Sources
//
// Everything below needs the encryption key. A missing or malformed
// SECRET_KEY is a configuration problem, and saying so beats an exception
// from somewhere inside the repository.
// =======================================================================
try {
    $secret = new Secret($config['secret_key']);
} catch (Throwable $e) {
    $respond($view->render('error', [
        'title'   => $translator->t('error.server'),
        'message' => $e->getMessage(),
    ]), 500);
}

$repo = new IntegrationRepository($pdo, $secret);

/**
 * The source this route refers to, or a 404.
 */
$requireIntegration = static function (?int $id) use ($repo, $view, $translator, $respond) {
    $integration = $id === null ? null : $repo->find($id);

    if ($integration === null) {
        $respond($view->render('error', [
            'title'   => $translator->t('error.not_found'),
            'message' => null,
        ]), 404);
    }

    return $integration;
};

/**
 * Read and check the form. Returns the values and the problems with them.
 *
 * @return array{values: array<string, string>, errors: list<string>}
 */
$readForm = static function (bool $requirePassword) use ($translator): array {
    $values = [
        'name'        => trim((string) ($_POST['name'] ?? '')),
        'hostname'    => trim((string) ($_POST['hostname'] ?? '')),
        'port'        => trim((string) ($_POST['port'] ?? '443')),
        'username'    => trim((string) ($_POST['username'] ?? '')),
        'verify_peer' => isset($_POST['verify_peer']) ? '1' : '0',
    ];

    $errors = [];

    if ($values['name'] === '') {
        $errors[] = $translator->t('integrations.error_name');
    }

    if ($values['hostname'] === '') {
        $errors[] = $translator->t('integrations.error_hostname');
    } elseif (preg_match('#^https?://#i', $values['hostname']) === 1) {
        // People paste the whole URL out of the browser. Saying so is more
        // use than letting the connection fail later with a DNS error.
        $errors[] = $translator->t('integrations.error_hostname_url');
    }

    $port = (int) $values['port'];

    if ($port < 1 || $port > 65535) {
        $errors[] = $translator->t('integrations.error_port');
    }

    if ($values['username'] === '') {
        $errors[] = $translator->t('integrations.error_username');
    }

    if ($requirePassword && (string) ($_POST['password'] ?? '') === '') {
        $errors[] = $translator->t('integrations.error_password');
    }

    return ['values' => $values, 'errors' => $errors];
};

// --- The list ----------------------------------------------------------
if ($route === 'integrations.index') {
    $respond($view->render('integrations/index', [
        'integrations' => $repo->all(),
    ]));
}

// --- Add ---------------------------------------------------------------
if ($route === 'integrations.add') {
    $values = ['port' => '443'];
    $errors = [];

    if ($method === 'POST') {
        ['values' => $values, 'errors' => $errors] = $readForm(true);

        if ($errors === []) {
            try {
                $newId = $repo->create(
                    name: $values['name'],
                    hostname: $values['hostname'],
                    username: $values['username'],
                    password: (string) $_POST['password'],
                    port: (int) $values['port'],
                    verifyPeer: $values['verify_peer'] === '1',
                );

                Flash::success($translator->t('integrations.added', ['name' => $values['name']]));
                Flash::info($translator->t('integrations.added_hint'));

                $redirect('/integrations');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    $respond($view->render('integrations/form', [
        'integration' => null,
        'values'      => $values,
        'errors'      => $errors,
    ]));
}

$integration = $requireIntegration($id);

// --- Edit --------------------------------------------------------------
if ($route === 'integrations.edit') {
    $values = [
        'name'        => $integration->name,
        'hostname'    => $integration->hostname,
        'port'        => (string) $integration->port,
        'username'    => $integration->username,
        'verify_peer' => $integration->verifyPeer ? '1' : '0',
    ];

    $errors = [];

    if ($method === 'POST') {
        ['values' => $values, 'errors' => $errors] = $readForm(false);

        if ($errors === []) {
            try {
                $repo->update(
                    id: $integration->id,
                    name: $values['name'],
                    hostname: $values['hostname'],
                    port: (int) $values['port'],
                    username: $values['username'],
                    verifyPeer: $values['verify_peer'] === '1',
                );

                Flash::success($translator->t('integrations.saved'));
                $redirect('/integrations');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    $respond($view->render('integrations/form', [
        'integration' => $integration,
        'values'      => $values,
        'errors'      => $errors,
    ]));
}

// --- Change the password -----------------------------------------------
if ($route === 'integrations.password') {
    $errors = [];

    if ($method === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $repeat   = (string) ($_POST['password_repeat'] ?? '');

        if ($password === '') {
            $errors[] = $translator->t('integrations.error_password');
        } elseif (!hash_equals($password, $repeat)) {
            $errors[] = $translator->t('integrations.error_password_match');
        }

        if ($errors === []) {
            $repo->updatePassword($integration->id, $password);

            Flash::success($translator->t('integrations.password_saved'));
            Flash::info($translator->t('integrations.added_hint'));

            $redirect('/integrations');
        }
    }

    $respond($view->render('integrations/password', [
        'integration' => $integration,
        'errors'      => $errors,
    ]));
}

// --- Test the connection -----------------------------------------------
if ($route === 'integrations.test') {
    $factory = new ClientFactory(timeout: 30);

    try {
        $client = $factory->make($integration, $repo->password($integration->id));
        $result = $client->testConnection();

        $message = $translator->t('integrations.test_ok_message', [
            'version' => $result['full_name'] ?? $result['version'] ?? '?',
        ]);

        $repo->recordTest($integration->id, true, $message, $result['version']);
        Flash::success($integration->name . ': ' . $message);
    } catch (VCenterException $e) {
        $repo->recordTest($integration->id, false, $e->getMessage());
        Flash::error($integration->name . ': ' . $e->getMessage());
    } catch (Throwable $e) {
        // Anything else is ours, not the vCenter's — a missing key, most
        // likely. It is recorded the same way so the row does not keep
        // showing a stale success.
        $repo->recordTest($integration->id, false, $e->getMessage());
        Flash::error($integration->name . ': ' . $e->getMessage());
    }

    $redirect('/integrations');
}

// --- Queue a collection ------------------------------------------------
//
// The GUI only queues; the worker does the collecting. A large vCenter takes
// minutes, which would outlast the web request — and a browser tab is a poor
// place to hold a job that must not be interrupted.
if ($route === 'integrations.collect') {
    $runs = new RunRepository($pdo);

    try {
        $runId = $runs->queue($integration->id, 'gui');

        Flash::success($translator->t('integrations.queued', [
            'name' => $integration->name,
            'run'  => $runId,
        ]));
    } catch (Throwable $e) {
        Flash::error($integration->name . ': ' . $e->getMessage());
    }

    $redirect('/integrations');
}

// --- Enable / disable --------------------------------------------------
if ($route === 'integrations.toggle') {
    $repo->setActive($integration->id, !$integration->isActive);

    Flash::success($translator->t(
        $integration->isActive ? 'integrations.disabled_now' : 'integrations.enabled_now',
        ['name' => $integration->name]
    ));

    $redirect('/integrations');
}

// --- Delete ------------------------------------------------------------
if ($route === 'integrations.delete') {
    if ($method === 'POST') {
        try {
            $repo->delete($integration->id);
            Flash::success($translator->t('integrations.deleted', ['name' => $integration->name]));
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }

        $redirect('/integrations');
    }

    $respond($view->render('integrations/delete', [
        'integration' => $integration,
        'counts'      => $repo->collectedCounts($integration->id),
    ]));
}

// Unreachable: the matcher above is the single list of what exists. If this
// line is ever reached, a route was added there without a handler.
$respond($view->render('error', [
    'title'   => $translator->t('error.server'),
    'message' => $config['is_dev'] ? 'Route "' . $route . '" is matched but has no handler.' : null,
]), 500);
