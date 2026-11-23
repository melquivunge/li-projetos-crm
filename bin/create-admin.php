<?php
// Uso: php bin/create-admin.php "Nome" email   (senha via ADMIN_PASSWORD ou digitada sem eco)
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
[$_, $name, $email] = $argv + [null, null, null];
if (!$name || !filter_var((string) $email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Uso: php bin/create-admin.php \"Nome\" email\n"); exit(1); }
$pass = getenv('ADMIN_PASSWORD') ?: '';
if ($pass === '') { echo "Senha (mín. 10): "; system('stty -echo'); $pass = trim((string) fgets(STDIN)); system('stty echo'); echo "\n"; }
if (strlen($pass) < 10) { fwrite(STDERR, "Senha curta demais.\n"); exit(1); }
App\Db::q("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'admin') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='admin', active=1",
    [$name, mb_strtolower((string) $email), password_hash($pass, PASSWORD_DEFAULT)]);
echo "Admin pronto: $email\n";
