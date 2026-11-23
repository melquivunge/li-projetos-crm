<?php
// php tests/run.php — pure-logic checks (no DB). Exit 1 on failure.
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Clients;
$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? '✔ ' : '✘ ') . $m . "\n"; if (!$c) $fail++; };

$ok(Clients::normalizeWhatsapp('(61) 99241-4304') === '+5561992414304', 'celular com DDD vira +55');
$ok(Clients::normalizeWhatsapp('+55 62 3241-0000') === '+556232410000', 'fixo com +55');
$ok(Clients::normalizeWhatsapp('99241-4304') === null, 'sem DDD é recusado');
$ok(Clients::normalizeWhatsapp('(01) 99241-4304') === null, 'DDD 0x é recusado');
$ok(Clients::normalizeWhatsapp('abc') === null, 'texto é recusado');

[$d, $e] = Clients::validate(['name' => 'Ana', 'whatsapp' => '61 99241 4304', 'area_m2' => '85,5', 'service' => 'Design de Interiores']);
$ok(!$e && $d['whatsapp'] === '+5561992414304' && $d['area_m2'] === 85.5, 'cliente válido normaliza telefone e área');
[, $e] = Clients::validate(['name' => 'A']);
$ok(isset($e['name'], $e['whatsapp']), 'exige nome e algum contato');
[, $e] = Clients::validate(['name' => 'Ana', 'email' => 'x@', 'area_m2' => '-3', 'service' => 'Obra']);
$ok(isset($e['email'], $e['area_m2'], $e['service']), 'recusa e-mail, área e serviço inválidos');
[$d] = Clients::validate(['name' => '<script>x</script>', 'email' => 'a@b.co']);
$ok(e($d['name']) === '&lt;script&gt;x&lt;/script&gt;', 'XSS no nome sai escapado');
$ok(Clients::csvCell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && Clients::csvCell('Ana') === 'Ana' && Clients::csvCell('+5561992414304') === '+5561992414304' && Clients::csvCell('+1+cmd') === "'+1+cmd", 'CSV neutraliza fórmulas');

use App\Controllers\ApiController;
$ok(ApiController::mapService('Projeto de Arquitetura') === 'Projetos de Arquitetura' && ApiController::mapService('Planta 3D do Apartamento (R$ 490)') === 'Plantas Baixas' && ApiController::mapService('Consultoria (R$ 250/hora)') === 'Consultoria' && ApiController::mapService('Obra') === null, 'serviços do site viram serviços do CRM');
[, $e] = Clients::validate(['name' => 'Ana'], requireContact: false);
$ok(!$e, 'pedido de reunião aceita sem contato');

use App\Pricing;
$it = fn($code, $unit, $price, $min = null, $max = null, $rank = 0) => ['code' => $code, 'label' => $code, 'unit' => $unit, 'price' => $price, 'min_total' => $min, 'max_area' => $max, 'credit_rank' => $rank];
$planta = $it('planta3d', 'fixo', 490, null, null, 1); $e100 = $it('estudo100', 'fixo', 990, null, 100, 2); $e200 = $it('estudo200', 'fixo', 1690, null, 200, 2); $e300 = $it('estudo300', 'fixo', 2490, null, 300, 2);
$arq = $it('arquitetura', 'm2', 100, 3500); $cons = $it('consultoria', 'hora', 250);
$all = [$planta, $e300, $e100, $e200, $arq, $cons];
$ok(Pricing::studyFor($all, 100)['code'] === 'estudo100' && Pricing::studyFor($all, 100.4)['code'] === 'estudo200' && Pricing::studyFor($all, 300)['code'] === 'estudo300' && Pricing::studyFor($all, 301) === null, 'faixa do estudo pela área (acima de 300 = sob consulta)');
$ok(Pricing::line($arq, 20) === 3500.0 && Pricing::line($arq, 120) === 12000.0, 'projeto por m² respeita o mínimo de R$ 3.500');
$ok(Pricing::line($cons, 2) === 500.0, 'consultoria por hora');
$q = Pricing::quote([[$arq, 120]], [$planta, $e200]);
$ok($q['credit'] === 1690.0 && $q['total'] === 10310.0, 'abate só a etapa paga mais alta (estudo, não a planta)');
$q = Pricing::quote([[$e100, 1], [$cons, 1]], [$planta]);
$ok($q['credit'] === 0.0 && $q['total'] === 1240.0, 'sem projeto completo, nada é abatido');
$q = Pricing::quote([[$arq, 10]], [$e300]);
$ok($q['credit'] === 2490.0 && $q['total'] === 1010.0, 'abatimento nunca passa do valor do projeto');
use App\Controllers\ProposalController;
$txt = ProposalController::whatsappText(['title' => 'Interiores', 'total' => 12000, 'valid_until' => '2026-12-10'], 'Ana Souza', 'https://crm.x/p/abc');
$ok(str_contains($txt, 'Olá, Ana!') && str_contains($txt, 'R$ 12.000,00') && str_contains($txt, '10/12/2026') && str_ends_with($txt, 'https://crm.x/p/abc'), 'mensagem de WhatsApp da proposta');

