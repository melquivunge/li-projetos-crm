<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Briefings, Clients, Db, Files, Notify, Opportunities};

final class BriefingController
{
    private static function url(array $b): string
    {
        return rtrim((string) env('APP_URL', ''), '/') . '/b/' . $b['public_token'];
    }

    /** Creates a briefing for an opportunity from its service template (falls back to the client's service). */
    public static function create(string $id): void
    {
        $u = Auth::requirePerm('crm');
        $o = Opportunities::findOrFail((int) $id);
        $c = Clients::findOrFail((int) $o['client_id']);
        $service = $o['service'] ?: $c['service'];
        $t = Db::q('SELECT questions FROM briefing_templates WHERE service = ?', [$service])->fetchColumn()
            ?: Db::q("SELECT questions FROM briefing_templates WHERE service = 'Design de Interiores'")->fetchColumn();
        Db::q('INSERT INTO briefings (client_id, opportunity_id, service, questions, answers, public_token, created_by) VALUES (?,?,?,?,?,?,?)',
            [$c['id'], $o['id'], $service, $t, '{}', bin2hex(random_bytes(32)), $u['id']]);
        $bid = (int) Db::pdo()->lastInsertId();
        Clients::event((int) $c['id'], 'sistema', 'Briefing criado para "' . $o['title'] . '".');
        Audit::log('briefing.create', 'briefing', $bid);
        redirect("/briefings/$bid");
    }

    public static function show(string $id): void
    {
        Auth::requirePerm('crm');
        [$b, $c] = Briefings::load((int) $id);
        $history = Db::q("SELECT * FROM approvals WHERE entity = 'briefing' AND entity_id = ? ORDER BY id", [$b['id']])->fetchAll();
        view('briefing_show', ['b' => $b, 'c' => $c, 'questions' => Briefings::parse($b['questions']), 'answers' => json_decode((string) $b['answers'], true) ?: [],
            'files' => Files::forOwner('briefing', (int) $b['id']), 'url' => self::url($b), 'history' => $history]);
    }

    /** Team edits. Editing a validated briefing reopens it as a new version, so what the client validated stays on record. */
    public static function save(string $id): void
    {
        $u = Auth::requirePerm('crm');
        [$b, $c] = Briefings::load((int) $id);
        $answers = Briefings::cleanAnswers(Briefings::parse($b['questions']), (array) ($_POST['a'] ?? []));
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        $changed = $json !== json_encode(json_decode((string) $b['answers'], true) ?: [], JSON_UNESCAPED_UNICODE);
        if ($b['status'] === 'validado' && $changed) {
            Db::q("UPDATE briefings SET answers = ?, version = version + 1, status = 'rascunho', validated_at = NULL WHERE id = ?", [$json, $b['id']]);
            Clients::event((int) $c['id'], 'sistema', 'Briefing alterado após a validação: agora versão ' . ((int) $b['version'] + 1) . '. Peça uma nova validação ao cliente.');
        } elseif ($changed) {
            Db::q('UPDATE briefings SET answers = ? WHERE id = ?', [$json, $b['id']]);
        }
        [$saved, $errors] = Files::store(Files::list($_FILES['files'] ?? []), 'briefing', (int) $b['id'], (int) $c['id'], (int) $u['id'], false);
        Audit::log('briefing.update', 'briefing', (int) $b['id']);
        flash($errors ? 'Salvo, mas: ' . implode(' ', $errors) : 'Briefing salvo' . ($saved ? " com $saved arquivo(s)." : '.'), $errors ? 'danger' : 'success');
        redirect('/briefings/' . $b['id']);
    }

    /** Marks as sent and opens WhatsApp with the client link (or just marks, when the client has no WhatsApp). */
    public static function send(string $id): void
    {
        Auth::requirePerm('crm');
        [$b, $c] = Briefings::load((int) $id);
        if (in_array($b['status'], ['rascunho', 'enviado'], true)) Db::q("UPDATE briefings SET status = 'enviado', sent_at = COALESCE(sent_at, NOW()) WHERE id = ?", [$b['id']]);
        if ($b['opportunity_id']) {
            $o = Db::q('SELECT * FROM opportunities WHERE id = ?', [$b['opportunity_id']])->fetch();
            if ($o && in_array($o['stage'], ['novo', 'qualificado'], true)) Opportunities::move($o, 'briefing');
        }
        Clients::event((int) $c['id'], 'whatsapp', 'Briefing enviado ao cliente.');
        Audit::log('briefing.send', 'briefing', (int) $b['id']);
        if (!$c['whatsapp']) { flash('Marcado como enviado. Copie o link e envie ao cliente.'); redirect('/briefings/' . $b['id']); }
        $text = 'Olá, ' . explode(' ', trim($c['name']))[0] . '! Aqui é da LI Projetos. Para prepararmos o seu projeto, responda este briefing (leva uns 5 minutos, e você pode anexar fotos e plantas): ' . self::url($b);
        redirect('https://wa.me/' . ltrim($c['whatsapp'], '+') . '?text=' . rawurlencode($text));
    }

