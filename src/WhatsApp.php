<?php
declare(strict_types=1);
namespace App;

/**
 * WhatsApp provider (DROPE, unofficial API: https://dropestore.com/whatsapp-api/documentacao/). The only file that knows the provider's format.
 * WHATSAPP_DRIVER: "drope" (real), "teste" (records, sends nothing) or empty (inbox is read-only).
 * Send: POST {DROPESTORE_URL}/send/text, header "token" (device token dwa_...). Receive: webhook signed with X-DROPE-Signature.
 * ponytail: DROPE documents no sample of the incoming "messages" body; parse() reads the UAZAPI shape (same field names as
 * DROPE's send response) and also Evolution/Z-API. An unknown body is kept in storage/whatsapp-unparsed.json to adjust.
 */
final class WhatsApp
{
    public static function driver(): string { return (string) env('WHATSAPP_DRIVER', ''); }

    public static function canSend(): bool { return in_array(self::driver(), ['drope', 'teste'], true); }

    /** Pure: provider JID/number → "+55DDD9XXXXXXXX". Brazilian mobiles often arrive without the 9th digit. Null for groups/invalid. */
    public static function phone(string $raw): ?string
    {
        if (preg_match('/@(g\.us|broadcast|newsletter|lid)$/', $raw)) return null;
        $d = preg_replace('/\D/', '', explode('@', $raw)[0]);
        if (preg_match('/^55([1-9][0-9])([6-9][0-9]{7})$/', $d, $m)) $d = '55' . $m[1] . '9' . $m[2];
        if (str_starts_with($d, '55')) return Clients::normalizeWhatsapp($d);
        return strlen($d) >= 8 && strlen($d) <= 15 ? '+' . $d : null; // foreign number
    }

    /**
     * Pure: webhook body → messages. Accepts Evolution API (event messages.upsert) and Z-API style bodies.
     * @return list<array{phone:string, name:?string, text:string, kind:string, id:?string, from_me:bool, at:?int}>
     */
    public static function parse(array $p, string $event = ''): array
    {
        $out = [];
        $event = strtolower($event ?: (string) ($p['EventType'] ?? $p['event'] ?? ''));
        // DROPE / UAZAPI: { EventType: "messages", message: { chatid, messageid, fromMe, isGroup, senderName, text, messageType, messageTimestamp(ms), sender_pn } }
        $dm = null;
        foreach ([$p['message'] ?? null, $p['data'] ?? null, $p] as $c) if (is_array($c) && isset($c['messageid'])) { $dm = $c; break; }
        if ($dm) {
            if ($event !== '' && $event !== 'messages') return [];
            if (!empty($dm['isGroup'])) return [];
            $jid = (string) ($dm['chatid'] ?? $p['chat']['wa_chatid'] ?? '');
            if (str_ends_with($jid, '@lid') || $jid === '') $jid = (string) ($dm['sender_pn'] ?? $p['chat']['phone'] ?? '');
            $phone = self::phone($jid);
            if (!$phone) return [];
            $type = strtolower((string) ($dm['messageType'] ?? 'text'));
            $kind = match (true) {
                str_contains($type, 'image') || str_contains($type, 'sticker') => 'imagem', str_contains($type, 'audio') || str_contains($type, 'ptt') => 'audio',
                str_contains($type, 'video') => 'video', str_contains($type, 'document') => 'documento',
                in_array($type, ['text', 'conversation', 'extendedtextmessage', 'chat'], true) => 'texto', default => 'outro' };
            $text = $dm['text'] ?? (is_array($dm['content'] ?? null) ? ($dm['content']['text'] ?? $dm['content']['caption'] ?? '') : ($dm['content'] ?? ''));
            $text = mb_substr(trim((string) $text), 0, 4000);
            if ($text === '' && $kind === 'outro') return []; // reactions, polls, protocol messages
            if ($text === '' && $kind === 'texto') return [];
            $ts = isset($dm['messageTimestamp']) ? (int) $dm['messageTimestamp'] : null;
            return [['phone' => $phone, 'name' => !empty($dm['fromMe']) ? null : self::clean($dm['senderName'] ?? $p['chat']['name'] ?? null), 'text' => $text, 'kind' => $kind,
                'id' => mb_substr((string) $dm['messageid'], 0, 120), 'from_me' => !empty($dm['fromMe']), 'at' => $ts && $ts > 99999999999 ? intdiv($ts, 1000) : $ts]];
        }
        if (isset($p['data']) && is_array($p['data'])) { // Evolution
            if ($event !== '' && str_replace('_', '.', $event) !== 'messages.upsert') return [];
            $list = isset($p['data']['key']) ? [$p['data']] : ($p['data']['messages'] ?? (array_is_list($p['data']) ? $p['data'] : []));
            foreach ($list as $m) {
                if (!is_array($m) || !isset($m['key'])) continue;
                $k = $m['key'];
                $jid = (string) ($k['remoteJid'] ?? '');
                if (str_ends_with($jid, '@lid')) $jid = (string) ($k['remoteJidAlt'] ?? $k['senderPn'] ?? $m['senderPn'] ?? '');
                $phone = self::phone($jid);
                if (!$phone) continue;
                [$text, $kind] = self::content((array) ($m['message'] ?? []));
                if ($text === '' && $kind === 'outro') continue; // reactions, receipts, protocol messages
                $out[] = ['phone' => $phone, 'name' => !empty($k['fromMe']) ? null : self::clean($m['pushName'] ?? null), 'text' => $text, 'kind' => $kind,
                    'id' => isset($k['id']) ? mb_substr((string) $k['id'], 0, 120) : null, 'from_me' => !empty($k['fromMe']), 'at' => isset($m['messageTimestamp']) ? (int) $m['messageTimestamp'] : null];
            }
            return $out;
        }
        if (isset($p['phone']) && empty($p['isGroup'])) { // Z-API
            $phone = self::phone((string) $p['phone']);
            $text = (string) ($p['text']['message'] ?? $p['image']['caption'] ?? '');
            $kind = isset($p['image']) ? 'imagem' : (isset($p['audio']) ? 'audio' : (isset($p['video']) ? 'video' : (isset($p['document']) ? 'documento' : 'texto')));
            if ($phone && ($text !== '' || $kind !== 'texto')) $out[] = ['phone' => $phone, 'name' => empty($p['fromMe']) ? self::clean($p['senderName'] ?? $p['chatName'] ?? null) : null,
                'text' => mb_substr($text, 0, 4000), 'kind' => $kind, 'id' => isset($p['messageId']) ? mb_substr((string) $p['messageId'], 0, 120) : null,
                'from_me' => !empty($p['fromMe']), 'at' => isset($p['momment']) ? intdiv((int) $p['momment'], 1000) : null];
        }
        return $out;
    }