use App\Controllers\DashboardController as Dash;
$ok(Dash::median([]) === null && Dash::median([5, 1, 3]) === 3.0 && Dash::median([4, 1, 3, 2]) === 2.5, 'mediana do tempo até o 1º contato');
$days = Dash::fillDays(['2026-11-02' => 3], '2026-11-01', '2026-11-03');
$ok($days === ['2026-11-01' => 0, '2026-11-02' => 3, '2026-11-03' => 0], 'dias sem lead aparecem como zero');

$sch = ProposalController::scheduleFrom(['Conceito', 'Anteprojeto', '', 'Entrega'], ['10 dias úteis', '', '5 dias', '3 dias']);
$ok($sch === [['stage' => 'Conceito', 'duration_text' => '10 dias úteis'], ['stage' => 'Entrega', 'duration_text' => '3 dias']], 'cronograma só leva etapas com prazo');
$ok(ProposalController::isFullName('Ana Souza') && !ProposalController::isFullName('Ana') && !ProposalController::isFullName('A B') && ProposalController::isFullName('  Maria  de Lourdes '), 'aceite exige nome completo');

use App\Opportunities;
$ok(Opportunities::clientStage([]) === 'novo', 'cliente sem oportunidade fica em Novo');
$ok(Opportunities::clientStage([['id'=>1,'stage'=>'ganho','updated_at'=>'2026-01-01'],['id'=>2,'stage'=>'briefing','updated_at'=>'2025-01-01'],['id'=>3,'stage'=>'qualificado','updated_at'=>'2026-02-01']]) === 'briefing', 'etapa do cliente = oportunidade aberta mais avançada');
$ok(Opportunities::clientStage([['id'=>1,'stage'=>'perdido','updated_at'=>'2026-01-01'],['id'=>2,'stage'=>'ganho','updated_at'=>'2026-03-01']]) === 'ganho', 'sem abertas, vale a fechada mais recente');
use App\Auth as A;
$ok(A::permsFor(['admin']) === ['*'] && in_array('finance', A::permsFor(['financeiro']), true) && !in_array('crm', A::permsFor(['designer']), true), 'permissões por papel');
$ok(count(array_intersect(['crm','proposals','tasks','projects'], A::permsFor(['comercial','designer']))) === 4 && A::permsFor([]) === [], 'papéis acumulam permissões; sem papel, sem acesso');
use App\Controllers\TaskController as TC;
$ok(TC::bucket('2026-01-01', null, '2026-01-02') === 'atrasadas' && TC::bucket('2026-01-02', null, '2026-01-02') === 'hoje' && TC::bucket('2026-01-03', null, '2026-01-02') === 'proximas' && TC::bucket('2026-01-01', '2026-01-01 10:00', '2026-01-02') === 'feitas', 'tarefas por aba');

