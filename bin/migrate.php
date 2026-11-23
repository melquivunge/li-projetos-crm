<?php
// Uso: php bin/migrate.php [--baseline=NNN]
// Aplica, em ordem, as migrações de database/migrations ainda não registradas em schema_migrations.
// --baseline=008 marca 002..008 como já aplicadas sem rodar (banco criado pelo schema.sql antes deste script existir).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Db;

Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files);
$baseline = preg_match('/^--baseline=(\d{3})$/', $argv[1] ?? '', $m) ? $m[1] : null;
$done = Db::q('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $done, true)) continue;
    if ($baseline !== null && substr($name, 0, 3) <= $baseline) { Db::q('INSERT INTO schema_migrations (name) VALUES (?)', [$name]); echo "baseline: $name\n"; continue; }
    Db::pdo()->exec((string) file_get_contents($f));   // DDL commits implicitly in MySQL: a failure stops here, before recording
    Db::q('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
    echo "aplicada: $name\n"; $applied++;
}
echo $applied ? "$applied migração(ões) aplicada(s).\n" : "Nada a aplicar.\n";
