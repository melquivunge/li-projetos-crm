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
        $n = 0;
        foreach ($msgs as $m) if (WhatsApp::store($m)) $n++;
        echo json_encode(['ok' => true, 'stored' => $n]);
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
        $rows = Db::q('SELECT m.id, m.from_me, m.body, m.status, m.created_at, u.name AS user_name FROM wa_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.chat_id = ? AND m.id > ? ORDER BY m.id LIMIT 100', [$w['id'], $after])->fetchAll();
        if ($rows && $w['unread']) Db::q('UPDATE wa_chats SET unread = 0 WHERE id = ?', [$w['id']]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['messages' => array_map(fn($r) => ['id' => (int) $r['id'], 'mine' => (bool) $r['from_me'], 'body' => $r['body'], 'status' => $r['status'],
            'time' => date('H:i', strtotime($r['created_at'])), 'by' => $r['user_name']], $rows)], JSON_UNESCAPED_UNICODE);
    }

    public static function send(string $id): void
    {
        $w = self::chat((int) $id);
        $text = trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? '')));
        if ($text === '' || mb_strlen($text) > 4000) { flash('Escreva a mensagem (até 4.000 caracteres).', 'danger'); redirect('/whatsapp/' . $w['id']); }
        Db::q("INSERT INTO wa_messages (chat_id, from_me, body, status, user_id) VALUES (?, 1, ?, 'enviando', ?)", [$w['id'], $text, Auth::id()]);
        $mid = (int) Db::pdo()->lastInsertId();
        $r = WhatsApp::send($w['phone'], $text);
        Db::q('UPDATE wa_messages SET status = ?, provider_id = ? WHERE id = ?', [$r['ok'] ? 'enviada' : 'falhou', $r['id'], $mid]);
        Db::q('UPDATE wa_chats SET last_message_at = NOW(), last_preview = ?, unread = 0 WHERE id = ?', [mb_substr('Você: ' . preg_replace('/\s+/', ' ', $text), 0, 160), $w['id']]);
        Audit::log('whatsapp.send', 'wa_chat', (int) $w['id']);
        if (!$r['ok']) $_SESSION['wa_failed'] = ['chat' => (int) $w['id'], 'text' => $text, 'error' => $r['error']];
        redirect('/whatsapp/' . $w['id'] . '#fim');
    }
}
