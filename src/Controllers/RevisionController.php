<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Clients, Db, Files, Notify, Projects, Revisions};

final class RevisionController
{
    private static function back(int $projectId, string $anchor = 'revisoes'): never { redirect("/projetos/$projectId#$anchor"); }

    private static function date(mixed $s): ?string
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : null;
    }

    /** Bell for the partners + the project manager. */
    private static function team(array $p, string $body, string $link): void
    {
        $ids = Db::q("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR id = ?)", [$p['manager_id'] ?? 0])->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_unique(array_map('intval', $ids)) as $uid) Notify::user($uid, $body, $link);
    }

    // ---- Team ----

    public static function create(string $id): void
    {
        $u = Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $type = isset(Revisions::TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'revisao';
        $desc = trim((string) ($_POST['description'] ?? ''));
        if ($desc === '') { flash('Descreva o que o cliente pediu.', 'danger'); self::back((int) $p['id']); }
        $phase = (int) ($_POST['phase_id'] ?? 0);
        $phase = $phase && Db::q('SELECT 1 FROM project_phases WHERE id = ? AND project_id = ?', [$phase, $p['id']])->fetch() ? $phase : null;
        $deliv = (int) ($_POST['deliverable_id'] ?? 0);
        $deliv = $deliv && Db::q('SELECT 1 FROM deliverables WHERE id = ? AND project_id = ?', [$deliv, $p['id']])->fetch() ? $deliv : null;
        $rid = Revisions::create($p, $phase, $deliv, $type, $desc, false, $u['name'], (int) $u['id'], self::date($_POST['due_date'] ?? null), FinanceController::money($_POST['extra_cost'] ?? ''));
        $r = Db::q('SELECT * FROM revision_requests WHERE id = ?', [$rid])->fetch();
        if ($deliv) Db::q("UPDATE deliverables SET status = 'ajustes' WHERE id = ? AND status <> 'aprovado'", [$deliv]);
        Audit::log('revision.create', 'revision', $rid);
        if ($r['is_extra']) flash(($type === 'escopo' ? 'Mudança de escopo' : 'Esta é a ' . $r['round'] . 'ª rodada, além das ' . $p['revisions_included'] . ' incluídas') . '. O trabalho só começa depois que o cliente aprovar o custo extra.', 'warning');
        self::back((int) $p['id']);
    }

    /** start / done / refuse / reopen / cost (set value) / approve_cost (recorded by the team). */
    public static function update(string $id): void
    {
        $u = Auth::requirePerm('projects');
        $r = Db::q('SELECT * FROM revision_requests WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        $p = Projects::findOrFail((int) $r['project_id']);
        $action = (string) ($_POST['action'] ?? '');
        switch ($action) {
            case 'start':
                if (!Revisions::canStart($r)) { flash('Esta revisão espera o cliente aprovar o custo extra.', 'danger'); self::back((int) $p['id']); }
                Db::q("UPDATE revision_requests SET status = 'andamento' WHERE id = ?", [$r['id']]); break;
            case 'done':
                if (!in_array($r['status'], ['aberta', 'andamento'], true)) { flash('Esta revisão ainda não pode ser concluída.', 'danger'); self::back((int) $p['id']); }
                Db::q("UPDATE revision_requests SET status = 'concluida', done_at = NOW() WHERE id = ?", [$r['id']]); break;
            case 'refuse':
                if ($r['status'] === 'concluida') self::back((int) $p['id']);
                Db::q("UPDATE revision_requests SET status = 'recusada' WHERE id = ?", [$r['id']]);
                Clients::event((int) $p['client_id'], 'sistema', 'Pedido de revisão #' . $r['id'] . ' recusado · ' . $p['title'] . '.'); break;
            case 'reopen':
                if ($r['status'] !== 'recusada') self::back((int) $p['id']);
                Db::q('UPDATE revision_requests SET status = ? WHERE id = ?', [$r['is_extra'] && !$r['cost_approved_at'] ? 'aguardando_aprovacao' : 'aberta', $r['id']]); break;
            case 'cost':
                if ($r['status'] !== 'aguardando_aprovacao') self::back((int) $p['id']);
                Db::q('UPDATE revision_requests SET extra_cost = ? WHERE id = ?', [FinanceController::money($_POST['extra_cost'] ?? ''), $r['id']]); break;
            case 'approve_cost':
                $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
                if ($r['status'] !== 'aguardando_aprovacao' || (float) $r['extra_cost'] <= 0 || $name === '') { flash('Defina o valor e informe quem aprovou.', 'danger'); self::back((int) $p['id']); }
                Revisions::approveCost($r, $p, $name, (int) $u['id']); break;
            default: abort(400);
        }
        Audit::log('revision.' . $action, 'revision', (int) $r['id']);
        self::back((int) $p['id']);
    }

    private static function deliverable(string $id): array
    {
        Auth::requirePerm('projects');
        $d = Db::q('SELECT * FROM deliverables WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        return [$d, Projects::findOrFail((int) $d['project_id'])];
    }

    public static function publicUrl(array $d): string
    {
        return rtrim((string) env('APP_URL', ''), '/') . '/e/' . $d['public_token'];
    }

    /** Sends the approval link by WhatsApp (creates the link on first use). */
    public static function send(string $id): void
    {
        [$d, $p] = self::deliverable($id);
        if ($p['status'] === 'pausado' && ($p['hold_reason'] ?? '') === 'pagamento') { flash('Projeto pausado por pagamento em atraso: novas entregas ficam bloqueadas até o cliente regularizar.', 'danger'); self::back((int) $p['id'], 'entregas'); }
        if (!Db::q("SELECT 1 FROM files WHERE owner_type = 'deliverable' AND owner_id = ?", [$d['id']])->fetch()) { flash('Envie pelo menos um arquivo antes de mandar para o cliente.', 'danger'); self::back((int) $p['id'], 'entregas'); }
        $c = Db::q('SELECT name, whatsapp FROM clients WHERE id = ?', [$p['client_id']])->fetch(); // project visibility already checked
        if (!$d['public_token']) { $d['public_token'] = bin2hex(random_bytes(32)); Db::q('UPDATE deliverables SET public_token = ? WHERE id = ?', [$d['public_token'], $d['id']]); }
        Db::q("UPDATE deliverables SET sent_at = NOW(), status = IF(status = 'aprovado', status, 'enviado') WHERE id = ?", [$d['id']]);
        Clients::event((int) $p['client_id'], 'whatsapp', 'Entregável "' . $d['title'] . '" enviado para aprovação.');
        Audit::log('deliverable.send', 'deliverable', (int) $d['id']);
        if (!$c['whatsapp']) { flash('O cliente não tem WhatsApp. Copie o link: ' . self::publicUrl($d)); self::back((int) $p['id'], 'entregas'); }
        $text = 'Olá, ' . explode(' ', trim($c['name']))[0] . '! Aqui é da ' . \App\Brand::name() . '. "' . $d['title'] . '", do projeto ' . $p['title'] . ", está pronto para você ver. Pelo link você aprova ou pede ajustes:\n" . self::publicUrl($d);
        redirect('https://wa.me/' . ltrim($c['whatsapp'], '+') . '?text=' . rawurlencode($text));
    }

    /** The client approved by WhatsApp or in a meeting: the team records it with the client's name. */
    public static function approve(string $id): void
    {
        $u = Auth::requirePerm('projects');
        [$d, $p] = self::deliverable($id);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
        if ($name === '') { flash('Informe quem aprovou.', 'danger'); self::back((int) $p['id'], 'entregas'); }
        if ($d['status'] !== 'aprovado') Revisions::approveDeliverable($d, $p, $name, (int) $u['id']);
        Audit::log('deliverable.approve', 'deliverable', (int) $d['id']);
        self::back((int) $p['id'], 'entregas');
    }

    // ---- Client (no login; the 64-hex token is the credential) ----

    private static function byToken(string $token): array
    {
        $d = Db::q('SELECT * FROM deliverables WHERE public_token = ?', [$token])->fetch() ?: abort(404);
        if (!$d['sent_at']) abort(404);
        $p = Db::q('SELECT * FROM projects WHERE id = ?', [$d['project_id']])->fetch() ?: abort(404);
        $c = Db::q('SELECT id, name FROM clients WHERE id = ?', [$p['client_id']])->fetch() ?: abort(404);
        return [$d, $p, $c];
    }

    public static function publicShow(string $token): void
    {
        [$d, $p, $c] = self::byToken($token);
        header('X-Robots-Tag: noindex');
        $latest = [];
        foreach (Files::forOwner('deliverable', (int) $d['id']) as $f) if ($f['visible_to_client']) $latest[$f['name']] ??= $f; // newest version of each file
        $pending = Db::q("SELECT * FROM revision_requests WHERE deliverable_id = ? AND status = 'aguardando_aprovacao' AND extra_cost > 0 ORDER BY id", [$d['id']])->fetchAll();
        $open = Db::q("SELECT * FROM revision_requests WHERE deliverable_id = ? AND status IN ('aberta','andamento','aguardando_aprovacao') ORDER BY id DESC", [$d['id']])->fetchAll();
        $all = Db::q('SELECT type, status, phase_id FROM revision_requests WHERE project_id = ?', [$p['id']])->fetchAll();
        $used = Revisions::used($all, $d['phase_id'] ? (int) $d['phase_id'] : null);
        $approval = Db::q("SELECT * FROM approvals WHERE entity = 'deliverable' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$d['id']])->fetch() ?: null;
        $msg = $_SESSION['e_msg'] ?? null; unset($_SESSION['e_msg']);
        $paid = \App\Finance::projectPaidUp($p);
        view('deliverable_public', compact('d', 'p', 'c', 'latest', 'pending', 'open', 'used', 'approval', 'msg', 'paid') + ['token' => $token], 'layout_guest');
    }

    public static function publicAnswer(string $token): void
    {
        [$d, $p, $c] = self::byToken($token);
        $name = mb_substr(trim((string) ($_POST['signer'] ?? '')), 0, 160);
        $action = (string) ($_POST['action'] ?? '');
        $fail = function (string $m) use ($token): never { $_SESSION['e_msg'] = ['danger', $m]; redirect('/e/' . $token . '#responder'); };
        if (!ProposalController::isFullName($name)) $fail('Digite o seu nome completo.');
        if ($action === 'approve') {
            if ($d['status'] === 'aprovado') redirect('/e/' . $token);
            if (empty($_POST['agree'])) $fail('Marque que você conferiu e aprova.');
            Revisions::approveDeliverable($d, $p, $name, null);
            self::team($p, $c['name'] . ' aprovou "' . $d['title'] . '"', '/projetos/' . $p['id'] . '#entregas');
            $_SESSION['e_msg'] = ['success', 'Aprovado. Obrigada! A equipe já foi avisada.'];
        } elseif ($action === 'adjust') {
            $desc = trim((string) ($_POST['description'] ?? ''));
            if (mb_strlen($desc) < 5) $fail('Conte o que você gostaria de ajustar.');
            if ($d['status'] === 'aprovado') $fail('Este item já foi aprovado. Fale com a equipe pelo WhatsApp.');
            $rid = Revisions::create($p, $d['phase_id'] ? (int) $d['phase_id'] : null, (int) $d['id'], 'revisao', $desc, true, $name, null);
            Db::q("UPDATE deliverables SET status = 'ajustes' WHERE id = ?", [$d['id']]);
            $extra = (int) Db::q('SELECT is_extra FROM revision_requests WHERE id = ?', [$rid])->fetchColumn();
            self::team($p, $c['name'] . ' pediu ajustes em "' . $d['title'] . '"' . ($extra ? ' (além das rodadas incluídas: defina o valor)' : ''), '/projetos/' . $p['id'] . '#revisoes');
            $_SESSION['e_msg'] = ['success', $extra ? 'Recebemos o seu pedido. Ele passa das rodadas de revisão incluídas no contrato, então vamos te mandar o valor para aprovar antes de começar.' : 'Recebemos o seu pedido de ajustes. A equipe vai trabalhar nele e te avisa quando a nova versão estiver pronta.'];
        } elseif ($action === 'approve_cost') {
            $r = Db::q("SELECT * FROM revision_requests WHERE id = ? AND deliverable_id = ? AND status = 'aguardando_aprovacao' AND extra_cost > 0", [(int) ($_POST['revision_id'] ?? 0), $d['id']])->fetch();
            if (!$r) redirect('/e/' . $token);
            if (empty($_POST['agree'])) $fail('Marque que você concorda com o valor.');
            Revisions::approveCost($r, $p, $name, null);
            self::team($p, $c['name'] . ' aprovou o custo extra de ' . money((float) $r['extra_cost']) . ' em ' . $p['title'], '/projetos/' . $p['id'] . '#revisoes');
            $_SESSION['e_msg'] = ['success', 'Valor aprovado. A equipe já pode começar o ajuste.'];
        } else abort(400);
        redirect('/e/' . $token . '#responder');
    }

    public static function publicFile(string $token, string $id): void
    {
        [$d, $p] = self::byToken($token);
        $f = Db::q("SELECT * FROM files WHERE id = ? AND owner_type = 'deliverable' AND owner_id = ? AND visible_to_client = 1", [(int) $id, $d['id']])->fetch() ?: abort(404);
        if (!\App\Finance::projectPaidUp($p)) Files::sendPreview($f); // anti-calote: originals only when fully paid
        Files::send($f, !empty($_GET['baixar']));
    }
}
