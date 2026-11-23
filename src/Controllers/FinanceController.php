<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Clients, Db, Finance, Mail};

/** /financeiro: what's overdue, what's coming, what came in. Payments are recorded by hand. Nothing is deleted. */
final class FinanceController
{
    public const VIEWS = ['vencidas' => 'Vencidas', 'abertas' => 'A receber', 'pagas' => 'Recebidas'];

    private static function back(array $i): never
    {
        $b = (string) ($_POST['back'] ?? '');
        redirect(str_starts_with($b, '/') && !str_starts_with($b, '//') ? $b : '/projetos/' . $i['project_id'] . '#financeiro');
    }

    /** Installment + project + client, finance permission required. */
    private static function load(string $id): array
    {
        Auth::requirePerm('finance');
        $i = Db::q("SELECT i.*, p.title AS project_title, c.name AS client_name, c.whatsapp, c.email,
                (SELECT COUNT(*) FROM installments x WHERE x.project_id = i.project_id AND x.status <> 'cancelada') AS n_total
            FROM installments i JOIN projects p ON p.id = i.project_id JOIN clients c ON c.id = i.client_id WHERE i.id = ?", [(int) $id])->fetch();
        return $i ?: abort(404);
    }

    /** Pure: "1.234,56" / "1234.56" / "800" → float, null if not a positive number. */
    public static function money(mixed $v): ?float
    {
        $v = trim(str_replace(['R$', ' '], '', (string) $v));
        if ($v === '') return null;
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        return is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
    }

    private static function date(mixed $s): ?string
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : null;
    }

