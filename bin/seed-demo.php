<?php
// Uso: SEED_PASSWORD=... php bin/seed-demo.php
// Dados fictícios para o STAGING. Recusa rodar em produção (APP_ENV precisa ser staging ou local) e em banco com clientes.
// Telefones são falsos (+5500…) para nenhum "Cobrar no WhatsApp" abrir conversa com uma pessoa real; e-mails são @example.com.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\{Db, Opportunities, Projects, Revisions};

if (!in_array(env('APP_ENV'), ['staging', 'local'], true)) { fwrite(STDERR, "Recusado: APP_ENV=" . env('APP_ENV') . ". Só roda em staging ou local.\n"); exit(1); }
$n = (int) Db::q('SELECT COUNT(*) FROM clients')->fetchColumn();
if ($n > 0 && $n < 24 && in_array('--repair', $argv, true) && env('APP_ENV') === 'staging') {
    // a previous run stopped half-way: wipe the demo data and start again (staging only)
    Db::q('DELETE FROM clients');
    Db::q("DELETE FROM users WHERE email LIKE '%@demo.example.com'");
    echo "Dados incompletos ($n clientes) apagados.\n"; $n = 0;
}
if ($n > 0) { echo "Banco já tem $n clientes. Nada feito.\n"; exit(0); }
$pass = (string) getenv('SEED_PASSWORD');
if (strlen($pass) < 10) { fwrite(STDERR, "Defina SEED_PASSWORD (mín. 10).\n"); exit(1); }

set_exception_handler(function (Throwable $e) { echo 'ERRO: ', $e->getMessage(), ' (linha ', $e->getLine(), ' de ', basename($e->getFile()), ")\n"; exit(1); });
mt_srand(42);
$day = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . "$n days"));
$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];

// ---- Equipe ----
$team = [];
foreach ([['Ana Sócia (demo)', 'socia@demo.example.com', 'admin', 'Sócia · arquiteta'], ['Bruno Comercial (demo)', 'comercial@demo.example.com', 'comercial', 'Atendimento'],
          ['Carla Gestora (demo)', 'gestora@demo.example.com', 'gestor', 'Gestora de projetos'], ['Diego Designer (demo)', 'designer@demo.example.com', 'designer', 'Designer de interiores'],
          ['Eva Financeiro (demo)', 'financeiro@demo.example.com', 'financeiro', 'Financeiro']] as [$n, $m, $r, $job]) {
    Db::q('INSERT INTO users (name, email, password_hash, role, job_title) VALUES (?,?,?,?,?)', [$n, $m, password_hash($pass, PASSWORD_DEFAULT), $r === 'admin' ? 'admin' : 'equipe', $job]);
    $id = (int) Db::pdo()->lastInsertId();
    Db::q('INSERT INTO user_roles (user_id, role) VALUES (?,?)', [$id, $r]);
    $team[$r] = $id;
}

// ---- Clientes e oportunidades ----
$names = ['Mariana Albuquerque', 'Rafael Monteiro', 'Juliana Prado', 'Thiago Barreto', 'Fernanda Lacerda', 'Gustavo Teixeira', 'Patrícia Moura', 'Leonardo Siqueira',
          'Camila Fontes', 'Rodrigo Nogueira', 'Beatriz Carvalho', 'Henrique Pacheco', 'Larissa Toledo', 'Marcelo Rezende', 'Aline Figueiredo', 'Diego Cardoso',
          'Renata Queiroz', 'Felipe Andrade', 'Vanessa Rocha', 'Eduardo Pires', 'Isabela Correia', 'Caio Vasconcelos', 'Natália Brandão', 'Otávio Ramos'];
