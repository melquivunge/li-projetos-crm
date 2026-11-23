<?php
declare(strict_types=1);
namespace App;

/** Projects: born when a proposal is accepted; phases come from the proposal schedule. */
final class Projects
{
    public const STATUS = ['ativo' => 'Em andamento', 'pausado' => 'Pausado', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'];
    public const PHASE_STATUS = ['pendente' => 'A fazer', 'andamento' => 'Em andamento', 'concluida' => 'Concluída'];
    public const DELIVERABLE_STATUS = ['pendente' => 'A fazer', 'producao' => 'Em produção', 'enviado' => 'Enviado ao cliente', 'ajustes' => 'Ajustes pedidos', 'aprovado' => 'Aprovado'];
    public const DEFAULT_PHASES = ['Briefing validado', 'Conceito', 'Anteprojeto', 'Detalhamento', 'Revisão', 'Aprovação', 'Entrega'];

    /** Pure: calendar days in a free-text duration ("15 dias", "2 semanas", "10 dias úteis", "3 a 4 semanas", "1 mês"). Null if unreadable. */
    public static function days(string $text): ?int
    {
        $t = mb_strtolower($text);
        if (!preg_match_all('/\d+/', $t, $m)) return null;
        $n = max(array_map('intval', $m[0]));        // "3 a 4 semanas" → the longer bound
        if ($n <= 0) return null;
        if (preg_match('/m[eê]s|meses/u', $t)) return $n * 30;
        if (str_contains($t, 'semana')) return $n * 7;
        if (preg_match('/[úu]teis/u', $t)) return (int) ceil($n * 7 / 5);
        if (str_contains($t, 'dia')) return $n;
        return null;
    }

    /** Pure: phases with due dates laid end to end from $start. Empty schedule → the 7 default phases, no dates. */
    public static function plan(array $schedule, string $start): array
    {
        if (!$schedule) return array_map(fn($n) => ['name' => $n, 'due_date' => null], self::DEFAULT_PHASES);
        $cur = strtotime($start); $out = [];
        foreach ($schedule as $row) {
            $d = self::days((string) $row['duration_text']);
            $cur = ($d !== null && $cur) ? strtotime("+$d days", $cur) : null; // one unreadable duration → later dates unknown
            $out[] = ['name' => $row['stage'], 'due_date' => $cur ? date('Y-m-d', $cur) : null];
        }
        return $out;
    }

    /** Pure: weeks (Mon–Sun) covering a month "YYYY-MM"; each cell is a Y-m-d date. */
    public static function monthGrid(string $ym): array
    {
        $first = strtotime("$ym-01");
        $from = strtotime('-' . ((int) date('N', $first) - 1) . ' days', $first);
        $last = strtotime(date('Y-m-t', $first));
        $to = strtotime('+' . (7 - (int) date('N', $last)) . ' days', $last);
        $weeks = [];
        for ($d = $from, $i = 0; $d <= $to; $d = strtotime('+1 day', $d), $i++) $weeks[intdiv($i, 7)][] = date('Y-m-d', $d);
        return $weeks;
    }

    /** Pure: the phase a project is in = first one not done. */
    public static function current(array $phases): ?array
    {
        foreach ($phases as $ph) if ($ph['status'] !== 'concluida') return $ph;
        return null;
    }

    /** Pure: statuses after moving a project to phase $targetId (null = all done). Earlier phases done, target and later open. */
    public static function moveStatuses(array $phases, ?int $targetId): array
    {
        $out = []; $reached = false;
        foreach ($phases as $ph) {
            if ($targetId !== null && (int) $ph['id'] === $targetId) $reached = true;
            $out[(int) $ph['id']] = $reached ? 'pendente' : 'concluida';
        }
        return $out;
    }

    /** Moves a project to the phase called $name ("" = conclude all). False if it has no such phase. */
    public static function moveTo(int $projectId, string $name): bool
    {
        $phases = self::phases($projectId);
        $target = null;
        if ($name !== '') {
            foreach ($phases as $ph) if ($ph['name'] === $name) { $target = (int) $ph['id']; break; }
            if ($target === null) return false;
        }
        foreach (self::moveStatuses($phases, $target) as $id => $st) {
            $was = array_values(array_filter($phases, fn($p) => (int) $p['id'] === $id))[0]['status'];
            if (($st === 'concluida') === ($was === 'concluida')) continue;
            Db::q('UPDATE project_phases SET status = ?, done_at = ' . ($st === 'concluida' ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$st, $id]);
        }
        self::sync($projectId);
        return true;
    }

    public static function seeAll(): bool
    {
        return Auth::isAdmin() || in_array('gestor', Auth::roles(), true);
    }

    /** Admins and project managers (gestor) see every project; others only the ones they manage or have a task in. */
    public static function scope(string $a = 'p'): array
    {
        if (self::seeAll()) return ['1=1', []];
        return ["($a.manager_id = ? OR EXISTS (SELECT 1 FROM tasks tv WHERE tv.project_id = $a.id AND tv.owner_id = ?))", [Auth::id(), Auth::id()]];
    }

    public static function findOrFail(int $id): array
    {
        [$w, $params] = self::scope();
        $p = Db::q("SELECT p.*, c.name AS client_name, u.name AS manager_name FROM projects p JOIN clients c ON c.id = p.client_id
                    LEFT JOIN users u ON u.id = p.manager_id WHERE p.id = ?", [$id])->fetch() ?: abort(404);
        if (!Db::q("SELECT 1 FROM projects p WHERE p.id = ? AND $w", [$id, ...$params])->fetch()) abort(403);
        return $p;
    }

    public static function phases(int $projectId): array
    {
        return Db::q('SELECT * FROM project_phases WHERE project_id = ? ORDER BY sort, id', [$projectId])->fetchAll();
    }

    /** Marks the first open phase as in progress, and keeps the project due date = last phase date. */
    public static function sync(int $projectId): void
    {
        $phases = self::phases($projectId);
        $cur = self::current($phases);
        Db::q("UPDATE project_phases SET status = 'pendente' WHERE project_id = ? AND status = 'andamento' AND id <> ?", [$projectId, $cur['id'] ?? 0]);
        if ($cur) Db::q("UPDATE project_phases SET status = 'andamento' WHERE id = ?", [$cur['id']]);
        $due = $phases ? end($phases)['due_date'] : null;
        if ($due) Db::q('UPDATE projects SET due_date = ? WHERE id = ?', [$due, $projectId]);
    }

    /** Creates the project for an accepted proposal. Idempotent: one project per proposal. */
    public static function fromProposal(array $p, ?int $opportunityId): int
    {
        $existing = Db::q('SELECT id FROM projects WHERE proposal_id = ?', [$p['id']])->fetchColumn();
        if ($existing) return (int) $existing;
        $manager = Db::q('SELECT owner_id FROM clients WHERE id = ?', [$p['client_id']])->fetchColumn() ?: null;
        $start = date('Y-m-d');
        $schedule = Db::q('SELECT stage, duration_text FROM proposal_schedule WHERE proposal_id = ? ORDER BY sort', [$p['id']])->fetchAll();
        Db::q('INSERT INTO projects (client_id, opportunity_id, proposal_id, title, contract_value, revisions_included, manager_id, start_date) VALUES (?,?,?,?,?,?,?,?)',
            [$p['client_id'], $opportunityId, $p['id'], mb_substr((string) $p['title'], 0, 160), (float) ($p['total'] ?? 0), Revisions::included($p['revision_policy'] ?? null), $manager, $start]);
        $id = (int) Db::pdo()->lastInsertId();
        foreach (self::plan($schedule, $start) as $i => $ph) Db::q('INSERT INTO project_phases (project_id, name, sort, due_date) VALUES (?,?,?,?)', [$id, $ph['name'], $i, $ph['due_date']]);
        self::sync($id);
        $due = Db::q('SELECT due_date FROM projects WHERE id = ?', [$id])->fetchColumn() ?: null;
        Finance::createFor($id, (int) $p['client_id'], (string) ($p['payment_terms'] ?? ''), (float) ($p['total'] ?? 0), $start, $due);
        Clients::event((int) $p['client_id'], 'sistema', 'Projeto #' . $id . ' criado a partir da proposta #' . $p['id'] . '.');
        if ($manager) Notify::user((int) $manager, 'Novo projeto: ' . $p['title'], '/projetos/' . $id);
        Audit::log('project.create', 'project', $id);
        return $id;
    }
}
