<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Audit, Clients, Db, Mail, Notify, Opportunities, Pricing, Projects};
use Dompdf\Dompdf;

final class ProposalController
{
    public const STATUS = ['rascunho' => 'Rascunho', 'enviada' => 'Enviada', 'vista' => 'Vista', 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'substituida' => 'Substituída'];

    private static function items(bool $activeOnly = true): array
    {
        return Db::q('SELECT * FROM price_items' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY credit_rank DESC, price')->fetchAll();
    }

    /** Proposal + its client, after the same visibility check as the client page. */
    private static function load(int $id): array
    {
        $p = Db::q('SELECT * FROM proposals WHERE id = ?', [$id])->fetch() ?: abort(404);
        $c = Clients::findOrFail((int) $p['client_id']);
        $p['items'] = Db::q('SELECT * FROM proposal_items WHERE proposal_id = ? ORDER BY id', [$id])->fetchAll();
        $p['schedule'] = Db::q('SELECT stage, duration_text FROM proposal_schedule WHERE proposal_id = ? ORDER BY sort', [$id])->fetchAll();
        $p['history'] = Db::q('SELECT * FROM approvals WHERE entity = ? AND entity_id = ? ORDER BY id', ['proposal', $id])->fetchAll();
        return [$p, $c];
    }

    private static function publicUrl(array $p): string
    {
        return rtrim((string) env('APP_URL', ''), '/') . '/p/' . $p['public_token'];
    }

    public const DEFAULT_STAGES = ['Briefing validado', 'Conceito', 'Anteprojeto', 'Detalhamento', 'Revisão', 'Aprovação', 'Entrega'];
    private const TEXT_FIELDS = ['project_summary' => 3000, 'scope' => 5000, 'deliverables' => 5000, 'exclusions' => 3000, 'payment_terms' => 2000, 'revision_policy' => 2000, 'notes' => 5000];

    private static function template(?string $service): ?array
    {
        return $service ? (Db::q('SELECT * FROM proposal_templates WHERE service = ?', [$service])->fetch() ?: null) : null;
    }

    /** Defaults for a new proposal: the service template plus what we know about the client. */
    private static function blank(array $c): array
    {
        $t = self::template($c['service'] ?? null);
        $bits = array_filter([$c['service'] ? mb_strtolower($c['service']) : null, $c['area_m2'] ? area($c['area_m2']) : null, $c['city'] ? 'em ' . $c['city'] : null]);
        return [
            'title' => trim(($c['service'] ?? 'Projeto') . ($c['area_m2'] ? ' · ' . area($c['area_m2']) : '')),
            'valid_until' => date('Y-m-d', strtotime('+15 days')),
            'project_summary' => $bits ? 'Projeto de ' . implode(' ', $bits) . '.' : '',
            'scope' => $t['scope'] ?? '', 'deliverables' => $t['deliverables'] ?? '', 'exclusions' => $t['exclusions'] ?? '',
            'revision_policy' => $t['revision_policy'] ?? 'Inclui 2 rodadas de revisão em cada etapa. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.',
            'payment_terms' => '', 'notes' => 'Atendimento 100% online, com reuniões por videochamada.',
            'schedule' => array_map(fn($st) => ['stage' => $st, 'duration_text' => ''], self::DEFAULT_STAGES),
            'qty' => [], 'paid' => [],
        ];
    }

    /** Pure: keeps schedule rows that have both a stage and a duration. */
    public static function scheduleFrom(array $stages, array $durations): array
    {
        $out = [];
        foreach ($stages as $k => $st) {
            $st = mb_substr(trim((string) $st), 0, 120); $du = mb_substr(trim((string) ($durations[$k] ?? '')), 0, 80);
            if ($st !== '' && $du !== '') $out[] = ['stage' => $st, 'duration_text' => $du];
        }
        return $out;
    }

    /** Reads and validates the form. @return array{0: array, 1: array, 2: string[]} [data, quote, errors] */
    private static function fromPost(): array
    {
        $items = array_column(self::items(), null, 'code');
        $lines = []; $paid = []; $errors = [];
        foreach ((array) ($_POST['qty'] ?? []) as $code => $q) {
            $q = (float) str_replace(',', '.', (string) $q);
            if (!isset($items[$code]) || $q <= 0) continue;
            if ($q > 99999) { $errors[] = 'Quantidade alta demais em ' . $items[$code]['label'] . '.'; continue; }
            $lines[] = [$items[$code], $q];
        }
        foreach ((array) ($_POST['paid'] ?? []) as $code) if (isset($items[$code]) && (int) $items[$code]['credit_rank'] > 0) $paid[] = $items[$code];
        $d = ['title' => mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160), 'valid_until' => (string) ($_POST['valid_until'] ?? '')];
        foreach (self::TEXT_FIELDS as $k => $max) $d[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
        $d['schedule'] = self::scheduleFrom((array) ($_POST['stage'] ?? []), (array) ($_POST['duration'] ?? []));
        $d['qty'] = (array) ($_POST['qty'] ?? []); $d['paid'] = (array) ($_POST['paid'] ?? []);
        if ($d['title'] === '') $errors[] = 'Informe o título da proposta.';
        if (!$lines) $errors[] = 'Inclua pelo menos um item com quantidade.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['valid_until']) || $d['valid_until'] < date('Y-m-d')) $errors[] = 'A validade precisa ser uma data a partir de hoje.';
        return [$d, Pricing::quote($lines, $paid) + ['codes' => array_map(fn($l) => $l[0]['code'], $lines)], $errors];
    }

    private static function persist(array $c, array $d, array $q, int $userId, ?array $parent): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $oppId = $parent['opportunity_id'] ?? Opportunities::forProposal($c)['id'];
        Db::q('INSERT INTO proposals (client_id, opportunity_id, user_id, title, credit, total, valid_until, notes, project_summary, scope, deliverables, exclusions, payment_terms, revision_policy, version, parent_id, public_token)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$c['id'], $oppId, $userId, $d['title'], $q['credit'], $q['total'], $d['valid_until'], $d['notes'], $d['project_summary'], $d['scope'], $d['deliverables'], $d['exclusions'], $d['payment_terms'], $d['revision_policy'],
             $parent ? (int) $parent['version'] + 1 : 1, $parent['id'] ?? null, bin2hex(random_bytes(32))]);
        $pid = (int) $pdo->lastInsertId();
        foreach ($q['lines'] as $k => $l) Db::q('INSERT INTO proposal_items (proposal_id, code, label, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)', [$pid, $q['codes'][$k] ?? null, $l['label'], $l['quantity'], $l['unit_price'], $l['line_total']]);
        foreach ($d['schedule'] as $k => $row) Db::q('INSERT INTO proposal_schedule (proposal_id, stage, duration_text, sort) VALUES (?,?,?,?)', [$pid, $row['stage'], $row['duration_text'], $k]);
        if ($parent) Db::q("UPDATE proposals SET status = 'substituida' WHERE id = ?", [$parent['id']]);
        $pdo->commit();
        return $pid;
    }

    private static function form(array $c, array $d, array $errors, ?array $p = null): void
    {
        $items = self::items();
        $study = $c['area_m2'] ? Pricing::studyFor($items, (float) $c['area_m2']) : null;
        view('proposal_form', ['c' => $c, 'items' => $items, 'suggested' => $p ? null : ($study['code'] ?? null), 'd' => $d, 'errors' => $errors, 'p' => $p]);
    }

    public static function create(string $id): void
    {
        Auth::requirePerm('proposals');
        $c = Clients::findOrFail((int) $id);
        self::form($c, self::blank($c), []);
    }

    public static function store(string $id): void
    {
        $u = Auth::requirePerm('proposals');
        $c = Clients::findOrFail((int) $id);
        [$d, $q, $errors] = self::fromPost();
        if ($errors) { http_response_code(422); self::form($c, $d, $errors); return; }
        $pid = self::persist($c, $d, $q, (int) $u['id'], null);
        Clients::event((int) $c['id'], 'proposta', "Proposta #$pid criada: {$d['title']} (" . money($q['total']) . ').');
        Audit::log('proposal.create', 'proposal', $pid);
        redirect("/propostas/$pid");
    }

    public static function edit(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        if (in_array($p['status'], ['aceita', 'recusada', 'substituida'], true)) { flash('Propostas aceitas, recusadas ou substituídas não mudam. Crie uma nova.', 'danger'); redirect('/propostas/' . $p['id']); }
        $d = array_intersect_key($p, array_flip(['title', 'valid_until', 'notes', 'project_summary', 'scope', 'deliverables', 'exclusions', 'payment_terms', 'revision_policy']));
        if ($d['valid_until'] < date('Y-m-d')) $d['valid_until'] = date('Y-m-d', strtotime('+15 days'));
        $d['schedule'] = $p['schedule'] ?: array_map(fn($st) => ['stage' => $st, 'duration_text' => ''], self::DEFAULT_STAGES);
        $d['qty'] = []; foreach ($p['items'] as $i) if ($i['code']) $d['qty'][$i['code']] = rtrim(rtrim((string) $i['quantity'], '0'), '.');
        $d['paid'] = [];
        self::form($c, $d, [], $p);
    }

    /** A draft is edited in place; once sent, an edit becomes a new version and the old one is kept as "substituída". */
    public static function update(string $id): void
    {
        $u = Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        if (in_array($p['status'], ['aceita', 'recusada', 'substituida'], true)) abort(409, 'Esta proposta não pode mais ser alterada.');
        [$d, $q, $errors] = self::fromPost();
        if ($errors) { http_response_code(422); self::form($c, $d, $errors, $p); return; }
        if ($p['status'] === 'rascunho') {
            $pid = self::persist($c, $d, $q, (int) $u['id'], $p['parent_id'] ? ['id' => $p['parent_id'], 'version' => (int) $p['version'] - 1, 'opportunity_id' => $p['opportunity_id']] : null);
            // ponytail: the replaced draft was never sent, so nothing points at it; drop it to keep one draft per edit
            Db::q("DELETE FROM proposals WHERE id = ? AND status = 'rascunho'", [$p['id']]);
            Clients::event((int) $c['id'], 'proposta', "Rascunho da proposta atualizado (agora #$pid).");
        } else {
            $pid = self::persist($c, $d, $q, (int) $u['id'], $p);
            Clients::event((int) $c['id'], 'proposta', "Proposta #{$p['id']} atualizada: nova versão #$pid (v" . ((int) $p['version'] + 1) . ').');
        }
        Audit::log('proposal.update', 'proposal', $pid);
        flash($p['status'] === 'rascunho' ? 'Rascunho salvo.' : 'Nova versão criada. Envie de novo para o cliente; o link antigo leva para esta versão.');
        redirect("/propostas/$pid");
    }

    // ---- Service templates (admin) ----

    public static function templates(): void
    {
        Auth::requireAdmin();
        view('proposal_templates', ['rows' => Db::q('SELECT * FROM proposal_templates ORDER BY id')->fetchAll()]);
    }

    public static function saveTemplates(): void
    {
        Auth::requireAdmin();
        foreach ((array) ($_POST['t'] ?? []) as $id => $f) {
            $v = fn(string $k, int $max) => mb_substr(trim((string) ($f[$k] ?? '')), 0, $max);
            Db::q('UPDATE proposal_templates SET scope = ?, deliverables = ?, exclusions = ?, revision_policy = ? WHERE id = ?',
                [$v('scope', 5000), $v('deliverables', 5000), $v('exclusions', 3000), $v('revision_policy', 2000), (int) $id]);
        }
        Audit::log('proposal_templates.update');
        flash('Modelos salvos. Valem para as próximas propostas.');
        redirect('/precos/modelos');
    }

    public static function index(): void
    {
        Auth::requirePerm('proposals');
        [$where, $params] = Clients::scope();
        $status = (string) ($_GET['status'] ?? '');
        $counts = Db::q("SELECT p.status, COUNT(*) FROM proposals p JOIN clients c ON c.id = p.client_id WHERE $where GROUP BY p.status", $params)->fetchAll(\PDO::FETCH_KEY_PAIR);
        $sum = Db::q("SELECT COALESCE(SUM(CASE WHEN p.status IN ('enviada','vista') THEN p.total END),0) open_v, COALESCE(SUM(CASE WHEN p.status = 'aceita' THEN p.total END),0) won_v FROM proposals p JOIN clients c ON c.id = p.client_id WHERE $where", $params)->fetch();
        if (isset(self::STATUS[$status])) { $where .= ' AND p.status = ?'; $params[] = $status; } else $status = '';
        $rows = Db::q("SELECT p.id, p.title, p.status, p.total, p.valid_until, p.sent_at, p.viewed_at, p.created_at, c.id AS client_id, c.name AS client_name
            FROM proposals p JOIN clients c ON c.id = p.client_id WHERE $where ORDER BY p.id DESC LIMIT 200", $params)->fetchAll();
        view('proposals', compact('rows', 'status', 'counts', 'sum'));
    }

    public static function show(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        view('proposal_show', ['p' => $p, 'c' => $c, 'url' => self::publicUrl($p)]);
    }

    public static function renderPdf(array $p, array $c): string
    {
        ob_start();
        require __DIR__ . '/../../views/proposal_pdf.php';
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml((string) ob_get_clean(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }

    public static function pdf(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="proposta-' . (int) $p['id'] . '.pdf"');
        echo self::renderPdf($p, $c);
    }

    private static function opportunityOf(array $p): ?array
    {
        return $p['opportunity_id'] ? (Db::q('SELECT * FROM opportunities WHERE id = ?', [$p['opportunity_id']])->fetch() ?: null) : null;
    }

    /** First send moves the opportunity to "Proposta enviada"; later stages are left alone. */
    private static function markSent(array $p, array $c, string $channel): void
    {
        Db::q("UPDATE proposals SET status = IF(status = 'rascunho', 'enviada', status), sent_at = COALESCE(sent_at, NOW()) WHERE id = ?", [$p['id']]);
        $o = self::opportunityOf($p);
        if ($o && in_array($o['stage'], ['novo', 'qualificado', 'briefing'], true)) Opportunities::move($o, 'proposta');
        Clients::event((int) $c['id'], $channel, 'Proposta #' . $p['id'] . ' enviada por ' . ($channel === 'email' ? 'e-mail' : 'WhatsApp') . '.');
        Audit::log('proposal.send.' . $channel, 'proposal', (int) $p['id']);
    }

    public static function email(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        if (!$c['email']) { flash('O cliente não tem e-mail cadastrado.', 'danger'); redirect('/propostas/' . $p['id']); }
        $first = explode(' ', $c['name'])[0];
        $body = "Olá, $first!\n\nSegue a proposta \"{$p['title']}\" no valor de " . money((float) $p['total']) . ", válida até " . date('d/m/Y', strtotime($p['valid_until'])) . ".\n\nVocê pode ver e responder pelo link:\n" . self::publicUrl($p) . "\n\nO PDF está em anexo. Qualquer dúvida, é só responder este e-mail ou chamar no WhatsApp.\n\nEquipe LI Projetos";
        if (Mail::send($c['email'], $c['name'], 'Proposta LI Projetos: ' . $p['title'], $body, [[self::renderPdf($p, $c), 'proposta-' . (int) $p['id'] . '.pdf', 'application/pdf']]) !== null) {
            flash('Não foi possível enviar o e-mail agora. Tente de novo ou envie pelo WhatsApp.', 'danger');
            redirect('/propostas/' . $p['id']);
        }
        self::markSent($p, $c, 'email');
        flash('Proposta enviada por e-mail para ' . $c['email'] . '.');
        redirect('/propostas/' . $p['id']);
    }

    /** Pure: WhatsApp text for a proposal. */
    public static function whatsappText(array $p, string $clientName, string $url): string
    {
        return 'Olá, ' . explode(' ', trim($clientName))[0] . '! Aqui é da LI Projetos. Segue a sua proposta "' . $p['title'] . '", no valor de '
            . money((float) $p['total']) . ', válida até ' . date('d/m/Y', strtotime($p['valid_until'])) . ":\n" . $url;
    }

    public static function whatsapp(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        if (!$c['whatsapp']) { flash('O cliente não tem WhatsApp cadastrado.', 'danger'); redirect('/propostas/' . $p['id']); }
        self::markSent($p, $c, 'whatsapp');
        // ponytail: wa.me link (manual send). DropeStore API plugs in here in phase 5.
        redirect('https://wa.me/' . ltrim($c['whatsapp'], '+') . '?text=' . rawurlencode(self::whatsappText($p, $c['name'], self::publicUrl($p))));
    }

    public static function status(string $id): void
    {
        Auth::requirePerm('proposals');
        [$p, $c] = self::load((int) $id);
        $s = (string) ($_POST['status'] ?? '');
        if (!in_array($s, ['aceita', 'recusada'], true)) abort(400);
        self::decide($p, $c, $s, 'pela equipe');
        redirect('/propostas/' . $p['id']);
    }

    private static function decide(array $p, array $c, string $s, string $by): void
    {
        Db::q('UPDATE proposals SET status = ?, decided_at = NOW() WHERE id = ?', [$s, $p['id']]);
        Clients::event((int) $c['id'], 'proposta', 'Proposta #' . $p['id'] . ' ' . $s . " $by.");
        $o = self::opportunityOf($p);
        if ($o) Opportunities::move($o, $s === 'aceita' ? 'ganho' : 'negociacao');
        if ($s === 'aceita') Projects::fromProposal($p, $o ? (int) $o['id'] : null);
    }

    // ---- Public page for the client (no login; the 64-hex token is the credential) ----

    private static function byToken(string $token): array
    {
        $p = Db::q('SELECT * FROM proposals WHERE public_token = ?', [$token])->fetch() ?: abort(404);
        $c = Db::q('SELECT id, name, stage FROM clients WHERE id = ?', [$p['client_id']])->fetch() ?: abort(404);
        $p['items'] = Db::q('SELECT * FROM proposal_items WHERE proposal_id = ? ORDER BY id', [$p['id']])->fetchAll();
        $p['schedule'] = Db::q('SELECT stage, duration_text FROM proposal_schedule WHERE proposal_id = ? ORDER BY sort', [$p['id']])->fetchAll();
        return [$p, $c];
    }

    public static function publicShow(string $token): void
    {
        [$p, $c] = self::byToken($token);
        if ($p['status'] === 'substituida') {
            $next = Db::q("SELECT public_token, status FROM proposals WHERE parent_id = ? ORDER BY id DESC LIMIT 1", [$p['id']])->fetch();
            if ($next && $next['status'] !== 'rascunho') redirect('/p/' . $next['public_token']);
        }
        if ($p['status'] === 'rascunho') abort(404);
        if ($p['status'] === 'enviada' && !Auth::user()) {
            Db::q("UPDATE proposals SET status = 'vista', viewed_at = NOW() WHERE id = ?", [$p['id']]);
            Clients::event((int) $c['id'], 'proposta', 'Cliente abriu a proposta #' . $p['id'] . '.');
            Notify::clientTeam(self::ownerOf((int) $c['id']), $c['name'] . ' abriu a proposta #' . $p['id'], '/propostas/' . $p['id']);
            $p['status'] = 'vista';
        }
        header('X-Robots-Tag: noindex');
        view('proposal_public', ['p' => $p, 'c' => $c, 'expired' => $p['valid_until'] < date('Y-m-d')], 'layout_guest');
    }

    /** Pure: a full name has at least two words of 2+ letters. */
    public static function isFullName(string $name): bool
    {
        return count(array_filter(preg_split('/\s+/', trim($name)) ?: [], fn($w) => mb_strlen($w) >= 2)) >= 2;
    }

    public static function publicAnswer(string $token): void
    {
        [$p, $c] = self::byToken($token);
        $accept = ($_POST['answer'] ?? '') === 'aceitar';
        $name = mb_substr(trim((string) ($_POST['signer'] ?? '')), 0, 160);
        if (!in_array($p['status'], ['enviada', 'vista'], true) || $p['valid_until'] < date('Y-m-d')) redirect('/p/' . $token);
        if ($accept && (!self::isFullName($name) || empty($_POST['agree']))) { $_SESSION['accept_error'] = 'Para aceitar, digite o seu nome completo e marque que leu e concorda.'; redirect('/p/' . $token . '#aceite'); }
        $s = $accept ? 'aceita' : 'recusada';
        Db::q('INSERT INTO approvals (entity, entity_id, version, decision, name, ip, user_agent) VALUES (?,?,?,?,?,?,?)',
            ['proposal', $p['id'], $p['version'], $s, $name !== '' ? $name : $c['name'], client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        self::decide($p, $c, $s, 'pelo cliente' . ($name !== '' ? " ($name)" : ''));
        Notify::clientTeam(self::ownerOf((int) $c['id']), $c['name'] . ($accept ? ' aceitou' : ' recusou') . ' a proposta #' . $p['id'], '/propostas/' . $p['id']);
        redirect('/p/' . $token);
    }

    private static function ownerOf(int $clientId): ?int
    {
        $o = Db::q('SELECT owner_id FROM clients WHERE id = ?', [$clientId])->fetchColumn();
        return $o ? (int) $o : null;
    }

    // ---- Price table (admin) ----

    public static function prices(): void
    {
        Auth::requireAdmin();
        view('prices', ['items' => self::items(false)]);
    }

    public static function savePrices(): void
    {
        Auth::requireAdmin();
        foreach ((array) ($_POST['price'] ?? []) as $id => $price) {
            $price = (float) str_replace(',', '.', (string) $price);
            $min = trim((string) ($_POST['min_total'][$id] ?? ''));
            if ($price <= 0 || $price > 1000000) continue;
            Db::q('UPDATE price_items SET price = ?, min_total = ?, active = ? WHERE id = ?',
                [$price, $min === '' ? null : (float) str_replace(',', '.', $min), isset($_POST['active'][$id]) ? 1 : 0, (int) $id]);
        }
        Audit::log('prices.update');
        flash('Tabela de preços salva.');
        redirect('/precos');
    }
}