    /** Pure: Evolution "message" object → [text, kind]. */
    private static function content(array $msg): array
    {
        $text = (string) ($msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? '');
        $kind = 'texto';
        foreach (['imageMessage' => 'imagem', 'audioMessage' => 'audio', 'videoMessage' => 'video', 'documentMessage' => 'documento', 'documentWithCaptionMessage' => 'documento', 'stickerMessage' => 'imagem'] as $key => $k) {
            if (isset($msg[$key])) { $kind = $k; $text = (string) ($msg[$key]['caption'] ?? $msg[$key]['message']['documentMessage']['caption'] ?? $msg[$key]['fileName'] ?? ''); break; }
        }
        if ($text === '' && $kind === 'texto') $kind = 'outro';
        return [mb_substr($text, 0, 4000), $kind];
    }

    private static function clean(mixed $name): ?string
    {
        $n = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name) ?? '');
        return $n === '' ? null : mb_substr($n, 0, 160);
    }

    /** Pure: label for media without caption. */
    public static function label(string $kind): string
    {
        return ['imagem' => '[imagem]', 'audio' => '[áudio]', 'video' => '[vídeo]', 'documento' => '[documento]', 'outro' => '[mensagem]'][$kind] ?? '';
    }

    public static function base(): string { return rtrim((string) env('DROPESTORE_URL', 'https://dropestore.com/wp-json/wdm/v1'), '/'); }

    /** Pure: DROPE signs each delivery with sha256=HMAC(raw body, signing_secret). */
    public static function signatureOk(string $raw, string $header, string $secret): bool
    {
        return $secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    /** @return array{ok:bool, id:?string, error:?string} */
    public static function send(string $phone, string $text): array
    {
        if (self::driver() === 'teste') return ['ok' => true, 'id' => 'teste-' . bin2hex(random_bytes(8)), 'error' => null];
        if (self::driver() !== 'drope') return ['ok' => false, 'id' => null, 'error' => 'WhatsApp não conectado.'];
        $ch = curl_init(self::base() . '/send/text');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'token: ' . env('DROPESTORE_TOKEN', '')],
            CURLOPT_POSTFIELDS => json_encode(['number' => ltrim($phone, '+'), 'text' => $text, 'readchat' => true, 'track_source' => 'crm'], JSON_UNESCAPED_UNICODE)]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $r = json_decode($body, true);
        if ($code >= 200 && $code < 300 && !empty($r['success'])) return ['ok' => true, 'id' => isset($r['data']['messageid']) ? mb_substr((string) $r['data']['messageid'], 0, 120) : null, 'error' => null];
        error_log("whatsapp send failed: HTTP $code $err " . mb_substr($body, 0, 300));
        $why = ['DEVICE_DISCONNECTED' => 'O número está desconectado da DROPE. Leia o QR Code no painel.', 'TOKEN_INVALID' => 'Token da DROPE inválido.', 'RATE_LIMITED' => 'Muitas mensagens seguidas. Tente em alguns segundos.'][$r['code'] ?? ''] ?? null;
        if ($why) return ['ok' => false, 'id' => null, 'error' => $why];
        return ['ok' => false, 'id' => null, 'error' => $code ? "O WhatsApp recusou o envio (HTTP $code)." : 'Sem resposta do WhatsApp.'];
    }

    /**
     * Stores one parsed message: chat upsert, auto-link to a client with the same number, dedupe by provider id,
     * unread counter and bell (new chat, or chat going from 0 to 1 unread). Returns the chat id, or null for a duplicate.
     */
    public static function store(array $m): ?int
    {
        $chat = Db::q('SELECT * FROM wa_chats WHERE phone = ?', [$m['phone']])->fetch();
        $new = !$chat;
        if ($new) {
            $cid = Db::q('SELECT id FROM clients WHERE whatsapp = ? ORDER BY id DESC LIMIT 1', [$m['phone']])->fetchColumn() ?: null;
            Db::q('INSERT IGNORE INTO wa_chats (phone, name, client_id) VALUES (?,?,?)', [$m['phone'], $m['name'], $cid]);
            $chat = Db::q('SELECT * FROM wa_chats WHERE phone = ?', [$m['phone']])->fetch();
        }
        $at = $m['at'] && abs($m['at'] - time()) < 86400 * 30 ? date('Y-m-d H:i:s', $m['at']) : date('Y-m-d H:i:s');
        $body = $m['text'] !== '' ? $m['text'] : self::label($m['kind']);
        $ins = Db::q('INSERT IGNORE INTO wa_messages (chat_id, provider_id, from_me, body, kind, status, created_at) VALUES (?,?,?,?,?,?,?)',
            [$chat['id'], $m['id'], $m['from_me'] ? 1 : 0, $body, $m['kind'], $m['from_me'] ? 'enviada' : 'recebida', $at]);
        if ($ins->rowCount() === 0) return null;
        $preview = mb_substr(($m['from_me'] ? 'Você: ' : '') . preg_replace('/\s+/', ' ', $body), 0, 160);
        Db::q('UPDATE wa_chats SET last_message_at = ?, last_preview = ?, unread = IF(?, 0, unread + 1), name = COALESCE(name, ?) WHERE id = ?',
            [$at, $preview, $m['from_me'] ? 1 : 0, $m['name'], $chat['id']]);
        if (!$m['from_me'] && ($new || (int) $chat['unread'] === 0)) {
            $who = $chat['name'] ?: $m['name'] ?: $m['phone'];
            $owner = $chat['client_id'] ? Db::q('SELECT owner_id FROM clients WHERE id = ?', [$chat['client_id']])->fetchColumn() : null;
            Notify::clientTeam($owner ? (int) $owner : null, ($new ? 'WhatsApp: nova conversa de ' : 'WhatsApp: mensagem de ') . $who, '/whatsapp/' . $chat['id']);
            if ($chat['client_id'] && !Db::q("SELECT 1 FROM client_events WHERE client_id = ? AND type = 'sistema' AND body LIKE 'WhatsApp:%' AND created_at >= CURDATE()", [$chat['client_id']])->fetch())
                Db::q("INSERT INTO client_events (client_id, type, body) VALUES (?, 'sistema', ?)", [$chat['client_id'], 'WhatsApp: ' . mb_substr($body, 0, 500)]);
        }
        return (int) $chat['id'];
    }
}
