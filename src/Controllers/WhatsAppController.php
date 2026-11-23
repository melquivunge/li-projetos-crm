<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Db, WhatsApp};

/** WhatsApp inside the CRM: provider webhook, inbox, conversation, reply. */
final class WhatsAppController
{
    // ---- Provider webhook: POST /api/whatsapp/{token} (the secret is in the URL; wrong secret → 404) ----

    public static function webhook(string $token): void
    {
        $secret = (string) env('WHATSAPP_WEBHOOK_SECRET', '');
        if (strlen($secret) !== 64 || !hash_equals($secret, $token)) abort(404);
        $raw = file_get_contents('php://input', false, null, 0, 2_000_000) ?: ''; // ponytail: media arrive without base64 (we only store text)
        $signing = \App\Finance::setting('wa_signing_secret'); // saved by bin/whatsapp-setup.php
        if ($signing !== '' && !WhatsApp::signatureOk($raw, (string) ($_SERVER['HTTP_X_DROPE_SIGNATURE'] ?? ''), $signing)) { http_response_code(401); exit; }
        $in = json_decode($raw, true);
        header('Content-Type: application/json');
        if (!is_array($in)) { http_response_code(400); echo '{"ok":false}'; return; }
        $event = (string) ($_SERVER['HTTP_X_DROPE_EVENT'] ?? '');
        $msgs = WhatsApp::parse($in, $event);
        if (!$msgs && in_array(strtolower($event ?: (string) ($in['EventType'] ?? '')), ['messages', ''], true)) // unknown shape: keep the last one to adjust parse()
            @file_put_contents(storage_path('whatsapp-unparsed.json'), mb_substr($raw, 0, 20000));
        $n = 0; $media = [];
        foreach ($msgs as $m) if (WhatsApp::store($m)) { $n++; if ($m['kind'] !== 'texto' && $m['id']) $media[] = $m['id']; }
        echo json_encode(['ok' => true, 'stored' => $n]);
        if (!$media) return;
        // DROPE wants a reply within 8 s: answer first, then keep the audio/photo/file on our server (its link lasts 2 days)
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        foreach ($media as $pid) self::keepMedia($pid);
    }

    private static function keepMedia(string $providerId): ?array
    {
        $kept = WhatsApp::fetchMedia($providerId);
        if ($kept) Db::q('UPDATE wa_messages SET media_path = ?, media_mime = ? WHERE provider_id = ? AND media_path IS NULL', [$kept[0], $kept[1], $providerId]);
        return $kept;
    }

    /** GET /whatsapp/midia/{id}: the file of a message, only for who can see the conversation. Supports Range (Safari needs it for audio/video). */
    public static function media(string $id): void
    {
        Auth::requirePerm('crm');
        $m = Db::q('SELECT * FROM wa_messages WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        self::chat((int) $m['chat_id']);
        if (!$m['media_path'] && $m['provider_id'] && $m['kind'] !== 'texto' && ($k = self::keepMedia($m['provider_id']))) [$m['media_path'], $m['media_mime']] = $k;
        $file = $m['media_path'] ? WhatsApp::mediaFile($m['media_path']) : null;
        if (!$file) abort(404, 'Arquivo indisponível. O WhatsApp só guarda a mídia por um tempo; peça para reenviar.');
        session_write_close(); // long downloads must not lock the session
        $size = filesize($file); $start = 0; $end = $size - 1;
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
            if ($r[1] === '') { $start = max(0, $size - (int) $r[2]); } else { $start = (int) $r[1]; if ($r[2] !== '') $end = min($end, (int) $r[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206); header("Content-Range: bytes $start-$end/$size");
        }
        $inline = WhatsApp::kindOf($m['media_mime']) !== 'documento' || $m['media_mime'] === 'application/pdf';
        $name = \App\Files::cleanName($m['media_name'] ?: ('whatsapp-' . $m['id'] . '.' . pathinfo($file, PATHINFO_EXTENSION)));
        header('Content-Type: ' . $m['media_mime']);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
        header('Cache-Control: private, max-age=86400');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); echo $chunk; $left -= strlen($chunk); }
        fclose($fh);
        exit;
    }

    // ---- Team ----

    /** Chats this user may see: unlinked ones (new leads) + those of clients they can see. */
    private static function scope(): array
    {
        return Auth::isAdmin() ? ['1=1', []] : ['(w.client_id IS NULL OR c.owner_id = ?)', [Auth::id()]];
    }

    private static function chat(int $id): array
    {
        Auth::requirePerm('crm');
        $w = Db::q('SELECT w.*, c.name AS client_name, c.owner_id, c.stage FROM wa_chats w LEFT JOIN clients c ON c.id = w.client_id WHERE w.id = ?', [$id])->fetch() ?: abort(404);
        if ($w['client_id'] && !Auth::canSeeClient(['owner_id' => $w['owner_id']])) abort(403);
        return $w;
    }

    public static function unread(): int
    {
        [$where, $params] = self::scope();
        return (int) Db::q("SELECT COUNT(*) FROM wa_chats w LEFT JOIN clients c ON c.id = w.client_id WHERE w.unread > 0 AND $where", $params)->fetchColumn();
    }