$services = ['Design de Interiores', 'Projetos de Arquitetura', 'Plantas Baixas', 'Consultoria', 'Detalhamento Executivo'];
$cities = ['Águas Claras, DF', 'Asa Norte, DF', 'Lago Sul, DF', 'Sudoeste, DF', 'Jardim Botânico, DF', 'Setor Bueno, Goiânia', 'Setor Marista, Goiânia'];
$origins = ['Google Ads', 'Instagram', 'Indicação', 'Site', 'WhatsApp'];
$stages = array_merge(array_fill(0, 5, 'novo'), array_fill(0, 4, 'qualificado'), array_fill(0, 3, 'briefing'), array_fill(0, 3, 'proposta'), array_fill(0, 2, 'negociacao'), array_fill(0, 5, 'ganho'), array_fill(0, 2, 'perdido'));
$clients = [];
foreach ($names as $i => $name) {
    $service = $services[$i % 3 === 0 ? 1 : ($i % 7 === 0 ? 2 : 0)];
    $area = mt_rand(45, 260);
    $owner = $i % 2 ? $team['comercial'] : $team['admin'];
    $created = $day(-mt_rand(3, 75));
    $email = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $name)[0])) . '.' . ($i + 1) . '@example.com';
    Db::q('INSERT INTO clients (name, whatsapp, email, city, service, area_m2, origin, owner_id, created_at, consent_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$name, '+5500000000' . str_pad((string) ($i + 100), 3, '0', STR_PAD_LEFT), $email, $pick($cities), $service, $area, $pick($origins), $owner, $created . ' 10:00:00', $created . ' 10:00:00']);
    $cid = (int) Db::pdo()->lastInsertId();
    $stage = $stages[$i];
    $value = round($area * 100 * (mt_rand(90, 130) / 100), -1);
    $oid = Opportunities::create($cid, $service . ' · ' . $area . ' m²', $service, $owner, $stage, $value);
    if ($stage === 'perdido') Db::q('UPDATE opportunities SET lost_reason = ? WHERE id = ?', [$pick(['Preço acima do esperado', 'Adiou a obra para o ano que vem', 'Fechou com um escritório local']), $oid]);
    Db::q("INSERT INTO client_events (client_id, user_id, type, body, created_at) VALUES (?,?, 'nota', ?, ?)", [$cid, $owner, $pick(['Primeiro contato pelo WhatsApp, quer começar em até 2 meses.', 'Mandou fotos do apartamento. Gosta de tons claros e madeira.', 'Pediu orçamento para sala e cozinha integradas.', 'Reunião por vídeo marcada; quer entender as etapas.']), $created . ' 11:00:00']);
    if (in_array($stage, ['novo', 'qualificado'], true)) Db::q('INSERT INTO tasks (title, due_at, priority, owner_id, client_id, opportunity_id, created_by) VALUES (?,?,?,?,?,?,?)',
        [$pick(['Ligar para entender o projeto', 'Mandar portfólio no WhatsApp', 'Agendar reunião de briefing']), $day(mt_rand(-2, 4)), $pick(['normal', 'alta']), $owner, $cid, $oid, $owner]);
    $clients[] = compact('cid', 'oid', 'stage', 'service', 'area', 'owner', 'name', 'value');
}

