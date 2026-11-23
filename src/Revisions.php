<?php
declare(strict_types=1);
namespace App;

/**
 * Revision rounds. Each phase includes N rounds (from the proposal, default 2). Team errors ("correção") never count.
 * The round after the included ones, and any scope change, waits for the client to approve the extra cost.
 */
final class Revisions
{
    public const TYPES = ['revisao' => 'Revisão', 'correcao' => 'Correção (erro nosso)', 'escopo' => 'Mudança de escopo'];
    public const STATUS = ['aguardando_aprovacao' => 'Aguardando aprovação do custo', 'aberta' => 'A fazer', 'andamento' => 'Em andamento', 'concluida' => 'Concluída', 'recusada' => 'Recusada'];

    /** Pure: rounds included per phase, read from the proposal's revision policy ("Inclui 2 rodadas…"). */
    public static function included(?string $policy): int
    {
        $p = mb_strtolower((string) $policy);
        if (preg_match('/(\d{1,2})\s*rodadas?/u', $p, $m)) return (int) $m[1];
        if (preg_match('/\b(uma|duas|tr[eê]s)\s+rodadas?/u', $p, $m)) return ['uma' => 1, 'duas' => 2, 'três' => 3, 'tres' => 3][$m[1]];
        return 2;
    }

    /** Pure: how many included-type rounds a phase has used (refused requests don't count). */
    public static function used(array $requests, ?int $phaseId): int
    {
        return count(array_filter($requests, fn($r) => $r['type'] === 'revisao' && $r['status'] !== 'recusada' && (int) ($r['phase_id'] ?? 0) === (int) $phaseId));
    }

    /**
     * Pure: how a new request is filed. Returns [is_extra, round, status].
     * correção → never extra; revisão → extra once the included rounds are used; escopo → always extra.
     */
    public static function classify(string $type, int $used, int $included): array
    {
        if ($type === 'correcao') return [false, null, 'aberta'];
        if ($type === 'escopo') return [true, null, 'aguardando_aprovacao'];
        $round = $used + 1;
        return $round > $included ? [true, $round, 'aguardando_aprovacao'] : [false, $round, 'aberta'];
    }

    /** Pure: work may start only once nothing is pending approval. */
    public static function canStart(array $r): bool
    {
        return $r['status'] === 'aberta';
    }

    /** Files a request (team or client). Returns its id. */
    public static function create(array $project, ?int $phaseId, ?int $deliverableId, string $type, string $description, bool $fromClient, ?string $author, ?int $userId, ?string $due = null, ?float $cost = null): int
    {
        $all = Db::q('SELECT type, status, phase_id FROM revision_requests WHERE project_id = ?', [$project['id']])->fetchAll();
        [$extra, $round, $status] = self::classify($type, self::used($all, $phaseId), (int) $project['revisions_included']);
        Db::q('INSERT INTO revision_requests (project_id, phase_id, deliverable_id, type, is_extra, round, description, from_client, author_name, created_by, due_date, status, extra_cost) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$project['id'], $phaseId, $deliverableId, $type, $extra ? 1 : 0, $round, mb_substr($description, 0, 4000), $fromClient ? 1 : 0, $author, $userId, $due, $status, $extra ? $cost : null]);
        $id = (int) Db::pdo()->lastInsertId();
        $label = $extra ? ($type === 'escopo' ? 'mudança de escopo' : 'revisão extra (' . $round . 'ª rodada)') : mb_strtolower(self::TYPES[$type]) . ($round ? " ({$round}ª rodada)" : '');
        Clients::event((int) $project['client_id'], 'sistema', ($fromClient ? 'Cliente pediu ' : 'Pedido de ') . $label . ' · ' . $project['title'] . '.');
        return $id;
    }

    /** Extra cost approved (by the client on the link, or recorded by the team): unlock it and bill it. */
    public static function approveCost(array $r, array $project, string $name, ?int $userId): void
    {
        $inst = null;
        if ((float) $r['extra_cost'] > 0) {
            $n = 1 + (int) Db::q('SELECT COALESCE(MAX(number),0) FROM installments WHERE project_id = ?', [$project['id']])->fetchColumn();
            Db::q('INSERT INTO installments (project_id, client_id, number, description, amount, due_date) VALUES (?,?,?,?,?, CURDATE() + INTERVAL 7 DAY)',
                [$project['id'], $project['client_id'], $n, mb_substr(($r['type'] === 'escopo' ? 'Mudança de escopo' : 'Revisão extra') . ' #' . $r['id'], 0, 120), $r['extra_cost']]);
            $inst = (int) Db::pdo()->lastInsertId();
        }
        Db::q("UPDATE revision_requests SET status = 'aberta', cost_approved_at = NOW(), installment_id = ? WHERE id = ?", [$inst, $r['id']]);
        Db::q('INSERT INTO approvals (entity, entity_id, version, decision, name, user_id, ip, user_agent) VALUES (?,?,?,?,?,?,?,?)',
            ['revision', $r['id'], 1, 'custo_aprovado', $name, $userId, client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        Clients::event((int) $project['client_id'], 'sistema', 'Custo extra aprovado por ' . $name . ': ' . money((float) $r['extra_cost']) . ' · ' . $project['title'] . '.');
    }

    /** Deliverable approved: stamped with who, when and which file version. */
    public static function approveDeliverable(array $d, array $project, string $name, ?int $userId): void
    {
        $version = (int) Db::q("SELECT COALESCE(MAX(version),1) FROM files WHERE owner_type = 'deliverable' AND owner_id = ?", [$d['id']])->fetchColumn();
        Db::q("UPDATE deliverables SET status = 'aprovado', approved_at = NOW() WHERE id = ?", [$d['id']]);
        Db::q('INSERT INTO approvals (entity, entity_id, version, decision, name, user_id, ip, user_agent) VALUES (?,?,?,?,?,?,?,?)',
            ['deliverable', $d['id'], $version, 'aprovado', $name, $userId, client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        Clients::event((int) $project['client_id'], 'sistema', 'Entregável "' . $d['title'] . '" aprovado por ' . $name . ' (versão ' . $version . ').');
    }
}
