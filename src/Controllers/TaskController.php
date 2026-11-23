<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Clients, Db, Notify};

final class TaskController
{
    public const VIEWS = ['atrasadas' => 'Atrasadas', 'hoje' => 'Hoje', 'proximas' => 'Próximas', 'feitas' => 'Feitas'];
    public const PRIORITY = ['alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa'];

    /** Pure: which tab a task belongs to on a given day. */
    public static function bucket(string $due, ?string $doneAt, string $today): string
    {
        if ($doneAt) return 'feitas';
        return $due < $today ? 'atrasadas' : ($due === $today ? 'hoje' : 'proximas');
    }

    /** Late + due-today counts for the sidebar badge. */
    public static function counts(int $userId): array
    {
        $r = Db::q('SELECT COALESCE(SUM(due_at < CURDATE()),0) late, COALESCE(SUM(due_at = CURDATE()),0) today FROM tasks WHERE owner_id = ? AND done_at IS NULL', [$userId])->fetch();
        return ['late' => (int) $r['late'], 'today' => (int) $r['today']];
    }

    public static function index(): void
    {
        $u = Auth::requirePerm('tasks');
        $view = (string) ($_GET['ver'] ?? '');
        $mine = !Auth::isAdmin() || ($_GET['de'] ?? 'mim') === 'mim';
        $where = $mine ? 't.owner_id = ?' : '1=1';
        $params = $mine ? [$u['id']] : [];
        $counts = Db::q("SELECT COALESCE(SUM(t.done_at IS NULL AND t.due_at < CURDATE()),0) atrasadas, COALESCE(SUM(t.done_at IS NULL AND t.due_at = CURDATE()),0) hoje,
                COALESCE(SUM(t.done_at IS NULL AND t.due_at > CURDATE()),0) proximas, COALESCE(SUM(t.done_at >= NOW() - INTERVAL 7 DAY),0) feitas FROM tasks t WHERE $where", $params)->fetch();
        if (!isset(self::VIEWS[$view])) $view = (int) $counts['atrasadas'] ? 'atrasadas' : 'hoje';
        $cond = ['atrasadas' => 't.done_at IS NULL AND t.due_at < CURDATE()', 'hoje' => 't.done_at IS NULL AND t.due_at = CURDATE()',
                 'proximas' => 't.done_at IS NULL AND t.due_at > CURDATE()', 'feitas' => 't.done_at >= NOW() - INTERVAL 7 DAY'][$view];
        $rows = Db::q("SELECT t.*, c.name AS client_name, pj.title AS project_title, u.name AS owner_name, u.avatar AS owner_avatar FROM tasks t
            LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN projects pj ON pj.id = t.project_id LEFT JOIN users u ON u.id = t.owner_id
            WHERE $where AND $cond ORDER BY " . ($view === 'feitas' ? 't.done_at DESC' : "t.due_at, FIELD(t.priority,'alta','normal','baixa')") . ' LIMIT 200', $params)->fetchAll();
        $owners = Db::q('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR);
        view('tasks', compact('rows', 'view', 'counts', 'mine', 'owners'));
    }

    public static function store(): void
    {
        $u = Auth::requirePerm('tasks');
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $due = (string) ($_POST['due_at'] ?? '');
        $prio = isset(self::PRIORITY[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
        $owner = Auth::isAdmin() && (int) ($_POST['owner_id'] ?? 0) ? (int) $_POST['owner_id'] : (int) $u['id'];
        $clientId = (int) ($_POST['client_id'] ?? 0) ?: null;
        $oppId = (int) ($_POST['opportunity_id'] ?? 0) ?: null;
        $back = $clientId ? "/clientes/$clientId" : '/tarefas';
        if ($clientId) Clients::findOrFail($clientId); // visibility check
        if ($oppId && !Db::q('SELECT 1 FROM opportunities WHERE id = ? AND client_id = ?', [$oppId, $clientId])->fetch()) $oppId = null;
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { flash('A tarefa precisa de um título e de uma data.', 'danger'); redirect($back); }
        if (!Db::q('SELECT 1 FROM users WHERE id = ? AND active = 1', [$owner])->fetch()) $owner = (int) $u['id'];
        Db::q('INSERT INTO tasks (title, due_at, priority, owner_id, client_id, opportunity_id, created_by) VALUES (?,?,?,?,?,?,?)', [$title, $due, $prio, $owner, $clientId, $oppId, $u['id']]);
        $tid = (int) Db::pdo()->lastInsertId();
        if ($clientId) Clients::event($clientId, 'sistema', 'Tarefa: ' . $title . ' até ' . date('d/m/Y', strtotime($due)) . '.');
        if ($owner !== (int) $u['id']) Notify::user($owner, $u['name'] . ' criou uma tarefa para você: ' . $title, $back);
        Audit::log('task.create', 'task', $tid);
        redirect($back);
    }

    public static function done(string $id): void
    {
        $u = Auth::requirePerm('tasks');
        $t = Db::q('SELECT * FROM tasks WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        if (!Auth::isAdmin() && (int) $t['owner_id'] !== (int) $u['id'] && !($t['project_id'] && \App\Projects::seeAll())) abort(403);
        $undo = !empty($_POST['undo']);
        $back = (string) ($_POST['back'] ?? '/tarefas');
        $back = safe_back($back, '/tarefas');
        if (!$undo && $t['blocked_by'] && ($b = Db::q('SELECT title FROM tasks WHERE id = ? AND done_at IS NULL', [$t['blocked_by']])->fetchColumn())) {
            flash('Esta tarefa espera "' . $b . '" ser concluída.', 'danger'); redirect($back);
        }
        Db::q('UPDATE tasks SET done_at = ' . ($undo ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$t['id']]);
        if ($t['client_id'] && !$undo) Clients::event((int) $t['client_id'], 'sistema', 'Tarefa concluída: ' . $t['title']);
        Audit::log($undo ? 'task.reopen' : 'task.done', 'task', (int) $t['id']);
        redirect($back);
    }
}
