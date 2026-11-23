<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Clients, Db, Opportunities};

final class OpportunityController
{
    private static function money(string $v): ?float
    {
        $v = trim($v);
        return $v === '' ? null : max(0, (float) str_replace(['.', ','], ['', '.'], $v));
    }

    public static function store(string $id): void
    {
        $u = Auth::requirePerm('crm');
        $c = Clients::findOrFail((int) $id);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
        $service = in_array($_POST['service'] ?? '', Clients::SERVICES, true) ? $_POST['service'] : null;
        if ($title === '') { flash('Dê um nome à oportunidade (ex.: Interiores do apartamento).', 'danger'); redirect('/clientes/' . $c['id']); }
        $oid = Opportunities::create((int) $c['id'], $title, $service, $c['owner_id'] ? (int) $c['owner_id'] : (int) $u['id'], 'novo', self::money((string) ($_POST['estimated_value'] ?? '')));
        Clients::event((int) $c['id'], 'sistema', "Nova oportunidade: $title.");
        Audit::log('opportunity.create', 'opportunity', $oid);
        redirect('/clientes/' . $c['id']);
    }

    /** Stage change from the board (XHR) or the client page (form). */
    public static function stage(string $id): void
    {
        Auth::requirePerm('crm');
        $o = Opportunities::findOrFail((int) $id);
        $xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        $stage = (string) ($_POST['stage'] ?? '');
        $reason = mb_substr(trim((string) ($_POST['lost_reason'] ?? '')), 0, 255);
        $fail = function (string $msg) use ($xhr, $o) {
            if ($xhr) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['error' => $msg]); exit; }
            flash($msg, 'danger'); redirect('/clientes/' . $o['client_id']);
        };
        if (!isset(Opportunities::STAGES[$stage])) $fail('Etapa inválida.');
        if ($stage === 'perdido' && $reason === '') $fail('Informe o motivo da perda.');
        if (Opportunities::move($o, $stage, $reason)) Audit::log('opportunity.stage', 'opportunity', (int) $o['id']);
        if ($xhr) { http_response_code(204); exit; }
        redirect('/clientes/' . $o['client_id']);
    }

    public static function update(string $id): void
    {
        Auth::requirePerm('crm');
        $o = Opportunities::findOrFail((int) $id);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160) ?: $o['title'];
        $close = (string) ($_POST['expected_close'] ?? '');
        Db::q('UPDATE opportunities SET title = ?, estimated_value = ?, expected_close = ? WHERE id = ?', [
            $title, self::money((string) ($_POST['estimated_value'] ?? '')), preg_match('/^\d{4}-\d{2}-\d{2}$/', $close) ? $close : null, $o['id']]);
        Audit::log('opportunity.update', 'opportunity', (int) $o['id']);
        redirect('/clientes/' . $o['client_id']);
    }
}
