<?php
declare(strict_types=1);
namespace App;

/** Installments, collection and margin. No payment API: Pix key in the message, payments recorded by hand. */
final class Finance
{
    public const STATUS = ['aberta' => 'Em aberto', 'paga' => 'Paga', 'cancelada' => 'Cancelada'];
    public const METHODS = ['pix' => 'Pix', 'cartao' => 'Cartão', 'transferencia' => 'Transferência', 'boleto' => 'Boleto', 'dinheiro' => 'Dinheiro'];
    public const CATEGORIES = ['impressao' => 'Impressão', 'deslocamento' => 'Deslocamento', 'software' => 'Software', 'terceiros' => 'Terceiros', 'outros' => 'Outros'];
    /** Days relative to the due date when the team is reminded (negative = before). */
    public const MILESTONES = [-3, 0, 1, 7, 15, 30];
    /** Fixed contract clause shown in every proposal (page and PDF); the client accepts it with "li e concordo". */
    public const DELIVERY_CLAUSE = 'A última parcela vence antes da entrega final. Os arquivos finais (editáveis, em alta resolução, PDF e DWG) são liberados após a quitação total do projeto. Até lá, você acompanha e aprova cada etapa por prévias no portal do cliente. Com parcela 7 dias ou mais em atraso, o projeto fica pausado até a regularização, e os prazos voltam a contar a partir do pagamento.';

    /**
     * Pure: installments from the free-text payment terms.
     * "50% na assinatura + 50% na entrega" → 2 parts; "3x" / "em 3 parcelas" → 3 equal; otherwise one part.
     * First part due at $start; with $end (start of the final phase) the last one is due then, before delivery; else parts are 30 days apart.
     */
    public static function plan(string $terms, float $total, string $start, ?string $end = null): array
    {
        $t = mb_strtolower($terms);
        preg_match_all('/(\d{1,3}(?:[.,]\d+)?)\s*%/', $t, $m);
        $pcts = array_map(fn($p) => (float) str_replace(',', '.', $p), $m[1]);
        if ($pcts && abs(array_sum($pcts) - 100) > 0.01) $pcts = [];      // percentages that don't add up → ignore
        if (!$pcts && preg_match('/(\d{1,2})\s*(?:x\b|vezes|parcelas)/u', $t, $n) && (int) $n[1] > 1) $pcts = array_fill(0, (int) $n[1], 100 / (int) $n[1]);
        if (!$pcts) $pcts = [100];
        $n = count($pcts); $out = []; $sum = 0.0;
        foreach ($pcts as $i => $p) {
            $amount = $i === $n - 1 ? round($total - $sum, 2) : round($total * $p / 100, 2);
            $sum += $amount;
            $due = $i === 0 ? $start : ($i === $n - 1 && $end && $end > $start ? $end : date('Y-m-d', strtotime("$start +" . (30 * $i) . ' days')));
            if ($i > 0 && $end && $end > $start && $due > $end) $due = $end; // nothing falls due after the last (pre-delivery) installment
            $out[] = ['number' => $i + 1, 'amount' => $amount, 'due_date' => $due, 'description' => $n === 1 ? 'Pagamento único' : ($i === 0 ? 'Entrada' : ($i === $n - 1 && $end ? 'Antes da entrega final' : 'Parcela ' . ($i + 1) . " de $n"))];
        }
        return $out;
    }

    /** Pure: whole days past due (negative = days left). */
    public static function daysLate(string $due, string $today): int
    {
        return (int) round((strtotime($today) - strtotime($due)) / 86400);
    }

    /** Anti-calote: a project is paused when an installment is this many days late. */
    public const HOLD_DAYS = 7;

    /** Pure: originals (full resolution, PDF, DWG) unlock only with nothing open and the contract fully paid. */
    public static function paidUp(?float $contract, array $installments): bool
    {
        $paid = 0.0;
        foreach ($installments as $i) {
            if ($i['status'] === 'aberta') return false;
            if ($i['status'] === 'paga') $paid += (float) ($i['paid_amount'] ?? $i['amount']);
        }
        return $paid + 0.01 >= (float) $contract;
    }

    /** Pure: pause the project when any open installment is HOLD_DAYS or more late. */
    public static function shouldHold(array $installments, string $today): bool
    {
        foreach ($installments as $i) if ($i['status'] === 'aberta' && self::daysLate($i['due_date'], $today) >= self::HOLD_DAYS) return true;
        return false;
    }

    /**
     * Pure: a client's payment record. Badge: "Inadimplente" (something HOLD_DAYS+ late), "Em atraso" (late now),
     * "Pagou com atraso" (history), "Bom pagador" (always on time) or "Sem histórico".
     */
    public static function payerSummary(array $installments, string $today): array
    {
        $r = ['paid' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'late_paid' => 0, 'max_late' => 0, 'count' => 0];
        foreach ($installments as $i) {
            if ($i['status'] === 'cancelada') continue;
            $r['count']++;
            if ($i['status'] === 'paga') {
                $r['paid'] += (float) ($i['paid_amount'] ?? $i['amount']);
                if ($i['paid_at'] && $i['paid_at'] > $i['due_date']) $r['late_paid']++;
                continue;
            }
            $r['open'] += (float) $i['amount'];
            $late = self::daysLate($i['due_date'], $today);
            if ($late > 0) { $r['overdue'] += (float) $i['amount']; $r['max_late'] = max($r['max_late'], $late); }
        }
        $r['badge'] = $r['max_late'] >= self::HOLD_DAYS ? ['Inadimplente', 'danger'] : ($r['max_late'] > 0 ? ['Em atraso', 'warning']
            : ($r['late_paid'] ? ['Pagou com atraso', 'warning'] : ($r['paid'] > 0 ? ['Bom pagador', 'success'] : ['Sem histórico', 'light'])));
        return $r;
    }