use App\Briefings as BR; use App\Files as FI;
$qs = BR::parse("Imóvel | select | Casa;Apartamento\nÁrea (m²) | number\n\nConte mais | textarea\nSem opções | select\nEstilo");
$ok(count($qs) === 5 && $qs[0]['options'] === ['Casa', 'Apartamento'] && $qs[3]['type'] === 'text' && $qs[4]['type'] === 'text', 'perguntas do briefing: tipos, opções e linhas vazias');
$ans = BR::cleanAnswers($qs, [$qs[0]['key'] => 'Castelo', $qs[1]['key'] => '120 m²', $qs[2]['key'] => ' ok ', 'intruso' => 'x']);
$ok($ans[$qs[0]['key']] === '' && $ans[$qs[1]['key']] === '120' && $ans[$qs[2]['key']] === 'ok' && !isset($ans['intruso']), 'respostas: só perguntas conhecidas, opção válida, número limpo');
$ok(FI::check('application/pdf', 1000) === null && FI::check('application/x-php', 10) !== null && FI::check('image/jpeg', 16 * 1048576) !== null && FI::check('image/png', 0) !== null, 'upload: tipo e tamanho');
$ok(FI::cleanName('../../etc/passwd') === 'passwd' && FI::cleanName('planta <script>.pdf') === 'planta _script_.pdf', 'nome de arquivo sem caminho nem HTML');

use App\Projects as PJ; use App\Controllers\ProjectController as PC;
$ok(PJ::days('15 dias') === 15 && PJ::days('2 semanas') === 14 && PJ::days('3 a 4 semanas') === 28 && PJ::days('1 mês') === 30 && PJ::days('10 dias úteis') === 14 && PJ::days('a combinar') === null && PJ::days('5') === null, 'prazo em texto vira dias');
$pl = PJ::plan([['stage' => 'Conceito', 'duration_text' => '10 dias'], ['stage' => 'Anteprojeto', 'duration_text' => '2 semanas'], ['stage' => 'Entrega', 'duration_text' => 'a combinar'], ['stage' => 'Extra', 'duration_text' => '3 dias']], '2026-11-02');
$ok($pl[0]['due_date'] === '2026-11-12' && $pl[1]['due_date'] === '2026-11-26' && $pl[2]['due_date'] === null && $pl[3]['due_date'] === null, 'fases em sequência; prazo ilegível apaga os seguintes');
$ok(count(PJ::plan([], '2026-11-02')) === 7 && PJ::plan([], '2026-11-02')[0]['name'] === 'Briefing validado', 'sem cronograma: 7 fases padrão');
$g = PJ::monthGrid('2026-11');
$ok($g[0][0] === '2026-10-26' && end($g)[6] === '2026-12-06' && count($g) === 6 && count(PJ::monthGrid('2027-02')) === 4, 'calendário começa na segunda e cobre o mês');
$ok(PJ::current([['status' => 'concluida'], ['status' => 'pendente', 'id' => 2]])['id'] === 2 && PJ::current([['status' => 'concluida']]) === null, 'fase atual = primeira não concluída');
$ok(PC::minutes('1,5') === 90 && PC::minutes('1:30') === 90 && PC::minutes('2h') === 120 && PC::minutes('0,25') === 15 && PC::minutes('abc') === 0 && PC::minutes('1:75') === 0, 'horas: 1,5 / 1:30 / 2h');

$phs = [['id' => 1], ['id' => 2], ['id' => 3]];
$ok(PJ::moveStatuses($phs, 2) === [1 => 'concluida', 2 => 'pendente', 3 => 'pendente'] && PJ::moveStatuses($phs, null) === [1 => 'concluida', 2 => 'concluida', 3 => 'concluida'] && PJ::moveStatuses($phs, 1)[1] === 'pendente', 'Kanban: mover conclui as anteriores e reabre as seguintes');
$ok(App\Files::check('image/vnd.dwg', 5000) === null && App\Files::check('application/zip', 5000) !== null, 'upload aceita DWG');

echo $fail ? "\n$fail falha(s)\n" : "\nTudo certo\n";
exit($fail ? 1 : 0);
