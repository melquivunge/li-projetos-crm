<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Clients, Db, Notify, Opportunities};

/**
 * Public endpoints called by the website (liprojetos.com.br).
 * Body is JSON sent as text/plain (a "simple" CORS request: no preflight, works with keepalive/sendBeacon).
 * Guards: exact Origin, site key, honeypot, per-IP rate limit, field validation.
 * ponytail: the site key is visible in the site's JS; it only filters casual abuse. Origin + rate limit do the real work.
 */
final class ApiController
{
    public const EVENTS = ['whatsapp_click', 'schedule_cta_click'];
    private const LIMITS = ['lead' => 10, 'event' => 120]; // per IP per hour

    /** Maps the site's form options to the CRM's official services. */
    public static function mapService(string $s): ?string
    {
        foreach (['Projeto de Arquitetura' => 'Projetos de Arquitetura', 'Design de Interiores' => 'Design de Interiores', 'Plantas Baixas' => 'Plantas Baixas',
                  'Planta 3D' => 'Plantas Baixas', 'Consultoria' => 'Consultoria', 'Detalhamento' => 'Detalhamento Executivo'] as $prefix => $service) {
            if (str_starts_with($s, $prefix)) return $service;
        }
        return null;
    }

    private static function json(int $code, array $body = []): never
    {
        http_response_code($code);
        if ($code === 204) exit;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body ?: ['ok' => $code < 300]);
        exit;
    }

    /** Shared guards. Returns the decoded body. */
    private static function guard(string $bucket): array
    {
        // SITE_ORIGIN may list several origins, e.g. "https://liprojetos.com.br,https://www.liprojetos.com.br"
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) env('SITE_ORIGIN', '')))));
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if (!in_array($origin, $allowed, true)) self::json(403, ['error' => 'origin']);
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');

        $raw = file_get_contents('php://input', false, null, 0, 8192) ?: '';
        $in = json_decode($raw, true);
        if (!is_array($in)) self::json(400, ['error' => 'json']);
        $key = (string) env('SITE_API_KEY', '');
        if ($key === '' || !hash_equals($key, (string) ($in['key'] ?? ''))) self::json(403, ['error' => 'key']);
        if (!empty($in['website'])) self::json(202); // honeypot filled: pretend success, store nothing

        Db::q("INSERT INTO api_rate (ip, bucket, window_start, hits) VALUES (?, ?, NOW(), 1)
               ON DUPLICATE KEY UPDATE hits = IF(window_start < NOW() - INTERVAL 1 HOUR, 1, hits + 1),
                                       window_start = IF(window_start < NOW() - INTERVAL 1 HOUR, NOW(), window_start)", [client_ip(), $bucket]);
        $hits = (int) Db::q('SELECT hits FROM api_rate WHERE ip = ? AND bucket = ?', [client_ip(), $bucket])->fetchColumn();
        if ($hits > self::LIMITS[$bucket]) self::json(429, ['error' => 'rate']);
        return $in;
    }

    public static function lead(): void
    {
        $in = self::guard('lead');
        $type = ($in['type'] ?? '') === 'meeting_request' ? 'meeting_request' : 'contact_form';
        [$d, $errors] = Clients::validate([
            'name' => $in['name'] ?? '', 'whatsapp' => $in['phone'] ?? '', 'email' => $in['email'] ?? '',
            'city' => $in['city'] ?? '', 'area_m2' => preg_replace('/[^\d,.]/', '', (string) ($in['area'] ?? '')),
            'service' => self::mapService(trim((string) ($in['scope'] ?? ''))) ?? '',
            'origin' => ($in['origin'] ?? '') ?: 'site', 'gclid' => $in['gclid'] ?? '',
        ], requireContact: $type === 'contact_form');
        unset($errors['area_m2']); // a free-text area ("~90m²") must not lose the lead
        if ($errors) self::json(422, ['error' => 'fields', 'fields' => array_keys($errors)]);

        $lines = [$type === 'meeting_request' ? 'Pedido de reunião pelo site.' : 'Formulário de contato do site.'];
        foreach (['scope' => 'Tipo', 'date' => 'Data', 'time' => 'Horário', 'topic' => 'Assunto', 'message' => 'Mensagem'] as $k => $label) {
            $v = mb_substr(trim((string) ($in[$k] ?? '')), 0, 2000);
            if ($v !== '') $lines[] = "$label: $v";
        }
        $body = implode("\n", $lines);
        $consent = !empty($in['consent']) ? 1 : 0;

        // Same person again (WhatsApp or e-mail): add to the existing record instead of duplicating
        $existing = null;
        if ($d['whatsapp'] || $d['email']) {
            $existing = Db::q('SELECT id FROM clients WHERE (whatsapp = ? AND ? IS NOT NULL) OR (email = ? AND ? IS NOT NULL) ORDER BY id DESC LIMIT 1',
                [$d['whatsapp'], $d['whatsapp'], $d['email'], $d['email']])->fetchColumn() ?: null;
        }
        if ($existing) {
            $id = (int) $existing;
            Db::q('UPDATE clients SET gclid = COALESCE(gclid, ?), consent_at = COALESCE(consent_at, IF(?, NOW(), NULL)) WHERE id = ?', [$d['gclid'], $consent, $id]);
            $body = "Novo contato pelo site.\n" . $body;
        } else {
            Db::q('INSERT INTO clients (name, whatsapp, email, city, service, area_m2, origin, gclid, consent_at) VALUES (?,?,?,?,?,?,?,?, IF(?, NOW(), NULL))',
                [$d['name'], $d['whatsapp'], $d['email'], $d['city'], $d['service'], $d['area_m2'], $d['origin'], $d['gclid'], $consent]);
            $id = (int) Db::pdo()->lastInsertId();
        }
        if (!Db::q("SELECT 1 FROM opportunities WHERE client_id = ? AND stage IN ('novo','qualificado','briefing','proposta','negociacao')", [$id])->fetch()) {
            Opportunities::create($id, $d['service'] ?: ($type === 'meeting_request' ? 'Reunião pelo site' : 'Contato pelo site'), $d['service'], null);
        }
        Db::q("INSERT INTO client_events (client_id, type, body) VALUES (?, 'sistema', ?)", [$id, $body]);
        if ($d['whatsapp']) Db::q('UPDATE wa_chats SET client_id = ? WHERE phone = ? AND client_id IS NULL', [$id, $d['whatsapp']]);
        Db::q('INSERT INTO site_events (event, location, origin, client_id) VALUES (?, ?, ?, ?)', ['generate_lead', $type, $d['origin'], $id]);
        $owner = Db::q('SELECT owner_id FROM clients WHERE id = ?', [$id])->fetchColumn();
        Notify::clientTeam($owner ? (int) $owner : null, ($existing ? 'Novo contato de ' : 'Novo lead: ') . $d['name'] . ($type === 'meeting_request' ? ' (pedido de reunião)' : ''), "/clientes/$id");
        self::json(201);
    }

    public static function event(): void
    {
        $in = self::guard('event');
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, self::EVENTS, true)) self::json(422, ['error' => 'event']);
        Db::q('INSERT INTO site_events (event, location, origin) VALUES (?, ?, ?)', [
            $event,
            mb_substr(preg_replace('/[^\w\-]/', '', (string) ($in['location'] ?? '')), 0, 60) ?: null,
            mb_substr(trim((string) ($in['origin'] ?? '')), 0, 160) ?: null,
        ]);
        self::json(204);
    }
}