// ---- Briefings (a partir da etapa briefing) ----
foreach ($clients as $c) {
    if (in_array($c['stage'], ['novo', 'qualificado', 'perdido'], true)) continue;
    $q = (string) Db::q('SELECT questions FROM briefing_templates WHERE service = ?', [$c['service']])->fetchColumn();
    if ($q === '') continue;
    $status = $c['stage'] === 'briefing' ? $pick(['enviado', 'respondido']) : 'validado';
    Db::q('INSERT INTO briefings (client_id, opportunity_id, service, questions, status, public_token, created_by, sent_at, answered_at, validated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$c['cid'], $c['oid'], $c['service'], $q, $status, bin2hex(random_bytes(32)), $c['owner'], $day(-20) . ' 09:00:00', $status !== 'enviado' ? $day(-18) . ' 20:00:00' : null, $status === 'validado' ? $day(-18) . ' 20:05:00' : null]);
}

// ---- Propostas; as aceitas viram projetos (fases, parcelas) ----
$terms = ['50% na assinatura + 50% na entrega', 'Em 3x: entrada, meio do projeto e entrega', '30% / 40% / 30%', 'À vista no Pix com 5% de desconto'];
$policy = 'Inclui 2 rodadas de revisão em cada etapa. Alterações além disso, ou fora do escopo desta proposta, são orçadas à parte e só começam depois da sua aprovação.';
$projects = [];
foreach ($clients as $i => $c) {
    if (!in_array($c['stage'], ['proposta', 'negociacao', 'ganho'], true)) continue;
    $status = ['proposta' => $pick(['enviada', 'vista']), 'negociacao' => 'vista', 'ganho' => 'aceita'][$c['stage']];
    $total = max(3500.0, (float) $c['area'] * 100);
    Db::q('INSERT INTO proposals (client_id, opportunity_id, user_id, title, status, total, valid_until, project_summary, scope, deliverables, exclusions, payment_terms, revision_policy, public_token, sent_at, viewed_at, decided_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$c['cid'], $c['oid'], $c['owner'], $c['service'] . ' · ' . $c['area'] . ' m²', $status, $total, $day(mt_rand(3, 15)),
        'Projeto de ' . mb_strtolower($c['service']) . ' para ' . $c['area'] . ' m².', 'Layout, materiais, iluminação e marcenaria sob medida.', "Layout e especificação de materiais\nProjeto de marcenaria\nRenders realistas",
        'Execução ou acompanhamento de obra', $terms[$i % count($terms)], $policy, bin2hex(random_bytes(32)), $day(-25) . ' 10:00:00', $status !== 'enviada' ? $day(-24) . ' 19:00:00' : null, $status === 'aceita' ? $day(-22) . ' 21:00:00' : null]);
    $pid = (int) Db::pdo()->lastInsertId();
    Db::q('INSERT INTO proposal_items (proposal_id, code, label, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)', [$pid, 'interiores', $c['service'] . ' (por m²)', $c['area'], 100, $total]);
    foreach ([['Conceito', '15 dias'], ['Anteprojeto', '3 semanas'], ['Detalhamento', '20 dias'], ['Entrega', '10 dias']] as $k => [$st, $du])
        Db::q('INSERT INTO proposal_schedule (proposal_id, stage, duration_text, sort) VALUES (?,?,?,?)', [$pid, $st, $du, $k]);
    if ($status === 'aceita') {
        $prop = Db::q('SELECT * FROM proposals WHERE id = ?', [$pid])->fetch();
        $projects[] = ['id' => Projects::fromProposal($prop, $c['oid']), 'c' => $c];
    }
}

