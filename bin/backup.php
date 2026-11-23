<?php
// Uso: php bin/backup.php   → storage/backups/AAAAMMDD-HHMMSS.sql.gz (fora do site). Guarda os 10 mais recentes.
// A senha vai por MYSQL_PWD (variável de ambiente do processo), nunca na linha de comando nem no cron.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$dir = storage_path('backups');
if (!is_dir($dir)) mkdir($dir, 0750, true);
$file = rtrim($dir, '/') . '/' . date('Ymd-His') . '.sql.gz';
$cmd = sprintf('mysqldump --single-transaction --routines --no-tablespaces --default-character-set=utf8mb4 -h %s -u %s %s | gzip > %s',
    escapeshellarg((string) env('DB_HOST')), escapeshellarg((string) env('DB_USER')), escapeshellarg((string) env('DB_NAME')), escapeshellarg($file));
$p = proc_open(['/bin/sh', '-c', 'set -o pipefail 2>/dev/null; ' . $cmd], [2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => (string) env('DB_PASS'), 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
$err = stream_get_contents($pipes[2]); fclose($pipes[2]);
$code = proc_close($p);
$size = is_file($file) ? filesize($file) : 0;
if ($code !== 0 || $size < 1000) { if (is_file($file)) unlink($file); echo "FALHOU ($code): " . trim((string) $err) . "\n"; exit(1); }
$all = glob(rtrim($dir, '/') . '/*.sql.gz') ?: []; rsort($all);
foreach (array_slice($all, 10) as $old) unlink($old);
echo 'backup: ' . basename($file) . ' (' . round($size / 1024) . " KB)\n";
