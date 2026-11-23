<?php
// Uso: php bin/cron.php   (rodar de hora em hora; na Hostinger: Avançado → Cron Jobs → "0 * * * *").
// Lembretes no sino que não se repetem (Notify::once) e limpeza de tabelas temporárias. Seguro rodar várias vezes.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use App\{Db, Notify};

$lock = (is_dir(storage_path()) || mkdir(storage_path(), 0750, true)) ? fopen(storage_path('cron.lock'), 'c') : false;
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Outra execução em andamento.\n"; exit(0); }

$sent = ['tarefas' => 0, 'fases' => 0, 'propostas' => 0];
$dm = fn(string $d) => date('d/m', strtotime($d));
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);

// 1. Resumo do dia: tarefas para hoje e atrasadas (a partir das 7h, uma vez por dia)
if ((int) date('G') >= 7) {
    foreach (Db::q("SELECT t.owner_id, SUM(t.due_at = CURDATE()) today, SUM(t.due_at < CURDATE()) late FROM tasks t JOIN users u ON u.id = t.owner_id
                    WHERE u.active = 1 AND t.done_at IS NULL AND t.due_at <= CURDATE() GROUP BY t.owner_id") as $r) {
        $parts = array_filter([(int) $r['today'] ? $plural((int) $r['today'], 'tarefa para hoje', 'tarefas para hoje') : null,
                               (int) $r['late'] ? $plural((int) $r['late'], 'atrasada', 'atrasadas') : null]);
        $sent['tarefas'] += (int) Notify::once((int) $r['owner_id'], 'Bom dia! ' . $dm(date('Y-m-d')) . ': ' . implode(' e ', $parts) . '.', '/tarefas', 2);
    }
}

// 2. Fases de projetos em andamento: vence amanhã ou já venceu → responsável do projeto (sem responsável ativo: as sócias)
$admins = Db::q("SELECT id FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
foreach (Db::q("SELECT ph.id, ph.name, ph.due_date, p.id pid, p.title, IF(u.active = 1, p.manager_id, NULL) manager_id FROM project_phases ph JOIN projects p ON p.id = ph.project_id
                LEFT JOIN users u ON u.id = p.manager_id WHERE p.status = 'ativo' AND ph.status <> 'concluida' AND ph.due_date <= CURDATE() + INTERVAL 1 DAY") as $r) {
    $body = $r['due_date'] < date('Y-m-d')
        ? 'Fase "' . $r['name'] . '" de ' . $r['title'] . ' está atrasada (prazo ' . $dm($r['due_date']) . ').'
        : 'Fase "' . $r['name'] . '" de ' . $r['title'] . ' vence ' . ($r['due_date'] === date('Y-m-d') ? 'hoje' : 'amanhã') . '.';
    foreach ($r['manager_id'] ? [$r['manager_id']] : $admins as $uid) $sent['fases'] += (int) Notify::once((int) $uid, $body, '/projetos/' . $r['pid'] . '#fases');
}

// 3. Propostas sem resposta: vence amanhã, ou venceu ontem → sócias + responsável pelo cliente
foreach (Db::q("SELECT p.id, p.valid_until, c.name, c.owner_id FROM proposals p JOIN clients c ON c.id = p.client_id
                WHERE p.status IN ('enviada','vista') AND p.valid_until BETWEEN CURDATE() - INTERVAL 1 DAY AND CURDATE() + INTERVAL 1 DAY") as $r) {
    $body = $r['valid_until'] < date('Y-m-d')
        ? 'A proposta #' . $r['id'] . ' de ' . $r['name'] . ' venceu sem resposta. Vale um contato.'
        : 'A proposta #' . $r['id'] . ' de ' . $r['name'] . ' vence ' . ($r['valid_until'] === date('Y-m-d') ? 'hoje' : 'amanhã') . ' sem resposta.';
    $ids = Db::q("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR id = ?)", [$r['owner_id'] ?? 0])->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_map('intval', $ids)) as $uid) $sent['propostas'] += (int) Notify::once($uid, $body, '/propostas/' . $r['id']);
}

// 3b. Parcelas: 3 dias antes, no dia e com 1, 7, 15 e 30 dias de atraso → financeiro + sócias (a cobrança ao cliente é um clique)
$fin = Db::q("SELECT DISTINCT u.id FROM users u LEFT JOIN user_roles r ON r.user_id = u.id WHERE u.active = 1 AND (u.role = 'admin' OR r.role IN ('admin','financeiro'))")->fetchAll(PDO::FETCH_COLUMN);
$sent['parcelas'] = 0;
foreach (Db::q("SELECT i.id, i.amount, i.due_date, c.name FROM installments i JOIN clients c ON c.id = i.client_id JOIN projects p ON p.id = i.project_id
                WHERE i.status = 'aberta' AND p.status <> 'cancelado' AND i.due_date BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE() + INTERVAL 3 DAY") as $r) {
    $m = App\Finance::milestone($r['due_date'], date('Y-m-d'));
    if ($m === null) continue;
    $v = money((float) $r['amount']);
    $body = $m < 0 ? "Parcela de {$r['name']} ($v) vence em " . -$m . ' dias. Quer mandar um lembrete?'
          : ($m === 0 ? "Parcela de {$r['name']} ($v) vence hoje." : "{$r['name']} está com $v em atraso há $m dia" . ($m > 1 ? 's' : '') . '. Cobrar?');
    foreach ($fin as $uid) $sent['parcelas'] += (int) Notify::once((int) $uid, $body, '/financeiro?ver=' . ($m > 0 ? 'vencidas' : 'abertas'), 40);
}

// 3c. Anti-calote: pausa o projeto com parcela 7+ dias atrasada; retoma quando regulariza (o pagamento também retoma na hora)
$sent['pausados'] = 0;
foreach (Db::q("SELECT DISTINCT p.id, p.title, c.name FROM projects p JOIN clients c ON c.id = p.client_id JOIN installments i ON i.project_id = p.id
                WHERE p.status = 'ativo' OR (p.status = 'pausado' AND p.hold_reason = 'pagamento')") as $r) {
    if (App\Finance::syncHold((int) $r['id']) !== 'paused') continue;
    $sent['pausados']++;
    foreach ($fin as $uid) Notify::once((int) $uid, "Projeto {$r['title']} ({$r['name']}) pausado: parcela com " . App\Finance::HOLD_DAYS . '+ dias de atraso. Novas entregas ficam bloqueadas até o pagamento.', '/projetos/' . $r['id'], 7);
}

// 4. Limpeza
Db::q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
Db::q('DELETE FROM api_rate WHERE window_start < NOW() - INTERVAL 1 DAY');
Db::q('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < NOW() - INTERVAL 180 DAY');

echo date('Y-m-d H:i') . ' avisos: ' . json_encode($sent) . "\n";
