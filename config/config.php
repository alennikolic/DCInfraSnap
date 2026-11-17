<?php
// config/config.php
//
// The only place where environment variables are read. Everything else takes
// the array this file returns.
//
// Values come from the container environment, which docker-compose fills in
// from .env. Nothing is read from .env directly, so the application behaves
// the same way when it is run some other way.

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Read an environment variable, with a default.
 *
 * getenv() is used rather than $_ENV because php-fpm does not populate
 * $_ENV unless variables_order says so.
 */
$env = static function (string $name, ?string $default = null): ?string {
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

return [
    // --- Application --------------------------------------------------
    'app_name' => 'DCInfraSnap',
    'env'      => $env('APP_ENV', 'dev'),
    'is_dev'   => $env('APP_ENV', 'dev') === 'dev',

    // --- Paths --------------------------------------------------------
    'root'           => $root,
    'migrations_dir' => $root . '/db/migrations',
    'lang_dir'       => $root . '/lang',
    'views_dir'      => $root . '/src/Web/View',

    // --- Language -----------------------------------------------------
    // The default locale, and the one every other locale falls back to for
    // strings it has not translated. English stays the fallback: it is the
    // language the keys are written in and the only one guaranteed complete.
    'locale'          => $env('APP_LOCALE', 'en'),
    'fallback_locale' => 'en',

    // --- Database -----------------------------------------------------
    'db' => [
        'host'     => $env('DB_HOST', 'db'),
        'port'     => (int) $env('DB_PORT', '3306'),
        'name'     => $env('DB_NAME', 'dcinfrasnap'),
        'user'     => $env('DB_USER', 'dcinfrasnap'),
        'password' => $env('DB_PASSWORD', ''),
    ],

    // --- Secrets ------------------------------------------------------
    // 32 bytes in base64, used to encrypt stored credentials.
    'secret_key' => $env('SECRET_KEY'),

    // bcrypt hash of the GUI password. Empty means the GUI stays closed.
    'password_hash' => $env('APP_PASSWORD_HASH', ''),
];
