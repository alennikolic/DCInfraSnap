<?php
// config/bootstrap.php
//
// Shared start-up for both entry points (bin/dcinfrasnap and public/index.php).
// Registers class loading and returns the configuration array.
//
// Usage:
//   $config = require dirname(__DIR__) . '/config/bootstrap.php';

declare(strict_types=1);

$root = dirname(__DIR__);

// -----------------------------------------------------------------------
// Autoloading
//
// Composer is used when "composer install" has been run. Otherwise a minimal
// PSR-4 loader is registered, so the application works without a vendor/
// folder — there are no third-party dependencies, only PHP extensions.
// -----------------------------------------------------------------------
$composerAutoload = $root . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'DcInfraSnap\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = $root . '/src/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

// -----------------------------------------------------------------------
// Fail fast on a missing extension, with a message that says what to do.
// Without this, the first symptom is "Call to undefined function" somewhere
// deep in a collector.
// -----------------------------------------------------------------------
$missing = array_values(array_filter(
    ['pdo_mysql', 'sodium', 'curl', 'json', 'mbstring'],
    static fn (string $ext): bool => !extension_loaded($ext)
));

if ($missing !== []) {
    $message = 'Missing PHP extensions: ' . implode(', ', $missing)
        . '. Rebuild the image: docker compose up -d --build';

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    exit($message);
}

return require $root . '/config/config.php';
