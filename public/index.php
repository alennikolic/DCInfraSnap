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
use DcInfraSnap\Integration\Integration;
use DcInfraSnap\Integration\IntegrationRepository;
use DcInfraSnap\Inventory\InventoryQuery;
use DcInfraSnap\Inventory\ObjectType;
use DcInfraSnap\Project\ProjectRepository;
use DcInfraSnap\Report\DocxWriter;
use DcInfraSnap\Report\HtmlWriter;
use DcInfraSnap\Report\ReportBuilder;
use DcInfraSnap\Report\ReportQuery;
use DcInfraSnap\Run\RunRepository;
use DcInfraSnap\Source\Brocade\ClientFactory as BrocadeClientFactory;
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
    ['path' => '/san',          'key' => 'nav.san',          'enabled' => true],
    ['path' => '/zoning',       'key' => 'nav.zoning',       'enabled' => true],
    ['path' => '/runs',         'key' => 'nav.runs',         'enabled' => true],
    ['path' => '/reports',      'key' => 'nav.reports',      'enabled' => true],
    ['path' => '/projects',     'key' => 'nav.projects',     'enabled' => true],
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
} elseif ($path === '/projects') {
    $route = 'projects.index';
} elseif ($path === '/projects/add') {
    $route = 'projects.add';
} elseif (preg_match('#^/projects/(\d+)/(edit|sources|delete)$#', $path, $m) === 1) {
    $route = 'projects.' . $m[2];
    $id    = (int) $m[1];
} elseif ($path === '/reports') {
    // One address for the form and for generating from it. A POST that goes
    // wrong therefore renders the form again with the message on it, at the
    // address the person is already looking at.
    $route = 'reports.index';
} elseif ($path === '/vms/export') {
    $route = 'vms.export';
} elseif (preg_match('#^/(vms|hosts)/(\d+)$#', $path, $m) === 1) {
    $route = $m[1] . '.show';
    $id    = (int) $m[2];
} elseif (preg_match('#^/san/(\d+)$#', $path, $m) === 1) {
    $route = 'san.show';
    $id    = (int) $m[1];
} elseif (preg_match('#^/runs/(\d+)/reset$#', $path, $m) === 1) {
    $route = 'runs.reset';
    $id    = (int) $m[1];
} elseif (preg_match('#^/database/(\w+)$#', $path, $m) === 1) {
    $route = 'database.show';
    $table = $m[1];
} elseif (in_array($path, ['/vms', '/hosts', '/clusters', '/datastores', '/licenses',
                           '/san', '/zoning', '/runs', '/database'], true)) {
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

    // Back to the page the form belongs to, so the message is read where the
    // work was happening rather than on a page the person did not ask for.
    $redirect(match (true) {
        str_starts_with($route, 'projects.') => '/projects',
        str_starts_with($route, 'reports.')  => '/reports',
        default                              => '/integrations',
    });
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

        // Fibre Channel. Zones are counted from the defined configuration
        // only: the effective one holds the same zones again, and showing
        // both would double every figure.
        'switches'     => (int) $pdo->query('SELECT COUNT(*) FROM fc_switch')->fetchColumn(),
        'fc_ports'     => (int) $pdo->query(
            'SELECT COUNT(*) FROM fc_port WHERE port_type IS NOT NULL'
        )->fetchColumn(),
        'fc_devices'   => (int) $pdo->query('SELECT COUNT(*) FROM fc_device')->fetchColumn(),
        'zones'        => (int) $pdo->query(
            "SELECT COUNT(*) FROM fc_zone_object WHERE section = 'defined' AND kind = 'zone'"
        )->fetchColumn(),
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
        'devices' => $inventory->hostDevices((int) $host['integration_id'], (string) $host['moref']),
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

// --- Fibre Channel ---------------------------------------------------
if ($route === 'san.index') {
    $respond($view->render('san/index', [
        'rows'     => $inventory->fcSwitches(),
        'lastRuns' => $inventory->lastSuccessfulRuns(),
    ]));
}

if ($route === 'san.show') {
    $switch = $inventory->fcSwitch((int) $id);

    if ($switch === null) {
        $respond($view->render('error', [
            'title'   => $translator->t('error.not_found'),
            'message' => null,
        ]), 404);
    }

    $integrationId = (int) $switch['integration_id'];

    $respond($view->render('san/show', [
        'switch'  => $switch,
        'members' => $inventory->fcFabricMembers($integrationId),
        'ports'   => $inventory->fcPorts($integrationId),
        'devices' => $inventory->fcDevices($integrationId),
        'isls'    => $inventory->fcIsls($integrationId),
        'changes' => $inventory->changes(ObjectType::get('fc_switch'), (int) $id),
    ]));
}

if ($route === 'zoning.index') {
    // Defaults to the defined configuration: that is the one somebody wrote
    // and the one they will recognise.
    $section = ($_GET['section'] ?? '') === 'effective' ? 'effective' : 'defined';
    $kind    = (string) ($_GET['kind'] ?? '');
    $search  = trim((string) ($_GET['q'] ?? ''));

    $respond($view->render('zoning/index', [
        'rows'    => $inventory->fcZoning($section, ['kind' => $kind, 'search' => $search]),
        'section' => $section,
        'kind'    => in_array($kind, ['cfg', 'zone', 'alias'], true) ? $kind : '',
        'search'  => $search,
    ]));
}

// --- Collection history ---------------------------------------------
if ($route === 'runs.index') {
    $runRepository = new RunRepository($pdo);
    $recent        = $runRepository->recent(100);

    // The per-type outcome for the whole page in one query, rather than one
    // query per run.
    $respond($view->render('runs/index', [
        'rows'     => $recent,
        'runTypes' => $runRepository->typesFor(array_map(
            static fn (array $run): int => (int) $run['id'],
            $recent
        )),
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
// Projects
//
// A project is a grouping of sources and nothing more. No credential is
// involved, so this whole block sits above the point where the encryption
// key is needed: a broken SECRET_KEY stops collection, not the question of
// which sources belong together.
// =======================================================================
$projectRepo = new ProjectRepository($pdo);

if ($route === 'projects.index') {
    $respond($view->render('projects/index', [
        'projects'    => $projectRepo->all(),
        'sourceNames' => $projectRepo->sourceNamesByProject(),
    ]));
}

/**
 * Read and check the project form.
 *
 * Only the name is required. The other two are stored as NULL when left
 * empty rather than as an empty string, so "not given" has one
 * representation instead of two.
 *
 * @return array{values: array<string, string>, errors: list<string>}
 */
$readProjectForm = static function () use ($translator): array {
    $values = [
        'name'        => trim((string) ($_POST['name'] ?? '')),
        'client'      => trim((string) ($_POST['client'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
    ];

    $errors = [];

    if ($values['name'] === '') {
        $errors[] = $translator->t('projects.error_name');
    }

    return ['values' => $values, 'errors' => $errors];
};

// --- Add ---------------------------------------------------------------
if ($route === 'projects.add') {
    $values = [];
    $errors = [];

    if ($method === 'POST') {
        ['values' => $values, 'errors' => $errors] = $readProjectForm();

        if ($errors === []) {
            try {
                $projectRepo->create(
                    name: $values['name'],
                    client: $values['client'] === '' ? null : $values['client'],
                    description: $values['description'] === '' ? null : $values['description'],
                );

                Flash::success($translator->t('projects.added', ['name' => $values['name']]));
                Flash::info($translator->t('projects.added_hint'));

                $redirect('/projects');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    $respond($view->render('projects/form', [
        'project' => null,
        'values'  => $values,
        'errors'  => $errors,
    ]));
}

// --- Everything else about one project ---------------------------------
//
// Scoped to this block on purpose: the source routes below look up an
// integration by the same $id, and a project id is not an integration id.
if (str_starts_with($route, 'projects.')) {
    $project = $projectRepo->find((int) $id);

    if ($project === null) {
        $respond($view->render('error', [
            'title'   => $translator->t('error.not_found'),
            'message' => null,
        ]), 404);
    }

    // --- Edit ----------------------------------------------------------
    if ($route === 'projects.edit') {
        $values = [
            'name'        => $project->name,
            'client'      => $project->client ?? '',
            'description' => $project->description ?? '',
        ];

        $errors = [];

        if ($method === 'POST') {
            ['values' => $values, 'errors' => $errors] = $readProjectForm();

            if ($errors === []) {
                try {
                    $projectRepo->update(
                        id: $project->id,
                        name: $values['name'],
                        client: $values['client'] === '' ? null : $values['client'],
                        description: $values['description'] === '' ? null : $values['description'],
                    );

                    Flash::success($translator->t('projects.saved'));
                    $redirect('/projects');
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        $respond($view->render('projects/form', [
            'project' => $project,
            'values'  => $values,
            'errors'  => $errors,
        ]));
    }

    // --- Which sources it covers ---------------------------------------
    //
    // The form posts the whole set, so what is ticked when it is saved is
    // what the project consists of afterwards.
    if ($route === 'projects.sources') {
        if ($method === 'POST') {
            $posted = $_POST['sources'] ?? [];
            $posted = is_array($posted) ? $posted : [];

            try {
                $count = $projectRepo->setSources(
                    $project->id,
                    array_map('intval', array_values($posted))
                );

                Flash::success($translator->t('projects.sources_saved', [
                    'name'  => $project->name,
                    'count' => $count,
                ]));
            } catch (Throwable $e) {
                Flash::error($e->getMessage());
            }

            $redirect('/projects');
        }

        $respond($view->render('projects/sources', [
            'project'  => $project,
            'sources'  => $projectRepo->selectableSources(),
            'selected' => $projectRepo->sourceIds($project->id),
        ]));
    }

    // --- Delete --------------------------------------------------------
    if ($route === 'projects.delete') {
        if ($method === 'POST') {
            try {
                $projectRepo->delete($project->id);
                Flash::success($translator->t('projects.deleted', ['name' => $project->name]));
            } catch (Throwable $e) {
                Flash::error($e->getMessage());
            }

            $redirect('/projects');
        }

        $respond($view->render('projects/delete', [
            'project'     => $project,
            'sourceNames' => array_map(
                static fn (array $source): string => (string) $source['name'],
                $projectRepo->sources($project->id)
            ),
        ]));
    }
}

// =======================================================================
// Reports
//
// Reading and writing a document, no credentials involved, so this sits
// above the encryption key as well.
//
// GET renders the form; POST generates and streams the file. A POST that
// fails validation falls through to the same render with the messages on it,
// which is why there is no redirect in between: the ticked boxes survive.
// =======================================================================
if ($route === 'reports.index') {
    $errors = [];
    $values = ['scope' => 'project', 'project' => ''];

    $selectedSources = [];

    // Everything on by default. The boxes are there to leave a section out,
    // and the first report somebody generates should be the complete one.
    $selectedSections = ReportBuilder::allSections();

    if ($method === 'POST') {
        $values = [
            'scope'   => ($_POST['scope'] ?? 'project') === 'sources' ? 'sources' : 'project',
            'project' => trim((string) ($_POST['project'] ?? '')),
        ];

        $postedSources = is_array($_POST['sources'] ?? null)
            ? array_values(array_unique(array_map('intval', $_POST['sources'])))
            : [];

        // Only section keys this build knows. A posted name that is not one
        // of them is dropped rather than carried into the builder.
        $postedSections = is_array($_POST['sections'] ?? null)
            ? array_values(array_intersect(
                array_map('strval', $_POST['sections']),
                ReportBuilder::allSections()
            ))
            : [];

        $selectedSources  = $postedSources;
        $selectedSections = $postedSections;

        // The format rides on the submit button. Anything that is not one of
        // these is refused rather than quietly treated as HTML.
        $writer = match ((string) ($_POST['format'] ?? '')) {
            'html'  => new HtmlWriter(),
            'docx'  => new DocxWriter(),
            default => null,
        };

        if ($writer === null) {
            $errors[] = $translator->t('reports.error_format');
        }

        $project    = null;
        $reportIds  = [];

        if ($values['scope'] === 'sources') {
            $reportIds = $postedSources;

            if ($reportIds === []) {
                $errors[] = $translator->t('reports.error_no_sources');
            }
        } else {
            $project = $values['project'] === '' ? null : $projectRepo->find((int) $values['project']);

            if ($project === null) {
                $errors[] = $translator->t('reports.error_no_project');
            } else {
                $reportIds = $projectRepo->sourceIds($project->id);

                if ($reportIds === []) {
                    $errors[] = $translator->t('reports.error_project_empty', ['name' => $project->name]);
                }
            }
        }

        if ($postedSections === []) {
            $errors[] = $translator->t('reports.error_no_sections');
        }

        if ($errors === []) {
            $document = (new ReportBuilder(new ReportQuery($pdo), $translator))
                ->build($reportIds, $postedSections, $project);

            // No chapters means the chosen sources have never been collected
            // from. Generating the title page of an empty document would look
            // like a bug in the report rather than a missing collection.
            if ($document->chapters === []) {
                $errors[] = $translator->t('reports.error_nothing_collected');
            } else {
                $file = $writer->write($document);

                // Any buffer in front of this would hold the document twice
                // over for no reason.
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                // HTML is shown in a tab, because the browser is also where
                // it gets printed to PDF; a Word file can only be
                // downloaded. Ctrl+S saves the HTML under the same name.
                header('Content-Type: ' . $writer->contentType());
                header(
                    'Content-Disposition: ' . ($writer->isDownload() ? 'attachment' : 'inline')
                    . '; filename="' . $document->filename() . '.' . $writer->extension() . '"'
                );
                header('Content-Length: ' . strlen($file));
                header('Cache-Control: no-store');

                echo $file;

                exit;
            }
        }
    }

    $respond($view->render('reports/index', [
        'projects'            => $projectRepo->all(),
        'projectSourceCounts' => array_map('count', $projectRepo->sourceNamesByProject()),
        'sources'             => $projectRepo->selectableSources(),
        'values'              => $values,
        'selectedSources'     => $selectedSources,
        'selectedSections'    => $selectedSections,
        'errors'              => $errors,
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
    // The type decides the default port and which kind of peer verification
    // is on offer, so it is read first. Anything unrecognised falls back to
    // vCenter rather than reaching the database.
    $type = (string) ($_POST['type'] ?? Integration::TYPE_VCENTER);
    $type = isset(Integration::TYPES[$type]) ? $type : Integration::TYPE_VCENTER;

    $values = [
        'type'        => $type,
        'name'        => trim((string) ($_POST['name'] ?? '')),
        'hostname'    => trim((string) ($_POST['hostname'] ?? '')),
        'port'        => trim((string) ($_POST['port'] ?? (string) Integration::defaultPort($type))),
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

        // Which projects each source belongs to. One query for the page, and
        // the other direction of the same table the Projects page reads — a
        // source appearing under two projects is visible from here.
        'projectNames' => $projectRepo->projectNamesBySource(),
    ]));
}

// --- Add ---------------------------------------------------------------
if ($route === 'integrations.add') {
    // A type can be preselected by the link that got here, so the two buttons
    // on the Integrations page land on a form that is already right.
    $wanted = (string) ($_GET['type'] ?? Integration::TYPE_VCENTER);
    $wanted = isset(Integration::TYPES[$wanted]) ? $wanted : Integration::TYPE_VCENTER;

    $values = ['type' => $wanted, 'port' => (string) Integration::defaultPort($wanted)];
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
                    type: $values['type'],
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
    try {
        // A switch is reached over SSH and a vCenter over HTTPS, so the test
        // differs even though what gets recorded does not.
        if ($integration->isBrocade()) {
            $client = (new BrocadeClientFactory(timeout: 60))
                ->make($integration, $repo->password($integration->id));

            $result = $client->testConnection();

            $message = $translator->t('integrations.test_ok_switch', [
                'name'    => $result['name'] ?? '?',
                'version' => $result['fos_version'] ?? '?',
            ]);

            $repo->recordTest($integration->id, true, $message, $result['fos_version']);
            Flash::success($integration->name . ': ' . $message);

            $redirect('/integrations');
        }

        $client = (new ClientFactory(timeout: 30))
            ->make($integration, $repo->password($integration->id));

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