    public static function index(): void { self::render(null); }

    public static function show(string $id): void { self::render(self::chat((int) $id)); }

    private static function render(?array $chat): void
    {
        Auth::requirePerm('crm');
        [$where, $params] = self::scope();
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (w.name LIKE ? OR c.name LIKE ? OR w.phone LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $q);
            array_push($params, $like, $like, $digits !== '' ? '%' . $digits . '%' : $like);
        }
        $chats = Db::q("SELECT w.*, c.name AS client_name FROM wa_chats w LEFT JOIN clients c ON c.id = w.client_id WHERE $where ORDER BY w.last_message_at DESC, w.id DESC LIMIT 200", $params)->fetchAll();
        $messages = [];
        if ($chat) {
            $messages = array_reverse(Db::q('SELECT m.*, u.name AS user_name FROM wa_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.chat_id = ? ORDER BY m.id DESC LIMIT 300', [$chat['id']])->fetchAll());
            if ($chat['unread']) Db::q('UPDATE wa_chats SET unread = 0 WHERE id = ?', [$chat['id']]);
        }
        header('Cache-Control: no-store');
        view('whatsapp', ['chats' => $chats, 'chat' => $chat, 'messages' => $messages, 'q' => $q, 'canSend' => WhatsApp::canSend()]);
    }

    /** GET /whatsapp/{id}/mensagens?depois=N → JSON with newer messages (polled every 10 s while the chat is open). */
    public static function poll(string $id): void
    {
        $w = self::chat((int) $id);
        $after = max(0, (int) ($_GET['depois'] ?? 0));
        $rows = Db::q('SELECT m.id, m.from_me, m.body, m.kind, m.media_name, m.status, m.created_at, u.name AS user_name FROM wa_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.chat_id = ? AND m.id > ? ORDER BY m.id LIMIT 100', [$w['id'], $after])->fetchAll();
        if ($rows && $w['unread']) Db::q('UPDATE wa_chats SET unread = 0 WHERE id = ?', [$w['id']]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['messages' => array_map(fn($r) => ['id' => (int) $r['id'], 'mine' => (bool) $r['from_me'], 'body' => $r['body'], 'status' => $r['status'],
            'time' => date('H:i', strtotime($r['created_at'])), 'by' => $r['user_name'],
            'kind' => $r['kind'], 'file' => $r['media_name'], 'caption' => $r['body'] !== WhatsApp::label($r['kind'])], $rows)], JSON_UNESCAPED_UNICODE);
    }

    public static function send(string $id): void
    {
        $w = self::chat((int) $id);
        $back = '/whatsapp/' . $w['id'];
        $text = trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? '')));
        $up = $_FILES['file'] ?? null;
        $hasFile = $up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (mb_strlen($text) > 4000 || (!$hasFile && $text === '')) { flash('Escreva a mensagem ou anexe um arquivo (texto até 4.000 caracteres).', 'danger'); redirect($back); }
        $kept = null; $name = null;
        if ($hasFile) {
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || (int) $up['size'] > WhatsApp::MEDIA_MAX) { flash('O arquivo pode ter no máximo 15 MB.', 'danger'); redirect($back); }
            if ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) { flash('Não foi possível receber o arquivo. Tente de novo.', 'danger'); redirect($back); }
            $name = \App\Files::cleanName((string) $up['name']);
            $kept = WhatsApp::keep($up['tmp_name'], true);
            if (!$kept) { flash('Tipo de arquivo não aceito. Envie foto, áudio, vídeo MP4, PDF, Word, Excel, PowerPoint, DWG ou ZIP.', 'danger'); redirect($back); }
        }
        $kind = $kept ? WhatsApp::kindOf($kept[1]) : 'texto';
        Db::q("INSERT INTO wa_messages (chat_id, from_me, body, kind, media_path, media_mime, media_name, status, user_id) VALUES (?, 1, ?, ?, ?, ?, ?, 'enviando', ?)",
            [$w['id'], $text !== '' ? $text : WhatsApp::label($kind), $kind, $kept[0] ?? null, $kept[1] ?? null, $name, Auth::id()]);
        $mid = (int) Db::pdo()->lastInsertId();
        $r = $kept ? WhatsApp::sendMedia($w['phone'], $kept[0], $kept[1], $name, $text) : WhatsApp::send($w['phone'], $text);
        Db::q('UPDATE wa_messages SET status = ?, provider_id = ? WHERE id = ?', [$r['ok'] ? 'enviada' : 'falhou', $r['id'], $mid]);
        $preview = $text !== '' ? $text : WhatsApp::label($kind) . ($name ? " $name" : '');
        Db::q('UPDATE wa_chats SET last_message_at = NOW(), last_preview = ?, unread = 0 WHERE id = ?', [mb_substr('Você: ' . preg_replace('/\s+/', ' ', $preview), 0, 160), $w['id']]);
        Audit::log('whatsapp.send', 'wa_chat', (int) $w['id']);
        if (!$r['ok']) $_SESSION['wa_failed'] = ['chat' => (int) $w['id'], 'text' => $text, 'error' => $r['error']];
        redirect($back . '#fim');
    }
}
