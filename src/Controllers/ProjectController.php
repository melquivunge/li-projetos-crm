<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Clients, Db, Files, Notify, Projects};

final class ProjectController
{
    public const VIEWS = ['lista' => 'Lista', 'kanban' => 'Kanban', 'calendario' => 'Calendário'];

    /** Pure: "1,5" / "1.5" / "1:30" / "2h" → minutes (0 when unreadable). */
    public static function minutes(string $s): int
    {
        $s = trim(str_replace(',', '.', mb_strtolower($s)));
        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $s, $m)) return (int) $m[1] * 60 + (int) $m[2];
        if (preg_match('/^(\d{1,2}(?:\.\d+)?)\s*h?$/', $s, $m)) return (int) round((float) $m[1] * 60);
        return 0;
    }

    private static function back(int $id, string $anchor = ''): never { redirect("/projetos/$id" . ($anchor ? "#$anchor" : '')); }

    public static function index(): void
    {
        Auth::requirePerm('projects');
        $view = isset(self::VIEWS[$_GET['ver'] ?? '']) ? $_GET['ver'] : 'lista';
        $done = ($_GET['status'] ?? '') === 'encerrados';
        [$w, $params] = Projects::scope();
        $st = $done ? "p.status IN ('concluido','cancelado')" : "p.status IN ('ativo','pausado')";
        $rows = Db::q("SELECT p.*, c.name AS client_name, u.name AS manager_name, u.avatar AS manager_avatar,
                (SELECT ph.name FROM project_phases ph WHERE ph.project_id = p.id AND ph.status <> 'concluida' ORDER BY ph.sort, ph.id LIMIT 1) AS phase_name,
                (SELECT ph.due_date FROM project_phases ph WHERE ph.project_id = p.id AND ph.status <> 'concluida' ORDER BY ph.sort, ph.id LIMIT 1) AS phase_due,
                (SELECT COUNT(*) FROM project_phases ph WHERE ph.project_id = p.id) AS n_phases,
                (SELECT COUNT(*) FROM project_phases ph WHERE ph.project_id = p.id AND ph.status = 'concluida') AS n_done,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.done_at IS NULL AND t.due_at < CURDATE()) AS late_tasks
            FROM projects p JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = p.manager_id
            WHERE $w AND $st ORDER BY p.due_date IS NULL, p.due_date, p.id DESC LIMIT 300", $params)->fetchAll();
        $counts = Db::q("SELECT COALESCE(SUM(p.status IN ('ativo','pausado')),0) abertos, COALESCE(SUM(p.status IN ('concluido','cancelado')),0) encerrados,
                COALESCE(SUM(p.status = 'ativo' AND p.due_date < CURDATE()),0) atrasados FROM projects p WHERE $w", $params)->fetch();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
        $events = []; $grid = [];
        if ($view === 'calendario') {
            $grid = Projects::monthGrid($month);
            $from = $grid[0][0]; $to = end($grid)[6];
            foreach (Db::q("SELECT ph.due_date d, ph.name, ph.status, p.id pid, c.name client FROM project_phases ph JOIN projects p ON p.id = ph.project_id JOIN clients c ON c.id = p.client_id
                    WHERE $w AND p.status IN ('ativo','pausado') AND ph.due_date BETWEEN ? AND ?", [...$params, $from, $to]) as $e)
                $events[$e['d']][] = ['kind' => 'phase', 'label' => $e['name'] . ' · ' . $e['client'], 'href' => '/projetos/' . $e['pid'] . '#fases', 'done' => $e['status'] === 'concluida'];
            foreach (Db::q("SELECT t.due_at d, t.title, t.done_at, p.id pid FROM tasks t JOIN projects p ON p.id = t.project_id
                    WHERE $w AND p.status IN ('ativo','pausado') AND t.due_at BETWEEN ? AND ?", [...$params, $from, $to]) as $e)
                $events[$e['d']][] = ['kind' => 'task', 'label' => $e['title'], 'href' => '/projetos/' . $e['pid'] . '#tarefas', 'done' => (bool) $e['done_at']];
        }
        view('projects', compact('rows', 'view', 'done', 'counts', 'month', 'events', 'grid'));
    }

    public static function show(string $id): void
    {
        Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $phases = Projects::phases((int) $p['id']);
        $tasks = Db::q('SELECT t.*, u.name AS owner_name, u.avatar AS owner_avatar, b.title AS blocker_title, b.done_at AS blocker_done FROM tasks t
            LEFT JOIN users u ON u.id = t.owner_id LEFT JOIN tasks b ON b.id = t.blocked_by WHERE t.project_id = ? ORDER BY t.done_at IS NOT NULL, t.due_at, t.id', [$p['id']])->fetchAll();
        $deliverables = Db::q('SELECT * FROM deliverables WHERE project_id = ? ORDER BY id', [$p['id']])->fetchAll();
        $files = [];
        $ids = implode(',', array_map('intval', array_column($deliverables, 'id'))) ?: '0';
        if ($deliverables) {
            foreach (Db::q("SELECT * FROM files WHERE owner_type = 'deliverable' AND owner_id IN ($ids) ORDER BY id DESC") as $f) $files[$f['owner_id']][] = $f;
        }
        $hours = Db::q('SELECT te.*, u.name AS user_name, t.title AS task_title FROM time_entries te LEFT JOIN users u ON u.id = te.user_id LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.project_id = ? ORDER BY te.date DESC, te.id DESC LIMIT 15', [$p['id']])->fetchAll();
        $totalMin = (int) Db::q('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE project_id = ?', [$p['id']])->fetchColumn();
        $users = Db::q('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $revisions = Db::q('SELECT r.*, d.title AS deliverable_title FROM revision_requests r LEFT JOIN deliverables d ON d.id = r.deliverable_id WHERE r.project_id = ? ORDER BY FIELD(r.status, "aguardando_aprovacao", "andamento", "aberta", "concluida", "recusada"), r.id DESC', [$p['id']])->fetchAll();
        $approvals = [];
        if ($deliverables) foreach (Db::q("SELECT * FROM approvals WHERE entity = 'deliverable' AND entity_id IN ($ids) ORDER BY id") as $a) $approvals[$a['entity_id']] = $a;
        $fin = FinanceController::forProject($p, $totalMin);
        view('project_show', compact('p', 'phases', 'tasks', 'deliverables', 'files', 'hours', 'totalMin', 'users', 'fin', 'revisions', 'approvals'));
    }

    public static function update(string $id): void
    {
        Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160) ?: $p['title'];
        $status = isset(Projects::STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : $p['status'];
        $manager = (int) ($_POST['manager_id'] ?? 0);
        if (!$manager || !Db::q('SELECT 1 FROM users WHERE id = ? AND active = 1', [$manager])->fetch()) $manager = $p['manager_id'];
        Db::q("UPDATE projects SET title = ?, status = ?, manager_id = ?, done_at = IF(? = 'concluido', COALESCE(done_at, NOW()), NULL) WHERE id = ?", [$title, $status, $manager, $status, $p['id']]);
        if ($status !== $p['status']) Clients::event((int) $p['client_id'], 'sistema', 'Projeto #' . $p['id'] . ': ' . Projects::STATUS[$status] . '.');
        if ($manager && (int) $manager !== (int) $p['manager_id'] && (int) $manager !== Auth::id()) Notify::user((int) $manager, 'Você agora cuida do projeto ' . $title, '/projetos/' . $p['id']);
        Audit::log('project.update', 'project', (int) $p['id']);
        flash('Projeto salvo.');
        self::back((int) $p['id']);
    }

    private static function date(mixed $s): ?string
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : null;
    }

    private static function phaseOf(array $p): ?int
    {
        $ph = (int) ($_POST['phase_id'] ?? 0);
        return $ph && Db::q('SELECT 1 FROM project_phases WHERE id = ? AND project_id = ?', [$ph, $p['id']])->fetch() ? $ph : null;
    }

    public static function addPhase(string $id): void
    {
        Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name === '') { flash('Dê um nome à fase.', 'danger'); self::back((int) $p['id'], 'fases'); }
        $sort = 1 + (int) Db::q('SELECT COALESCE(MAX(sort),0) FROM project_phases WHERE project_id = ?', [$p['id']])->fetchColumn();
        Db::q('INSERT INTO project_phases (project_id, name, sort, due_date) VALUES (?,?,?,?)', [$p['id'], $name, $sort, self::date($_POST['due_date'] ?? null)]);
        Projects::sync((int) $p['id']);
        self::back((int) $p['id'], 'fases');
    }

    /** One route for a phase: save (name/date), done, reopen, delete. */
    public static function phase(string $id): void
    {
        Auth::requirePerm('projects');
        $ph = Db::q('SELECT * FROM project_phases WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        $p = Projects::findOrFail((int) $ph['project_id']);
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'save' => Db::q('UPDATE project_phases SET name = ?, due_date = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: $ph['name'], self::date($_POST['due_date'] ?? null), $ph['id']]),
            'done' => Db::q("UPDATE project_phases SET status = 'concluida', done_at = NOW() WHERE id = ?", [$ph['id']]),
            'reopen' => Db::q("UPDATE project_phases SET status = 'pendente', done_at = NULL WHERE id = ?", [$ph['id']]),
            'delete' => Db::q('DELETE FROM project_phases WHERE id = ?', [$ph['id']]),
            default => abort(400),
        };
        if ($action === 'done') Clients::event((int) $p['client_id'], 'sistema', 'Projeto #' . $p['id'] . ': fase ' . $ph['name'] . ' concluída.');
        Projects::sync((int) $p['id']);
        Audit::log('phase.' . $action, 'project', (int) $p['id']);
        self::back((int) $p['id'], 'fases');
    }

    /** Kanban drop (fetch → 204/422). Column "Todas concluídas" concludes every phase. */
    public static function move(string $id): void
    {
        Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $name = (string) ($_POST['stage'] ?? '');
        if (!Projects::moveTo((int) $p['id'], $name === self::ALL_DONE ? '' : $name)) {
            http_response_code(422); header('Content-Type: application/json');
            echo json_encode(['error' => 'Este projeto não tem a fase "' . $name . '". Ajuste as fases na página do projeto.']); exit;
        }
        Clients::event((int) $p['client_id'], 'sistema', 'Projeto #' . $p['id'] . ' movido para ' . ($name === self::ALL_DONE ? 'todas as fases concluídas' : 'a fase ' . $name) . '.');
        Audit::log('project.move', 'project', (int) $p['id']);
        http_response_code(204); exit;
    }

    public const ALL_DONE = 'Todas concluídas';

    public static function addTask(string $id): void
    {
        $u = Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $due = self::date($_POST['due_at'] ?? null);
        if ($title === '' || !$due) { flash('A tarefa precisa de um título e de uma data.', 'danger'); self::back((int) $p['id'], 'tarefas'); }
        $owner = (int) ($_POST['owner_id'] ?? 0);
        if (!$owner || !Db::q('SELECT 1 FROM users WHERE id = ? AND active = 1', [$owner])->fetch()) $owner = (int) $u['id'];
        $blocker = (int) ($_POST['blocked_by'] ?? 0);
        $blocker = $blocker && Db::q('SELECT 1 FROM tasks WHERE id = ? AND project_id = ?', [$blocker, $p['id']])->fetch() ? $blocker : null;
        $prio = isset(TaskController::PRIORITY[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
        Db::q('INSERT INTO tasks (title, due_at, priority, owner_id, project_id, phase_id, blocked_by, created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$title, $due, $prio, $owner, $p['id'], self::phaseOf($p), $blocker, $u['id']]);
        $tid = (int) Db::pdo()->lastInsertId();
        if ($owner !== (int) $u['id']) Notify::user($owner, $u['name'] . ' criou uma tarefa para você em ' . $p['title'] . ': ' . $title, '/projetos/' . $p['id'] . '#tarefas');
        Audit::log('task.create', 'task', $tid);
        self::back((int) $p['id'], 'tarefas');
    }

    public static function addDeliverable(string $id): void
    {
        Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
        if ($title === '') { flash('Dê um nome ao entregável.', 'danger'); self::back((int) $p['id'], 'entregas'); }
        Db::q('INSERT INTO deliverables (project_id, phase_id, title) VALUES (?,?,?)', [$p['id'], self::phaseOf($p), $title]);
        self::back((int) $p['id'], 'entregas');
    }

    /** Status change and/or new file versions for a deliverable. */
    public static function deliverable(string $id): void
    {
        $u = Auth::requirePerm('projects');
        $d = Db::q('SELECT * FROM deliverables WHERE id = ?', [(int) $id])->fetch() ?: abort(404);
        $p = Projects::findOrFail((int) $d['project_id']);
        $status = (string) ($_POST['status'] ?? '');
        if (isset(Projects::DELIVERABLE_STATUS[$status]) && $status !== $d['status']) {
            Db::q('UPDATE deliverables SET status = ? WHERE id = ?', [$status, $d['id']]);
            if (in_array($status, ['enviado', 'aprovado'], true)) Clients::event((int) $p['client_id'], 'sistema', 'Entregável "' . $d['title'] . '": ' . mb_strtolower(Projects::DELIVERABLE_STATUS[$status]) . '.');
        }
        [$saved, $errors] = Files::store(Files::list($_FILES['files'] ?? []), 'deliverable', (int) $d['id'], (int) $p['client_id'], (int) $u['id'], false);
        if ($errors) flash(implode(' ', $errors), 'danger');
        elseif ($saved) flash($saved === 1 ? 'Arquivo enviado.' : "$saved arquivos enviados.");
        self::back((int) $p['id'], 'entregas');
    }

    private static function deliverableFile(string $id): array
    {
        Auth::requirePerm('projects');
        $f = Db::q("SELECT f.*, d.project_id FROM files f JOIN deliverables d ON d.id = f.owner_id WHERE f.id = ? AND f.owner_type = 'deliverable'", [(int) $id])->fetch() ?: abort(404);
        Projects::findOrFail((int) $f['project_id']);
        return $f;
    }

    public static function file(string $id): void
    {
        Files::send(self::deliverableFile($id), !empty($_GET['baixar']));
    }

    public static function removeFile(string $id): void
    {
        $f = self::deliverableFile($id);
        Files::remove($f);
        Audit::log('file.delete', 'file', (int) $f['id']);
        self::back((int) $f['project_id'], 'entregas');
    }

    public static function addHours(string $id): void
    {
        $u = Auth::requirePerm('projects');
        $p = Projects::findOrFail((int) $id);
        $min = self::minutes((string) ($_POST['hours'] ?? ''));
        $date = self::date($_POST['date'] ?? null);
        if ($min < 1 || $min > 1440 || !$date || $date > date('Y-m-d')) { flash('Informe as horas (ex.: 1,5 ou 1:30) e uma data até hoje.', 'danger'); self::back((int) $p['id'], 'horas'); }
        $task = (int) ($_POST['task_id'] ?? 0);
        $task = $task && Db::q('SELECT 1 FROM tasks WHERE id = ? AND project_id = ?', [$task, $p['id']])->fetch() ? $task : null;
        Db::q('INSERT INTO time_entries (user_id, project_id, task_id, minutes, date, note) VALUES (?,?,?,?,?,?)',
            [$u['id'], $p['id'], $task, $min, $date, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200) ?: null]);
        self::back((int) $p['id'], 'horas');
    }
}