    public static function file(string $id): void
    {
        Auth::requirePerm('crm');
        $f = Db::q("SELECT * FROM files WHERE id = ? AND owner_type = 'briefing'", [(int) $id])->fetch() ?: abort(404);
        Clients::findOrFail((int) $f['client_id']);
        Files::send($f, !empty($_GET['baixar']));
    }

    public static function removeFile(string $id): void
    {
        Auth::requirePerm('crm');
        $f = Db::q("SELECT * FROM files WHERE id = ? AND owner_type = 'briefing'", [(int) $id])->fetch() ?: abort(404);
        Clients::findOrFail((int) $f['client_id']);
        Files::remove($f);
        Audit::log('file.delete', 'file', (int) $f['id']);
        redirect('/briefings/' . $f['owner_id']);
    }

    // ---- Client side (no login; the 64-hex token is the credential) ----

    private static function byToken(string $token): array
    {
        $b = Db::q('SELECT * FROM briefings WHERE public_token = ?', [$token])->fetch() ?: abort(404);
        if (!$b['sent_at']) abort(404);
        $c = Db::q('SELECT id, name, owner_id FROM clients WHERE id = ?', [$b['client_id']])->fetch() ?: abort(404);
        return [$b, $c];
    }

    public static function publicShow(string $token): void
    {
        [$b, $c] = self::byToken($token);
        header('X-Robots-Tag: noindex');
        $err = $_SESSION['briefing_error'] ?? null; unset($_SESSION['briefing_error']);
        view('briefing_public', ['b' => $b, 'c' => $c, 'questions' => Briefings::parse($b['questions']), 'answers' => json_decode((string) $b['answers'], true) ?: [],
            'files' => array_filter(Files::forOwner('briefing', (int) $b['id']), fn($f) => $f['from_client']), 'error' => $err], 'layout_guest');
    }

    public static function publicSave(string $token): void
    {
        [$b, $c] = self::byToken($token);
        if ($b['status'] === 'validado') redirect('/b/' . $token);
        $answers = Briefings::cleanAnswers(Briefings::parse($b['questions']), (array) ($_POST['a'] ?? []));
        [$saved, $errors] = Files::store(Files::list($_FILES['files'] ?? []), 'briefing', (int) $b['id'], (int) $c['id'], null, true);
        $validate = !empty($_POST['confirm']);
        $name = mb_substr(trim((string) ($_POST['signer'] ?? '')), 0, 160);
        if ($validate && !ProposalController::isFullName($name)) { $validate = false; $errors[] = 'Para confirmar, digite o seu nome completo.'; }
        Db::q('UPDATE briefings SET answers = ?, status = ?, answered_at = NOW(), validated_at = IF(?, NOW(), NULL) WHERE id = ?',
            [json_encode($answers, JSON_UNESCAPED_UNICODE), $validate ? 'validado' : 'respondido', $validate ? 1 : 0, $b['id']]);
        if ($validate) Db::q('INSERT INTO approvals (entity, entity_id, version, decision, name, ip, user_agent) VALUES (?,?,?,?,?,?,?)',
            ['briefing', $b['id'], $b['version'], 'validado', $name, client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        Clients::event((int) $c['id'], 'sistema', 'Cliente ' . ($validate ? 'respondeu e validou' : 'respondeu') . ' o briefing' . ($saved ? " e enviou $saved arquivo(s)" : '') . '.');
        Notify::clientTeam($c['owner_id'] ? (int) $c['owner_id'] : null, $c['name'] . ($validate ? ' validou o briefing' : ' respondeu o briefing'), '/briefings/' . $b['id']);
        if ($errors) $_SESSION['briefing_error'] = implode(' ', $errors);
        redirect('/b/' . $token . ($errors ? '#enviar' : '#ok'));
    }

    public static function publicFile(string $token, string $id): void
    {
        [$b] = self::byToken($token);
        $f = Db::q("SELECT * FROM files WHERE id = ? AND owner_type = 'briefing' AND owner_id = ? AND from_client = 1", [(int) $id, $b['id']])->fetch() ?: abort(404);
        Files::send($f);
    }

    // ---- Templates (admin) ----

    public static function templates(): void
    {
        Auth::requireAdmin();
        view('briefing_templates', ['rows' => Db::q('SELECT * FROM briefing_templates ORDER BY id')->fetchAll()]);
    }

    public static function saveTemplates(): void
    {
        Auth::requireAdmin();
        foreach ((array) ($_POST['q'] ?? []) as $id => $text) {
            $text = mb_substr(trim((string) $text), 0, 8000);
            if (Briefings::parse($text)) Db::q('UPDATE briefing_templates SET questions = ? WHERE id = ?', [$text, (int) $id]);
        }
        Audit::log('briefing_templates.update');
        flash('Perguntas salvas. Valem para os próximos briefings.');
        redirect('/briefings/modelos');
    }
}
