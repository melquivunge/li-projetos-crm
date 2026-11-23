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

    /**
     * Pure: installments from the free-text payment terms.
     * "50% na assinatura + 50% na entrega" → 2 parts; "3x" / "em 3 parcelas" → 3 equal; otherwise one part.
     * First part due at $start; with $end the last one is due at $end, else parts are 30 days apart.
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
            $out[] = ['number' => $i + 1, 'amount' => $amount, 'due_date' => $due, 'description' => $n === 1 ? 'Pagamento único' : ($i === 0 ? 'Entrada' : 'Parcela ' . ($i + 1) . " de $n")];
        }
        return $out;
    }

    /** Pure: whole days past due (negative = days left). */
    public static function daysLate(string $due, string $today): int
    {
        return (int) round((strtotime($today) - strtotime($due)) / 86400);
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
