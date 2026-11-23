<?php
// Uso: php bin/install.php — cria as tabelas num banco vazio a partir de database/schema.sql. Não faz nada se já houver tabelas.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Db;
if (Db::q("SHOW TABLES LIKE 'users'")->fetch()) { echo "Banco já instalado.\n"; exit(0); }
Db::pdo()->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
echo 'Instalado: ' . count(Db::q('SHOW TABLES')->fetchAll()) . " tabelas. PHP " . PHP_VERSION . "\n";
