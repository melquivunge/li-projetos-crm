<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Audit, Clients, Db, Opportunities};

final class ClientController
{
    private const PER_PAGE = 50;

    private static function owners(): array
    {
        return Db::q('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** Filters from the query string → [where sql, params, filters for the view]. */
    private static function filters(): array
    {
        [$where, $params] = Clients::scope();
        $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'stage' => (string) ($_GET['stage'] ?? ''), 'owner' => (int) ($_GET['owner'] ?? 0)];
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $f['q']);
            $where .= ' AND (c.name LIKE ? OR c.email LIKE ? OR c.city LIKE ?' . ($digits !== '' ? ' OR c.whatsapp LIKE ?' : '') . ')';
            array_push($params, $like, $like, $like);
            if ($digits !== '') $params[] = "%$digits%";
        }
        if ($f['owner'] && Auth::isAdmin()) { $where .= ' AND c.owner_id = ?'; $params[] = $f['owner']; }
        $f['late'] = !empty($_GET['atrasadas']);
        if ($f['late']) $where .= ' AND EXISTS (SELECT 1 FROM tasks t WHERE t.client_id = c.id AND t.done_at IS NULL AND t.due_at < CURDATE())';
        $baseWhere = $where; $baseParams = $params; // before the stage filter: feeds the stage tab counts
        if (isset(Clients::STAGES[$f['stage']])) { $where .= ' AND c.stage = ?'; $params[] = $f['stage']; } else $f['stage'] = '';
        return [$where, $params, $f, $baseWhere, $baseParams];
    }

    public static function index(): void
    {
        Auth::requirePerm('crm');
        [$where, $params, $f, $baseWhere, $baseParams] = self::filters();
        $next = "(SELECT %s FROM tasks t WHERE t.client_id = c.id AND t.done_at IS NULL ORDER BY t.due_at LIMIT 1)";
        $sql = "SELECT c.*, u.name AS owner_name, u.avatar AS owner_avatar, " . sprintf($next, 't.title') . " AS next_action, " . sprintf($next, 't.due_at') . " AS next_action_at
                FROM clients c LEFT JOIN users u ON u.id = c.owner_id WHERE $where
                ORDER BY (next_action_at IS NULL), next_action_at, c.updated_at DESC";
        if (($_GET['export'] ?? '') === 'csv') { self::csv($sql, $params); return; }
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $total = (int) Db::q("SELECT COUNT(*) FROM clients c WHERE $where", $params)->fetchColumn();
        $rows = Db::q($sql . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params)->fetchAll();
        $counts = Db::q("SELECT c.stage, COUNT(*) FROM clients c WHERE $baseWhere GROUP BY c.stage", $baseParams)->fetchAll(\PDO::FETCH_KEY_PAIR);
        [$sw, $spp] = Clients::scope();
        $late = (int) Db::q("SELECT COUNT(*) FROM clients c WHERE $sw AND EXISTS (SELECT 1 FROM tasks t WHERE t.client_id = c.id AND t.done_at IS NULL AND t.due_at < CURDATE())", $spp)->fetchColumn();
        view('clients', ['counts' => $counts, 'late' => $late, 'clients' => $rows, 'f' => $f, 'owners' => self::owners(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE))]);
    }

    private static function csv(string $sql, array $params): void
    {
        Audit::log('client.export');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 only with BOM
        fputcsv($out, ['ID', 'Nome', 'WhatsApp', 'E-mail', 'Cidade', 'Serviço', 'Área m²', 'Etapa', 'Motivo da perda', 'Origem', 'Responsável', 'Criado em'], ';');
        foreach (Db::q($sql, $params) as $c) {
            fputcsv($out, array_map([Clients::class, 'csvCell'], [$c['id'], $c['name'], $c['whatsapp'], $c['email'], $c['city'], $c['service'], $c['area_m2'], Clients::STAGES[$c['stage']], $c['lost_reason'], $c['origin'], $c['owner_name'], $c['created_at']]), ';');
        }
        fclose($out);
    }

    public static function create(): void
    {
        Auth::requirePerm('crm');
        view('client_form', ['c' => ['owner_id' => Auth::id()], 'errors' => [], 'owners' => self::owners()]);
    }

    /** Team members always own what they create; admins may assign. */
    private static function ownerFromPost(?int $current): ?int
    {
        if (!Auth::isAdmin()) return $current ?? Auth::id();
        $o = (int) ($_POST['owner_id'] ?? 0);
        return $o && isset(self::owners()[$o]) ? $o : null;
    }

    /** Address lookup for the client form (ViaCEP, through the server: CSP keeps the browser on our own origin). Sends only the CEP. */
    public static function cep(string $cep): void
    {
        Auth::requirePerm('crm');
        header('Content-Type: application/json; charset=utf-8');
        if (strlen($cep) !== 8) { http_response_code(422); echo '{}'; return; }
        $ch = curl_init("https://viacep.com.br/ws/$cep/json/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'crm-cep/1.0']);
        $r = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (!is_array($r) || !empty($r['erro']) || empty($r['uf'])) { http_response_code(404); echo '{}'; return; } // ponytail: no cache, one lookup per typed CEP
        echo json_encode(['street' => (string) ($r['logradouro'] ?? ''), 'district' => (string) ($r['bairro'] ?? ''), 'city' => (string) ($r['localidade'] ?? ''), 'state' => (string) $r['uf']], JSON_UNESCAPED_UNICODE);
    }

    public static function store(): void
    {
        Auth::requirePerm('crm');
        [$d, $errors] = Clients::validate($_POST);
        [$pf, $pe] = Clients::validateProfile($_POST, date('Y-m-d')); $errors += $pe;
        if ($errors) { http_response_code(422); view('client_form', ['c' => $_POST, 'errors' => $errors, 'owners' => self::owners()]); return; }
        Db::q('INSERT INTO clients (name, whatsapp, email, city, service, area_m2, origin, gclid, owner_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$d['name'], $d['whatsapp'], $d['email'], $d['city'], $d['service'], $d['area_m2'], $d['origin'] ?? 'manual', $d['gclid'], self::ownerFromPost(null)]);
        $id = (int) Db::pdo()->lastInsertId();
        Clients::saveProfile($id, $pf);
        Opportunities::create($id, $d['service'] ?: 'Projeto', $d['service'], self::ownerFromPost(null), 'novo');
        Clients::event($id, 'sistema', 'Cliente cadastrado manualmente.');
        Audit::log('client.create', 'client', $id);
        flash('Cliente cadastrado.');
        redirect("/clientes/$id");
    }

    public static function show(string $id): void
    {
        Auth::requirePerm('crm');
        $c = Clients::findOrFail((int) $id);
        $events = Db::q('SELECT e.*, u.name AS user_name FROM client_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.client_id = ? ORDER BY e.created_at DESC, e.id DESC', [$c['id']])->fetchAll();
        $proposals = Db::q('SELECT id, title, status, total, version FROM proposals WHERE client_id = ? ORDER BY id DESC', [$c['id']])->fetchAll();
        $opps = Db::q("SELECT o.*, u.name AS owner_name FROM opportunities o LEFT JOIN users u ON u.id = o.owner_id WHERE o.client_id = ? ORDER BY FIELD(o.stage,'ganho','perdido'), o.id DESC", [$c['id']])->fetchAll();
        $tasks = Db::q('SELECT t.*, u.name AS owner_name FROM tasks t LEFT JOIN users u ON u.id = t.owner_id WHERE t.client_id = ? ORDER BY t.done_at IS NOT NULL, t.due_at, t.id DESC LIMIT 30', [$c['id']])->fetchAll();
        $owners = self::owners();
        $briefings = [];
        foreach (Db::q('SELECT id, opportunity_id, kind, status FROM briefings WHERE client_id = ? ORDER BY id', [$c['id']]) as $bf) $briefings[$bf['opportunity_id']][$bf['kind']] = $bf;
        $installments = Db::q("SELECT i.status, i.amount, i.paid_amount, i.due_date, i.paid_at FROM installments i JOIN projects p ON p.id = i.project_id WHERE i.client_id = ? AND p.status <> 'cancelado'", [$c['id']])->fetchAll();
        $contracted = (float) Db::q("SELECT COALESCE(SUM(contract_value),0) FROM projects WHERE client_id = ? AND status <> 'cancelado'", [$c['id']])->fetchColumn();
        $payer = \App\Finance::payerSummary($installments, date('Y-m-d')) + ['contracted' => $contracted];
        view('client_show', compact('c', 'events', 'proposals', 'opps', 'tasks', 'owners', 'briefings', 'payer'));
    }

    public static function edit(string $id): void
    {
        Auth::requirePerm('crm');
        view('client_form', ['c' => Clients::findOrFail((int) $id), 'errors' => [], 'owners' => self::owners()]);
    }

    public static function update(string $id): void
    {
        Auth::requirePerm('crm');
        $c = Clients::findOrFail((int) $id);
        [$d, $errors] = Clients::validate($_POST);
        [$pf, $pe] = Clients::validateProfile($_POST, date('Y-m-d')); $errors += $pe;
        if ($errors) { http_response_code(422); view('client_form', ['c' => ['id' => $c['id']] + $_POST, 'errors' => $errors, 'owners' => self::owners()]); return; }
        $prev = $c['owner_id'] !== null ? (int) $c['owner_id'] : null;
        $owner = self::ownerFromPost($prev);
        Db::q('UPDATE clients SET name=?, whatsapp=?, email=?, city=?, service=?, area_m2=?, origin=?, gclid=?, owner_id=? WHERE id=?',
            [$d['name'], $d['whatsapp'], $d['email'], $d['city'], $d['service'], $d['area_m2'], $d['origin'], $d['gclid'], $owner, $c['id']]);
        Clients::saveProfile((int) $c['id'], $pf);
        if ($owner !== $prev) Clients::event((int) $c['id'], 'sistema', 'Responsável alterado para ' . (self::owners()[$owner] ?? 'ninguém') . '.');
        Audit::log('client.update', 'client', (int) $c['id']);
        flash('Dados salvos.');
        redirect('/clientes/' . $c['id']);
    }

    public static function note(string $id): void
    {
        Auth::requirePerm('crm');
        $c = Clients::findOrFail((int) $id);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 5000) { flash('A nota precisa ter entre 1 e 5.000 caracteres.', 'danger'); redirect('/clientes/' . $c['id']); }
        $type = in_array($_POST['type'] ?? '', ['nota', 'reuniao', 'ligacao'], true) ? $_POST['type'] : 'nota';
        Clients::event((int) $c['id'], $type, $body);
        Db::q('UPDATE clients SET updated_at = NOW() WHERE id = ?', [$c['id']]);
        redirect('/clientes/' . $c['id']);
    }

    /** Pipeline board of opportunities. Won and lost only from the last 30 days, so the board stays about live deals. */
    public static function board(): void
    {
        Auth::requirePerm('crm');
        [$where, $params] = Clients::scope();
        $owner = Auth::isAdmin() ? (int) ($_GET['owner'] ?? 0) : 0;
        if ($owner) { $where .= ' AND o.owner_id = ?'; $params[] = $owner; }
        $next = "(SELECT %s FROM tasks t WHERE t.client_id = c.id AND t.done_at IS NULL ORDER BY t.due_at LIMIT 1)";
        $rows = Db::q("SELECT o.id, o.title, o.service, o.stage, o.estimated_value, o.expected_close, o.updated_at, o.stage_changed_at AS since, o.owner_id,
                c.id AS client_id, c.name, c.city, c.area_m2, c.origin, u.name AS owner_name, u.avatar AS owner_avatar,
                " . sprintf($next, 't.title') . " AS next_action, " . sprintf($next, 't.due_at') . " AS next_action_at,
                COALESCE((SELECT p.total FROM proposals p WHERE p.opportunity_id = o.id AND p.status <> 'substituida' ORDER BY p.id DESC LIMIT 1), o.estimated_value) AS value
            FROM opportunities o JOIN clients c ON c.id = o.client_id LEFT JOIN users u ON u.id = o.owner_id
            WHERE $where AND (o.stage NOT IN ('ganho','perdido') OR o.updated_at >= NOW() - INTERVAL 30 DAY)
            ORDER BY (next_action_at IS NULL), next_action_at, o.updated_at DESC", $params)->fetchAll();
        $cols = array_fill_keys(array_keys(Opportunities::STAGES), []);
        foreach ($rows as $r) $cols[$r['stage']][] = $r;
        view('board', ['cols' => $cols, 'owners' => self::owners(), 'owner' => $owner]);
    }

    /** LGPD erasure: admin only, removes the client and (by cascade) the timeline. */
    public static function destroy(string $id): void
    {
        Auth::requireAdmin();
        $c = Clients::findOrFail((int) $id);
        if (($_POST['confirm'] ?? '') !== 'EXCLUIR') { flash('Digite EXCLUIR para confirmar.', 'danger'); redirect('/clientes/' . $c['id']); }
        Db::q('DELETE FROM clients WHERE id = ?', [$c['id']]);
        Audit::log('client.delete', 'client', (int) $c['id']);
        flash('Cliente e histórico excluídos (LGPD).');
        redirect('/clientes');
    }
}
