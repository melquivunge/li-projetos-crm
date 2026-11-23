<?php
declare(strict_types=1);
namespace App;

/**
 * Sales opportunities. A client may have many; the funnel lives here.
 * clients.stage mirrors the client's most relevant opportunity (syncClient) so lists and filters stay simple.
 */
final class Opportunities
{
    public const STAGES = ['novo' => 'Novo', 'qualificado' => 'Qualificado', 'briefing' => 'Briefing', 'proposta' => 'Proposta enviada', 'negociacao' => 'Negociação', 'ganho' => 'Ganho', 'perdido' => 'Perdido'];
    public const OPEN = ['novo', 'qualificado', 'briefing', 'proposta', 'negociacao'];

    /** Pure: the stage a client shows = its most advanced open opportunity, else the latest closed one. */
    public static function clientStage(array $opps): string
    {
        $order = array_flip(array_keys(self::STAGES));
        $open = array_filter($opps, fn($o) => in_array($o['stage'], self::OPEN, true));
        if ($open) { usort($open, fn($a, $b) => $order[$b['stage']] <=> $order[$a['stage']]); return $open[0]['stage']; }
        if (!$opps) return 'novo';
        usort($opps, fn($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
        return $opps[0]['stage'];
    }

    public static function syncClient(int $clientId): void
    {
        $opps = Db::q('SELECT id, stage, lost_reason, updated_at FROM opportunities WHERE client_id = ?', [$clientId])->fetchAll();
        $stage = self::clientStage($opps);
        $lost = $stage === 'perdido' ? (array_values(array_filter($opps, fn($o) => $o['stage'] === 'perdido'))[0]['lost_reason'] ?? null) : null;
        Db::q("UPDATE clients SET stage_changed_at = IF(stage <> ?, NOW(), stage_changed_at), stage = ?, lost_reason = ?,
               first_contact_at = COALESCE(first_contact_at, IF(? <> 'novo', NOW(), NULL)) WHERE id = ?", [$stage, $stage, $lost, $stage, $clientId]);
    }

    /** Loads an opportunity with its client, enforcing the same visibility rule as clients. */
    public static function findOrFail(int $id): array
    {
        $o = Db::q('SELECT o.*, c.name AS client_name, c.owner_id AS client_owner FROM opportunities o JOIN clients c ON c.id = o.client_id WHERE o.id = ?', [$id])->fetch();
        if (!$o) abort(404);
        if (!Auth::canSeeClient(['owner_id' => $o['client_owner']])) abort(403);
        return $o;
    }

    public static function create(int $clientId, string $title, ?string $service, ?int $ownerId, string $stage = 'novo', ?float $value = null): int
    {
        Db::q('INSERT INTO opportunities (client_id, title, service, owner_id, stage, estimated_value) VALUES (?,?,?,?,?,?)', [$clientId, mb_substr($title, 0, 160), $service, $ownerId, $stage, $value]);
        $id = (int) Db::pdo()->lastInsertId();
        self::syncClient($clientId);
        return $id;
    }

    /** Moves an opportunity; returns false when nothing changed. Caller validates the lost reason. */
    public static function move(array $o, string $stage, ?string $reason = null): bool
    {
        if (!isset(self::STAGES[$stage]) || $stage === $o['stage']) return false;
        Db::q('UPDATE opportunities SET stage = ?, lost_reason = ?, stage_changed_at = NOW() WHERE id = ?', [$stage, $stage === 'perdido' ? $reason : null, $o['id']]);
        Clients::event((int) $o['client_id'], 'etapa', $o['title'] . ': ' . self::STAGES[$o['stage']] . ' → ' . self::STAGES[$stage] . ($stage === 'perdido' && $reason ? " (motivo: $reason)" : ''));
        self::syncClient((int) $o['client_id']);
        return true;
    }

    /** The open opportunity a new proposal belongs to (most recent), or a fresh one. */
    public static function forProposal(array $c): array
    {
        $o = Db::q("SELECT * FROM opportunities WHERE client_id = ? AND stage IN ('novo','qualificado','briefing','proposta','negociacao') ORDER BY id DESC LIMIT 1", [$c['id']])->fetch();
        if ($o) return $o;
        $id = self::create((int) $c['id'], $c['service'] ?: 'Projeto', $c['service'], $c['owner_id'] ? (int) $c['owner_id'] : null);
        return Db::q('SELECT * FROM opportunities WHERE id = ?', [$id])->fetch();
    }
}
