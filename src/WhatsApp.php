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
                'id' => mb_substr((string) $dm['messageid'], 0, 120), 'from_me' => !empty($dm['fromMe']), 'at' => $ts && $ts > 99999999999 ? intdiv($ts, 1000) : $ts,
                'file' => $kind === 'documento' ? self::clean($dm['content']['fileName'] ?? $dm['fileName'] ?? $dm['content']['title'] ?? null) : null]];
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

    /** Media kept on the server: real type (finfo) → extension. Anything else is refused. */
    public const MEDIA = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/x-m4a' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/3gpp' => '3gp',
        'application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/zip' => 'zip', 'text/plain' => 'txt', 'image/vnd.dwg' => 'dwg', 'application/acad' => 'dwg'];
    public const MEDIA_MAX = 15 * 1024 * 1024;
    private const MEDIA_RE = '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]+$#';

    /** Pure: what the CRM shows/sends for a file type. */
    public static function kindOf(string $mime): string
    {
        return match (true) { str_starts_with($mime, 'image/') && $mime !== 'image/vnd.dwg' => 'imagem', str_starts_with($mime, 'audio/') => 'audio', str_starts_with($mime, 'video/') => 'video', default => 'documento' };
    }

    public static function mediaFile(string $rel): ?string
    {
        $f = storage_path('wa/') . $rel;
        return preg_match(self::MEDIA_RE, $rel) && is_file($f) ? $f : null;
    }

    /** Moves a local file (upload or download) into storage. Returns [relative path, mime] or null if the type isn't allowed. */
    public static function keep(string $tmp, bool $uploaded): ?array
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MEDIA[$mime]) || filesize($tmp) > self::MEDIA_MAX || filesize($tmp) === 0) return null;
        $rel = date('Y/m/') . bin2hex(random_bytes(16)) . '.' . self::MEDIA[$mime];
        $dest = storage_path('wa/') . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0750, true);
        if (!($uploaded ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest))) return null;
        return [$rel, $mime];
    }

    private static function api(string $path, array $body, int $timeout = 20): ?array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'token: ' . env('DROPESTORE_TOKEN', '')],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $r = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || empty($r['success'])) { error_log("whatsapp $path failed: HTTP $code " . mb_substr($raw, 0, 300)); return is_array($r) ? $r + ['_http' => $code] : ['_http' => $code]; }
        return $r;
    }

    /** Downloads the media of a received message (DROPE /message/download → fileURL, valid 2 days) and keeps it. Audio comes as MP3. */
    public static function fetchMedia(string $providerId): ?array
    {
        if (self::driver() !== 'drope' || $providerId === '') return null;
        $r = self::api('/message/download', ['id' => $providerId, 'generate_mp3' => true]);
        $url = (string) ($r['data']['fileURL'] ?? $r['fileURL'] ?? '');
        if (!str_starts_with($url, 'https://')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'wa');
        $fh = fopen($tmp, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_MAXFILESIZE => self::MEDIA_MAX, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch); fclose($fh);
        $kept = $ok ? self::keep($tmp, false) : null;
        if (!$kept) @unlink($tmp);
        return $kept;
    }

    /** Sends a stored file (base64; the route accepts up to 20 MB). */
    public static function sendMedia(string $phone, string $rel, string $mime, ?string $name, string $caption): array
    {
        if (self::driver() === 'teste') return ['ok' => true, 'id' => 'teste-' . bin2hex(random_bytes(8)), 'error' => null];
        if (self::driver() !== 'drope') return ['ok' => false, 'id' => null, 'error' => 'WhatsApp não conectado.'];
        $file = self::mediaFile($rel);
        if (!$file) return ['ok' => false, 'id' => null, 'error' => 'Arquivo não encontrado.'];
        $type = ['imagem' => 'image', 'audio' => 'audio', 'video' => 'video', 'documento' => 'document'][self::kindOf($mime)];
        if ($type === 'video' && $mime !== 'video/mp4') $type = 'document'; // DROPE: video only MP4
        if ($type === 'image' && $mime === 'image/gif') $type = 'document';
        $body = ['number' => ltrim($phone, '+'), 'type' => $type, 'file' => base64_encode((string) file_get_contents($file)), 'mimetype' => $mime, 'readchat' => true, 'track_source' => 'crm'];
        if ($caption !== '' && $type !== 'audio') $body['text'] = $caption;
        if ($type === 'document') $body['docName'] = $name ?: basename($file);
        $r = self::api('/send/media', $body, 60);
        if (!empty($r['success'])) return ['ok' => true, 'id' => isset($r['data']['messageid']) ? mb_substr((string) $r['data']['messageid'], 0, 120) : null, 'error' => null];
        $http = (int) ($r['_http'] ?? 0);
        $why = ['DEVICE_DISCONNECTED' => 'O número está desconectado da DROPE. Leia o QR Code no painel.', 'TOKEN_INVALID' => 'Token da DROPE inválido.', 'PAYLOAD_TOO_LARGE' => 'Arquivo grande demais para o WhatsApp.', 'RATE_LIMITED' => 'Muitas mensagens seguidas. Tente em alguns segundos.'][$r['code'] ?? ''] ?? null;
        return ['ok' => false, 'id' => null, 'error' => $why ?? ($http ? "O WhatsApp recusou o arquivo (HTTP $http)." : 'Sem resposta do WhatsApp.')];
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
        $ins = Db::q('INSERT IGNORE INTO wa_messages (chat_id, provider_id, from_me, body, kind, media_name, status, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$chat['id'], $m['id'], $m['from_me'] ? 1 : 0, $body, $m['kind'], $m['file'] ?? null, $m['from_me'] ? 'enviada' : 'recebida', $at]);
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