    public static function projectPaidUp(array $project): bool
    {
        return self::paidUp((float) ($project['contract_value'] ?? 0), Db::q('SELECT status, amount, paid_amount FROM installments WHERE project_id = ?', [$project['id']])->fetchAll());
    }

    /** Pauses an active project for late payment, or resumes one paused for that reason. Returns 'paused', 'resumed' or null. */
    public static function syncHold(int $projectId): ?string
    {
        $p = Db::q('SELECT id, client_id, title, status, hold_reason FROM projects WHERE id = ?', [$projectId])->fetch();
        if (!$p) return null;
        $hold = self::shouldHold(Db::q('SELECT status, due_date FROM installments WHERE project_id = ?', [$projectId])->fetchAll(), date('Y-m-d'));
        if ($hold && $p['status'] === 'ativo') {
            Db::q("UPDATE projects SET status = 'pausado', hold_reason = 'pagamento' WHERE id = ?", [$projectId]);
            Clients::event((int) $p['client_id'], 'cobranca', 'Projeto ' . $p['title'] . ' pausado: parcela com ' . self::HOLD_DAYS . '+ dias de atraso.');
            return 'paused';
        }
        if (!$hold && $p['status'] === 'pausado' && $p['hold_reason'] === 'pagamento') {
            Db::q("UPDATE projects SET status = 'ativo', hold_reason = NULL WHERE id = ?", [$projectId]);
            Clients::event((int) $p['client_id'], 'cobranca', 'Projeto ' . $p['title'] . ' retomado: pagamento em dia.');
            return 'resumed';
        }
        return null;
    }

    /** Pure: the team-reminder milestone hit today, or null. */
    public static function milestone(string $due, string $today): ?int
    {
        $d = self::daysLate($due, $today);
        return in_array($d, self::MILESTONES, true) ? $d : null;
    }

    /** Pure: friendly collection message for the client (WhatsApp or e-mail body). */
    public static function message(array $i, string $clientName, string $project, int $count, string $today, string $pixKey = '', string $pixName = ''): string
    {
        $first = explode(' ', trim($clientName))[0];
        $late = self::daysLate($i['due_date'], $today);
        $desc = (string) ($i['description'] ?: 'parcela');
        $what = 'a ' . mb_strtolower($desc) . ($count > 1 && !str_contains($desc, ' de ') ? " ({$i['number']} de $count)" : '')
              . ' do projeto ' . $project . ', no valor de ' . money((float) $i['amount']);
        $when = date('d/m', strtotime($i['due_date']));
        $text = "Olá, $first! Tudo bem? Aqui é da " . Brand::name() . ". ";
        $text .= $late < 0 ? "Passando para lembrar que $what vence em $when."
               : ($late === 0 ? "Passando para lembrar que $what vence hoje ($when)."
               : "Notamos que $what, com vencimento em $when, ainda está em aberto. Se já pagou, pode desconsiderar e nos mandar o comprovante, por favor.");
        if ($pixKey !== '') $text .= "\n\nPix: $pixKey" . ($pixName !== '' ? " ($pixName)" : '');
        return $text . "\n\nQualquer dúvida, é só responder esta mensagem. Obrigada!";
    }

    public static function setting(string $k): string
    {
        return (string) (Db::q('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn() ?: '');
    }

    /** Creates the installments of a new project from its proposal. */
    public static function createFor(int $projectId, int $clientId, string $terms, float $total, string $start, ?string $end): void
    {
        if ($total <= 0) return;
        foreach (self::plan($terms, $total, $start, $end) as $p)
            Db::q('INSERT INTO installments (project_id, client_id, number, description, amount, due_date) VALUES (?,?,?,?,?,?)',
                [$projectId, $clientId, $p['number'], $p['description'], $p['amount'], $p['due_date']]);
    }

    /** Pure: contracted, received, open, overdue, expenses, labour and margin for one project. */
    public static function summary(array $project, array $installments, float $expenses, int $minutes, float $hourlyCost, string $today): array
    {
        $live = array_filter($installments, fn($i) => $i['status'] !== 'cancelada');
        $received = array_sum(array_map(fn($i) => $i['status'] === 'paga' ? (float) ($i['paid_amount'] ?? $i['amount']) : 0, $live));
        $open = array_sum(array_map(fn($i) => $i['status'] === 'aberta' ? (float) $i['amount'] : 0, $live));
        $overdue = array_sum(array_map(fn($i) => $i['status'] === 'aberta' && $i['due_date'] < $today ? (float) $i['amount'] : 0, $live));
        $contract = (float) ($project['contract_value'] ?? 0);
        $labour = round($minutes / 60 * $hourlyCost, 2);
        return ['contract' => $contract, 'received' => $received, 'open' => $open, 'overdue' => $overdue, 'expenses' => $expenses, 'labour' => $labour,
                'margin' => round($contract - $expenses - $labour, 2), 'planned' => round(array_sum(array_map(fn($i) => (float) $i['amount'], $live)), 2)];
    }
}
