<?php
// Uso: php bin/whatsapp-setup.php
// Cadastra (ou atualiza) o webhook do dispositivo na DROPE apontando para este CRM e guarda o segredo de assinatura
// em settings.wa_signing_secret. Rodar de novo é seguro (modo simples: um webhook por dispositivo).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\{Db, WhatsApp};

if (WhatsApp::driver() !== 'drope') { echo "RECUSADO: o webhook real só é cadastrado na produção (APP_ENV=production e WHATSAPP_DRIVER=drope).\n"; exit(1); }
$secret = (string) env('WHATSAPP_WEBHOOK_SECRET', '');
$app = rtrim((string) env('APP_URL', ''), '/');
if (strlen($secret) !== 64 || !str_starts_with($app, 'https://') || !env('DROPESTORE_TOKEN')) {
    echo "FALTA CONFIGURAR: APP_URL (https), WHATSAPP_WEBHOOK_SECRET (64 hex) e DROPESTORE_TOKEN no .env\n"; exit(1);
}
$ch = curl_init(WhatsApp::base() . '/webhook');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'token: ' . env('DROPESTORE_TOKEN')],
    CURLOPT_POSTFIELDS => json_encode(['enabled' => true, 'url' => "$app/api/whatsapp/$secret", 'events' => ['messages'],
        'excludeMessages' => ['wasSentByApi', 'isGroupYes'], 'addUrlEvents' => false, 'addUrlTypesMessages' => false])]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$r = json_decode($body, true);
$sign = $r['signing_secret'] ?? $r['data']['signing_secret'] ?? $r[0]['signing_secret'] ?? $r['data'][0]['signing_secret'] ?? null;
if ($code < 200 || $code >= 300) { echo "FALHOU: HTTP $code " . ($r['code'] ?? '') . ' ' . ($r['error'] ?? mb_substr($body, 0, 200)) . "\n"; exit(1); }
if ($sign) {
    Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['wa_signing_secret', (string) $sign]);
    echo "Webhook cadastrado; assinatura ativada.\n";
} else {
    echo "ATENÇÃO: a resposta não trouxe o signing_secret. Com o driver drope o CRM recusa entregas sem assinatura: confira o webhook no painel da DROPE.\n";
    echo 'Chaves da resposta: ' . implode(', ', array_keys(is_array($r) ? ($r['data'] ?? $r) : [])) . "\n";
}
