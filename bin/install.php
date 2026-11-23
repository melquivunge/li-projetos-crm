<?php
// Uso: php bin/install.php — cria as tabelas num banco vazio a partir de database/schema.sql. Não faz nada se já houver tabelas.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Db;
if (Db::q("SHOW TABLES LIKE 'users'")->fetch()) { echo "Banco já instalado.\n"; exit(0); }
Db::pdo()->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
// schema.sql already contains every migration: record them so bin/migrate.php only runs future ones
Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $f) Db::q('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [basename($f)]);
echo 'Instalado: ' . count(Db::q('SHOW TABLES')->fetchAll()) . " tabelas. PHP " . PHP_VERSION . "\n";
