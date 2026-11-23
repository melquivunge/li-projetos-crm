<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require __DIR__ . '/helpers.php';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) require $file;
        }
    });
}

// .env only fills values not already provided by the environment
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}

date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', env('APP_ENV') === 'local' ? '1' : '0');

if (PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // The client portal has its own cookie: a client never shares a session (or CSRF token) with the team
    $portal = (bool) preg_match('#^/portal(/|$|\?)#', (string) ($_SERVER['REQUEST_URI'] ?? ''));
    session_set_cookie_params(['lifetime' => 0, 'path' => $portal ? '/portal' : '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_name($portal ? 'li_portal' : 'li_crm');
    ini_set('session.use_strict_mode', '1');
    session_start();

    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if ($https) header('Strict-Transport-Security: max-age=31536000');
}