    public static function index(): void
    {
        Auth::requirePerm('finance');
        $counts = Db::q("SELECT COALESCE(SUM(status = 'aberta' AND due_date < CURDATE()),0) vencidas, COALESCE(SUM(status = 'aberta' AND due_date >= CURDATE()),0) abertas,
                COALESCE(SUM(status = 'paga' AND paid_at >= CURDATE() - INTERVAL 30 DAY),0) pagas FROM installments")->fetch();
        $view = isset(self::VIEWS[$_GET['ver'] ?? '']) ? $_GET['ver'] : ((int) $counts['vencidas'] ? 'vencidas' : 'abertas');
        $cond = ['vencidas' => "i.status = 'aberta' AND i.due_date < CURDATE()", 'abertas' => "i.status = 'aberta' AND i.due_date >= CURDATE()", 'pagas' => "i.status = 'paga' AND i.paid_at >= CURDATE() - INTERVAL 30 DAY"][$view];
        $rows = Db::q("SELECT i.*, p.title AS project_title, c.name AS client_name, c.whatsapp, c.email,
                (SELECT COUNT(*) FROM installments x WHERE x.project_id = i.project_id AND x.status <> 'cancelada') AS n_total
            FROM installments i JOIN projects p ON p.id = i.project_id JOIN clients c ON c.id = i.client_id
            WHERE $cond ORDER BY " . ($view === 'pagas' ? 'i.paid_at DESC' : 'i.due_date') . ' LIMIT 300')->fetchAll();
        $tot = Db::q("SELECT COALESCE(SUM(CASE WHEN status = 'aberta' AND due_date < CURDATE() THEN amount END),0) vencido,
                COALESCE(SUM(CASE WHEN status = 'aberta' AND due_date >= CURDATE() THEN amount END),0) a_receber,
                COALESCE(SUM(CASE WHEN status = 'paga' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN COALESCE(paid_amount, amount) END),0) recebido_mes,
                COALESCE(SUM(CASE WHEN status = 'aberta' AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY THEN amount END),0) semana
            FROM installments")->fetch();
        $settings = ['pix_key' => Finance::setting('pix_key'), 'pix_name' => Finance::setting('pix_name'), 'hourly_cost' => Finance::setting('hourly_cost')];
        view('finance', compact('rows', 'view', 'counts', 'tot', 'settings'));
    }

    public static function pay(string $id): void
    {
        $i = self::load($id);
        if ($i['status'] !== 'aberta') self::back($i);
        $date = self::date($_POST['paid_at'] ?? null) ?? date('Y-m-d');
        $method = isset(Finance::METHODS[$_POST['method'] ?? '']) ? $_POST['method'] : 'pix';
        $amount = self::money($_POST['paid_amount'] ?? '') ?? (float) $i['amount'];
        if ($date > date('Y-m-d')) { flash('A data do pagamento não pode ser no futuro.', 'danger'); self::back($i); }
        Db::q("UPDATE installments SET status = 'paga', paid_at = ?, paid_amount = ?, method = ? WHERE id = ?", [$date, $amount, $method, $i['id']]);
        Clients::event((int) $i['client_id'], 'cobranca', 'Pagamento recebido: ' . money($amount) . ' (' . Finance::METHODS[$method] . ') · ' . $i['project_title'] . ', ' . ($i['description'] ?: 'parcela ' . $i['number']) . '.');
        Audit::log('installment.pay', 'installment', (int) $i['id']);
        flash('Pagamento registrado.');
        self::back($i);
    }

    /** Undo a payment recorded by mistake (estorno) or reactivate a cancelled one. The row stays; the audit log keeps both actions. */
    public static function reopen(string $id): void
    {
        $i = self::load($id);
        if ($i['status'] === 'aberta') self::back($i);
        Db::q("UPDATE installments SET status = 'aberta', paid_at = NULL, paid_amount = NULL, method = NULL WHERE id = ?", [$i['id']]);
        Clients::event((int) $i['client_id'], 'cobranca', ($i['status'] === 'paga' ? 'Pagamento estornado: ' : 'Parcela reativada: ') . money((float) $i['amount']) . ' · ' . $i['project_title'] . '.');
        Audit::log('installment.reopen', 'installment', (int) $i['id']);
        self::back($i);
    }

    public static function cancel(string $id): void
    {
        $i = self::load($id);
        if ($i['status'] !== 'aberta') self::back($i);
        Db::q("UPDATE installments SET status = 'cancelada' WHERE id = ?", [$i['id']]);
        Audit::log('installment.cancel', 'installment', (int) $i['id']);
        self::back($i);
    }

    public static function update(string $id): void
    {
        $i = self::load($id);
        $amount = self::money($_POST['amount'] ?? ''); $due = self::date($_POST['due_date'] ?? null);
        if ($i['status'] !== 'aberta' || !$amount || !$due) { flash('Informe valor e vencimento.', 'danger'); self::back($i); }
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 120) ?: $i['description'];
        Db::q('UPDATE installments SET amount = ?, due_date = ?, description = ? WHERE id = ?', [$amount, $due, $desc, $i['id']]);
        Audit::log('installment.update', 'installment', (int) $i['id']);
        self::back($i);
    }

    public static function add(string $id): void
    {
        Auth::requirePerm('finance');
        $p = Db::q('SELECT * FROM projects WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        $amount = self::money($_POST['amount'] ?? ''); $due = self::date($_POST['due_date'] ?? null);
        if (!$amount || !$due) { flash('Informe valor e vencimento.', 'danger'); redirect('/projetos/' . $p['id'] . '#financeiro'); }
        $n = 1 + (int) Db::q('SELECT COALESCE(MAX(number),0) FROM installments WHERE project_id = ?', [$p['id']])->fetchColumn();
        Db::q('INSERT INTO installments (project_id, client_id, number, description, amount, due_date) VALUES (?,?,?,?,?,?)',
            [$p['id'], $p['client_id'], $n, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 120) ?: "Parcela $n", $amount, $due]);
        Audit::log('installment.create', 'installment', (int) Db::pdo()->lastInsertId());
        redirect('/projetos/' . $p['id'] . '#financeiro');
    }

    /** Collect: WhatsApp opens wa.me with the message ready; e-mail is sent now. Both go to the client's history. */
    public static function remind(string $id): void
    {
        $i = self::load($id);
        if ($i['status'] !== 'aberta') self::back($i);
        $channel = ($_POST['canal'] ?? '') === 'email' ? 'email' : 'whatsapp';
        $text = Finance::message($i, $i['client_name'], $i['project_title'], (int) $i['n_total'], date('Y-m-d'), Finance::setting('pix_key'), Finance::setting('pix_name'));
        if ($channel === 'whatsapp' && !$i['whatsapp']) { flash('O cliente não tem WhatsApp cadastrado.', 'danger'); self::back($i); }
        if ($channel === 'email') {
            if (!$i['email']) { flash('O cliente não tem e-mail cadastrado.', 'danger'); self::back($i); }
            $subject = (Finance::daysLate($i['due_date'], date('Y-m-d')) > 0 ? 'Parcela em aberto · ' : 'Lembrete de pagamento · ') . $i['project_title'];
            if (Mail::send($i['email'], $i['client_name'], $subject, $text . "\n\nEquipe LI Projetos") !== null) { flash('Não foi possível enviar o e-mail agora. Tente pelo WhatsApp.', 'danger'); self::back($i); }
        }
        Db::q('UPDATE installments SET reminders = reminders + 1, last_reminder_at = NOW() WHERE id = ?', [$i['id']]);
        Clients::event((int) $i['client_id'], 'cobranca', 'Cobrança por ' . ($channel === 'email' ? 'e-mail' : 'WhatsApp') . ': ' . money((float) $i['amount']) . ' com vencimento em ' . date('d/m/Y', strtotime($i['due_date'])) . ' · ' . $i['project_title'] . '.');
        Audit::log('installment.remind.' . $channel, 'installment', (int) $i['id']);
        if ($channel === 'whatsapp') redirect('https://wa.me/' . ltrim($i['whatsapp'], '+') . '?text=' . rawurlencode($text));
        flash('Lembrete enviado para ' . $i['email'] . '.');
        self::back($i);
    }

    public static function addExpense(string $id): void
    {
        $u = Auth::requirePerm('finance');
        $p = Db::q('SELECT id FROM projects WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        $amount = self::money($_POST['amount'] ?? ''); $date = self::date($_POST['date'] ?? null) ?? date('Y-m-d');
        $cat = isset(Finance::CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'outros';
        if (!$amount) { flash('Informe o valor da despesa.', 'danger'); redirect('/projetos/' . $p['id'] . '#financeiro'); }
        Db::q('INSERT INTO expenses (project_id, amount, category, description, date, created_by) VALUES (?,?,?,?,?,?)',
            [$p['id'], $amount, $cat, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 160) ?: null, $date, $u['id']]);
        Audit::log('expense.create', 'project', (int) $p['id']);
        redirect('/projetos/' . $p['id'] . '#financeiro');
    }

    public static function settings(): void
    {
        Auth::requireAdmin();
        $cost = self::money($_POST['hourly_cost'] ?? '') ?? 0.0;
        foreach (['pix_key' => mb_substr(trim((string) ($_POST['pix_key'] ?? '')), 0, 140), 'pix_name' => mb_substr(trim((string) ($_POST['pix_name'] ?? '')), 0, 120), 'hourly_cost' => (string) $cost] as $k => $v)
            Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        Audit::log('settings.finance');
        flash('Configurações salvas.');
        redirect('/financeiro');
    }

    /** Data for the project page card (null when the user can't see money). */
    public static function forProject(array $p, int $totalMin): ?array
    {
        if (!Auth::can('finance')) return null;
        $inst = Db::q('SELECT * FROM installments WHERE project_id = ? ORDER BY number, id', [$p['id']])->fetchAll();
        $exp = Db::q('SELECT * FROM expenses WHERE project_id = ? ORDER BY date DESC, id DESC', [$p['id']])->fetchAll();
        $sum = Finance::summary($p, $inst, (float) array_sum(array_column($exp, 'amount')), $totalMin, (float) Finance::setting('hourly_cost'), date('Y-m-d'));
        return ['installments' => $inst, 'expenses' => $exp, 'sum' => $sum];
    }
}