// ---- Andamento dos projetos: começaram há algum tempo ----
foreach ($projects as $k => $pr) {
    $id = $pr['id']; $ago = [55, 40, 28, 18, 8][$k % 5];
    Db::q('UPDATE projects SET start_date = start_date - INTERVAL ? DAY, due_date = due_date - INTERVAL ? DAY, manager_id = ? WHERE id = ?', [$ago, $ago, $team['gestor'], $id]);
    Db::q('UPDATE project_phases SET due_date = due_date - INTERVAL ? DAY WHERE project_id = ?', [$ago, $id]);
    Db::q('UPDATE installments SET due_date = due_date - INTERVAL ? DAY WHERE project_id = ?', [$ago, $id]);
    // fases vencidas foram concluídas, menos no 2º projeto (uma fase atrasada de propósito)
    Db::q("UPDATE project_phases SET status = 'concluida', done_at = due_date WHERE project_id = ? AND due_date < CURDATE() - INTERVAL ? DAY", [$id, $k === 1 ? 6 : 0]);
    Projects::sync($id);
    // parcelas vencidas foram pagas, menos duas (para o Financeiro ter o que cobrar)
    foreach (Db::q('SELECT id FROM installments WHERE project_id = ? AND due_date < CURDATE() ORDER BY number', [$id])->fetchAll() as $n => $inst)
        if (!($k === 0 && $n === 1) && !($k === 2 && $n === 0)) Db::q("UPDATE installments SET status = 'paga', paid_at = due_date + INTERVAL 1 DAY, paid_amount = amount, method = ? WHERE id = ?", [$pick(['pix', 'pix', 'cartao', 'transferencia']), $inst['id']]);
    $phase = Db::q("SELECT id FROM project_phases WHERE project_id = ? AND status = 'andamento'", [$id])->fetchColumn() ?: null;
    // tarefas (a última espera a terceira)
    $t3 = null;
    foreach ([['Medir ambientes no local', -3, true], ['Montar moodboard', -1, false], ['Layout da sala e cozinha', 2, false], ['Renders da proposta de conceito', 6, false]] as $j => [$title, $due, $done]) {
        Db::q('INSERT INTO tasks (title, due_at, priority, owner_id, project_id, phase_id, blocked_by, created_by, done_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$title, $day($due), $j === 1 ? 'alta' : 'normal', $team['designer'], $id, $phase, $j === 3 ? $t3 : null, $team['gestor'], $done ? $day($due) . ' 18:00:00' : null]);
        if ($j === 2) $t3 = (int) Db::pdo()->lastInsertId();
    }
    // horas e despesas
    foreach (range(1, mt_rand(3, 6)) as $h) Db::q('INSERT INTO time_entries (user_id, project_id, minutes, date, note) VALUES (?,?,?,?,?)', [$team['designer'], $id, $pick([60, 90, 120, 150, 240]), $day(-mt_rand(1, $ago)), $pick(['Estudo de layout', 'Modelagem 3D', 'Reunião com cliente', 'Detalhamento de marcenaria'])]);
    if ($k % 2 === 0) Db::q("INSERT INTO expenses (project_id, amount, category, description, date, created_by) VALUES (?,?, 'impressao', 'Plotagem das pranchas', ?, ?)", [$id, 180, $day(-5), $team['financeiro']]);
    // entregável com um arquivo de exemplo; os 3 primeiros já foram enviados ao cliente
    Db::q('INSERT INTO deliverables (project_id, phase_id, title, status) VALUES (?,?,?,?)', [$id, $phase, 'Conceito e moodboard', 'producao']);
    $did = (int) Db::pdo()->lastInsertId();
    $src = dirname(__DIR__) . '/public/assets/login-render.webp';
    if (is_file($src)) {
        $rel = date('Y/m/') . bin2hex(random_bytes(16)) . '.webp';
        if (!is_dir(dirname(storage_path('files/') . $rel))) mkdir(dirname(storage_path('files/') . $rel), 0750, true);
        copy($src, storage_path('files/') . $rel);
        Db::q("INSERT INTO files (owner_type, owner_id, client_id, name, mime, size, path, version, uploaded_by) VALUES ('deliverable', ?, ?, 'conceito-sala.webp', 'image/webp', ?, ?, 1, ?)", [$did, $pr['c']['cid'], filesize($src), $rel, $team['designer']]);
        if ($k < 3) Db::q("UPDATE deliverables SET status = 'enviado', public_token = ?, sent_at = NOW() - INTERVAL 2 DAY WHERE id = ?", [bin2hex(random_bytes(32)), $did]);
    }
    // revisões: no 1º projeto, as 2 rodadas usadas e uma 3ª esperando aprovação do custo
    if ($k === 0) {
        $p = Db::q('SELECT * FROM projects WHERE id = ?', [$id])->fetch();
        $ph = $phase ? (int) $phase : null;
        Revisions::create($p, $ph, $did, 'revisao', 'Trocar o porcelanato da sala por um tom mais quente.', true, $pr['c']['name'], null);
        Revisions::create($p, $ph, $did, 'revisao', 'Aumentar o painel da TV até o teto.', true, $pr['c']['name'], null);
        $r3 = Revisions::create($p, $ph, $did, 'revisao', 'Refazer a cozinha em U em vez de L.', true, $pr['c']['name'], null);
        Db::q("UPDATE revision_requests SET status = 'concluida', done_at = NOW() - INTERVAL 3 DAY WHERE project_id = ? AND round = 1", [$id]);
        Db::q('UPDATE revision_requests SET extra_cost = 450 WHERE id = ?', [$r3]);
        Db::q("UPDATE deliverables SET status = 'ajustes' WHERE id = ?", [$did]);
    }
}
foreach (['pix_key' => 'pix@demo.example.com', 'pix_name' => 'LI Projetos (demo)', 'hourly_cost' => '60'] as $k => $v)
    Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);

echo 'Pronto: ' . count($team) . ' usuários, ' . count($clients) . ' clientes, ' . count($projects) . " projetos.\n";
echo "Logins (senha = SEED_PASSWORD): socia@, comercial@, gestora@, designer@, financeiro@demo.example.com\n";
