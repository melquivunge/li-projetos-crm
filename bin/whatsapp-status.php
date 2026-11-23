<?php
// Uso: php bin/whatsapp-status.php
// Diagnóstico do WhatsApp sem mostrar o conteúdo das mensagens: últimas entregas gravadas, corpo não reconhecido
// (só a estrutura de chaves) e falhas de entrega registradas pela DROPE.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\{Db, WhatsApp};

echo 'driver: ' . WhatsApp::driver() . ' | assinatura: ' . (\App\Finance::setting('wa_signing_secret') !== '' ? 'sim' : 'não') . "\n";
foreach (Db::q('SELECT m.id, m.chat_id, m.from_me, m.kind, m.status, CHAR_LENGTH(m.body) AS len, LEFT(m.provider_id, 12) AS pid, m.created_at FROM wa_messages m ORDER BY m.id DESC LIMIT 12') as $r)
    echo implode(' | ', $r) . "\n";
$f = storage_path('whatsapp-unparsed.json');
if (is_file($f)) {
    $keys = function ($v, string $p = '') use (&$keys): array { if (!is_array($v)) return [$p . ':' . gettype($v)]; $o = []; foreach ($v as $k => $x) $o = array_merge($o, $keys($x, $p . '.' . $k)); return $o; };
    echo 'não reconhecido em ' . date('d/m H:i', filemtime($f)) . ":\n" . implode("\n", array_slice($keys(json_decode((string) file_get_contents($f), true) ?? []), 0, 80)) . "\n";
} else echo "nenhum corpo não reconhecido\n";
$ch = curl_init(WhatsApp::base() . '/webhook/errors');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Accept: application/json', 'token: ' . env('DROPESTORE_TOKEN', '')]]);
$e = json_decode((string) curl_exec($ch), true);
curl_close($ch);
foreach (array_slice(is_array($e) ? ($e['data'] ?? $e) : [], 0, 8) as $x) if (is_array($x)) echo 'falha: ' . ($x['created'] ?? '') . ' ' . ($x['event'] ?? '') . ' HTTP ' . ($x['status_code'] ?? '') . ' ' . ($x['stage'] ?? '') . ' ' . mb_substr((string) ($x['error'] ?? ''), 0, 80) . "\n";
