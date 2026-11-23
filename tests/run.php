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

use App\Finance as Fin; use App\Controllers\FinanceController as FCT;
$pl = Fin::plan('50% na assinatura + 50% na entrega', 10000, '2026-11-01', '2026-12-20');
$ok(count($pl) === 2 && $pl[0]['amount'] === 5000.0 && $pl[0]['due_date'] === '2026-11-01' && $pl[1]['due_date'] === '2026-12-20' && $pl[0]['description'] === 'Entrada' && $pl[1]['description'] === 'Antes da entrega final', 'parcelas: 50% + 50%, a última no início da fase final');
$ok(App\Projects::beforeFinal([['due_date' => '2026-12-01'], ['due_date' => '2026-12-20'], ['due_date' => '2026-12-30']]) === '2026-12-20' && App\Projects::beforeFinal([['due_date' => '2026-12-01']]) === '2026-12-01' && App\Projects::beforeFinal([]) === null, 'contrato: última parcela vence quando começa a fase final (antes da entrega)');
$ok(array_column(Fin::plan('40% + 40% + 20%', 1000, '2026-11-01', '2026-11-20'), 'due_date') === ['2026-11-01', '2026-11-20', '2026-11-20'], 'contrato: nenhuma parcela vence depois da última (antes da entrega)');
$pl = Fin::plan('Em 3x sem juros', 1000, '2026-11-01');
$ok(count($pl) === 3 && $pl[0]['amount'] === 333.33 && $pl[2]['amount'] === 333.34 && $pl[1]['due_date'] === '2026-12-01' && array_sum(array_column($pl, 'amount')) === 1000.0, 'parcelas: 3x iguais, centavos na última, 30 dias entre elas');
$pl = Fin::plan('30% / 40% / 30%', 3500, '2026-11-01');
$ok(array_column($pl, 'amount') === [1050.0, 1400.0, 1050.0], 'parcelas: 30/40/30');
$ok(count(Fin::plan('à vista no Pix', 990, '2026-11-01')) === 1 && count(Fin::plan('50% agora e 30% depois', 990, '2026-11-01')) === 1, 'sem regra legível (ou % que não somam 100): pagamento único');
$ok(Fin::daysLate('2026-11-20', '2026-11-23') === 3 && Fin::milestone('2026-11-26', '2026-11-23') === -3 && Fin::milestone('2026-11-22', '2026-11-23') === 1 && Fin::milestone('2026-11-21', '2026-11-23') === null, 'dias de atraso e marcos do lembrete');
$inst = ['description' => 'Entrada', 'number' => 1, 'amount' => 5000, 'due_date' => '2026-11-20'];
$m1 = Fin::message($inst, 'Camila Duarte', 'Interiores 90 m²', 2, '2026-11-23', 'pix@liprojetos.com.br', 'LI Projetos');
$m2 = Fin::message($inst, 'Camila Duarte', 'Interiores 90 m²', 2, '2026-11-18');
$ok(str_starts_with($m1, 'Olá, Camila!') && str_contains($m1, 'ainda está em aberto') && str_contains($m1, 'Pix: pix@liprojetos.com.br (LI Projetos)') && str_contains($m1, '(1 de 2)'), 'mensagem de cobrança atrasada com Pix');
$ok(str_contains($m2, 'vence em 20/11') && !str_contains($m2, 'Pix'), 'lembrete antes do vencimento, sem Pix se não configurado');
$sm = Fin::summary(['contract_value' => 10000], [['status' => 'paga', 'amount' => 5000, 'paid_amount' => 5000, 'due_date' => '2026-11-01'], ['status' => 'aberta', 'amount' => 5000, 'due_date' => '2026-11-10'], ['status' => 'cancelada', 'amount' => 999, 'due_date' => '2026-11-10']], 300, 600, 60, '2026-11-23');
$ok($sm['received'] === 5000.0 && $sm['open'] === 5000.0 && $sm['overdue'] === 5000.0 && $sm['labour'] === 600.0 && $sm['margin'] === 9100.0 && $sm['planned'] === 10000.0, 'resumo: recebido ≠ contratado, cancelada fora, margem com horas e despesas');
$ok(FCT::money('1.234,56') === 1234.56 && FCT::money('R$ 800') === 800.0 && FCT::money('99.9') === 99.9 && FCT::money('-5') === null && FCT::money('abc') === null, 'valores em reais: 1.234,56 / R$ 800');

$paid = fn($s, $a, $pa = null, $d = '2026-11-01') => ['status' => $s, 'amount' => $a, 'paid_amount' => $pa, 'due_date' => $d];
$ok(!Fin::paidUp(10000, [$paid('paga', 5000, 5000), $paid('aberta', 5000)]) && Fin::paidUp(10000, [$paid('paga', 5000, 5000), $paid('paga', 5000)]) && !Fin::paidUp(10000, [$paid('paga', 5000, 5000), $paid('cancelada', 5000)]) && Fin::paidUp(0, []) && !Fin::paidUp(990, []), 'anti-calote: originais só com tudo pago (cancelar parcela não quita)');
$ok(Fin::shouldHold([$paid('aberta', 1, null, '2026-11-16')], '2026-11-23') && !Fin::shouldHold([$paid('aberta', 1, null, '2026-11-17')], '2026-11-23') && !Fin::shouldHold([$paid('paga', 1, 1, '2026-10-01')], '2026-11-23'), 'anti-calote: pausa com 7+ dias de atraso');
$ok(Clients::document('52998224725') === '529.982.247-25' && Clients::document('529.982.247-24') === null && Clients::document('111.111.111-11') === null && Clients::document('11222333000181') === '11.222.333/0001-81' && Clients::document('11.222.333/0001-80') === null && Clients::document('123') === null, 'perfil: CPF e CNPJ pelos dígitos verificadores');
[$pf, $pe] = Clients::validateProfile(['document' => '529.982.247-25', 'address_zip' => '70000000', 'address_state' => 'df', 'property_type' => 'casa', 'contact_pref' => 'fax', 'birth_date' => '1990-05-10', 'partner_whatsapp' => '61 99241 4304', 'rg' => ' '], '2026-11-23');
$ok(!$pe && $pf['address_zip'] === '70000-000' && $pf['address_state'] === 'DF' && $pf['property_type'] === 'casa' && $pf['contact_pref'] === null && $pf['partner_whatsapp'] === '+5561992414304' && $pf['rg'] === null, 'perfil: CEP, UF, opções e vazio = null');
[, $pe] = Clients::validateProfile(['document' => '123', 'address_zip' => '123', 'address_state' => 'XX', 'birth_date' => '2030-01-01', 'partner_whatsapp' => 'abc'], '2026-11-23');
$ok(isset($pe['document'], $pe['address_zip'], $pe['address_state'], $pe['birth_date'], $pe['partner_whatsapp']), 'perfil: recusa documento, CEP, UF, nascimento no futuro e WhatsApp inválidos');
$ps = Fin::payerSummary([$paid('paga', 100, 100, '2026-11-01') + ['paid_at' => '2026-11-01'], $paid('paga', 100, 100, '2026-11-01') + ['paid_at' => '2026-11-05'], $paid('aberta', 50, null, '2026-11-10') + ['paid_at' => null], $paid('cancelada', 999) + ['paid_at' => null]], '2026-11-23');
$ok($ps['paid'] === 200.0 && $ps['overdue'] === 50.0 && $ps['late_paid'] === 1 && $ps['max_late'] === 13 && $ps['badge'][0] === 'Inadimplente' && $ps['count'] === 3, 'perfil: resumo do pagador (cancelada fora, atraso conta)');
$ok(Fin::payerSummary([$paid('paga', 100, 100) + ['paid_at' => '2026-10-30']], '2026-11-23')['badge'][0] === 'Bom pagador' && Fin::payerSummary([], '2026-11-23')['badge'][0] === 'Sem histórico', 'perfil: bom pagador / sem histórico');
use App\Briefings as Brf;
$mq = Brf::parse("Abertura | titulo\n“Quero entender…” | roteiro\nB01 — Tipo | select | Apartamento;Casa\nB02 — Área | number");
$ok(count($mq) === 4 && $mq[0]['type'] === 'titulo' && $mq[1]['type'] === 'roteiro' && count(Brf::inputs($mq)) === 2 && Brf::answered($mq, [$mq[0]['key'] => 'x', $mq[2]['key'] => 'Casa']) === 1 && !isset(Brf::cleanAnswers($mq, [$mq[1]['key'] => 'x'])[$mq[1]['key']]), 'reunião: título e roteiro aparecem mas não contam como resposta');
use App\Revisions as Rev;
$ok(Rev::included('Inclui 2 rodadas de revisão em cada etapa.') === 2 && Rev::included('Inclui 3 rodadas') === 3 && Rev::included('Inclui duas rodadas de ajuste') === 2 && Rev::included('Cada sessão tem 1 hora.') === 2 && Rev::included(null) === 2, 'rodadas incluídas lidas da política da proposta (padrão 2)');
$reqs = [['type' => 'revisao', 'status' => 'concluida', 'phase_id' => 5], ['type' => 'revisao', 'status' => 'recusada', 'phase_id' => 5], ['type' => 'correcao', 'status' => 'aberta', 'phase_id' => 5], ['type' => 'revisao', 'status' => 'aberta', 'phase_id' => 6]];
$ok(Rev::used($reqs, 5) === 1 && Rev::used($reqs, 6) === 1 && Rev::used($reqs, 7) === 0, 'contagem por fase: correção e recusada não contam');
$ok(Rev::classify('revisao', 0, 2) === [false, 1, 'aberta'] && Rev::classify('revisao', 1, 2) === [false, 2, 'aberta'] && Rev::classify('revisao', 2, 2) === [true, 3, 'aguardando_aprovacao'], '3ª rodada vira extra e espera aprovação do custo');
$ok(Rev::classify('escopo', 0, 2) === [true, null, 'aguardando_aprovacao'] && Rev::classify('correcao', 9, 2) === [false, null, 'aberta'], 'escopo sempre extra; correção nunca');
$ok(!Rev::canStart(['status' => 'aguardando_aprovacao']) && Rev::canStart(['status' => 'aberta']) && !Rev::canStart(['status' => 'concluida']), 'só começa sem aprovação pendente');

use App\Brand as Mk;
$ok(Mk::pairs("10+ | anos\n\n50+|projetos\nSó valor") === [['10+', 'anos'], ['50+', 'projetos'], ['Só valor', '']], 'marca: linhas "valor | texto"');
$ok(Mk::initials('Iata de Almeida') === 'IA' && Mk::initials('Ana') === 'A' && Mk::initials('Ló') === 'L', 'marca: iniciais');
$ok(Mk::validColor('#1a5fb4') === '#1A5FB4' && Mk::validColor('red') === null && Mk::validColor('#12345') === null && Mk::validColor('#12345g') === null, 'marca: cor válida só #RRGGBB');
$ok(Mk::contrast('#000000', '#FFFFFF') === 21.0 && Mk::contrast('#866628', '#FFFDF9') > 4.5 && Mk::contrast('#F5D76E', '#FFFDF9') < 4.5, 'marca: contraste WCAG (avisa cor clara demais)');
$pl = Mk::palette('#1A5FB4');
$ok($pl['accent'] === '#1A5FB4' && Mk::contrast($pl['accent_strong'], '#FFFFFF') > Mk::contrast('#1A5FB4', '#FFFFFF') && Mk::contrast($pl['accent_soft'], '#FFFFFF') < 1.5 && Mk::mix('#000000', '#FFFFFF', 0.5) === '#808080', 'marca: tons derivados da cor');

use App\Portal;
$ok(Portal::attemptKey(' Ana@Ex.com ') === 'portal:ana@ex.com' && mb_strlen(Portal::attemptKey(str_repeat('a', 200) . '@x.com')) === 190, 'portal: tentativas contadas à parte da equipe, chave cabe na coluna');
$ok(Portal::inviteValid('2026-11-30 10:00:00', strtotime('2026-11-23 10:00:00')) && !Portal::inviteValid('2026-11-23 09:59:59', strtotime('2026-11-23 10:00:00')) && !Portal::inviteValid(null, time()), 'portal: convite vale até expirar');

use App\WhatsApp;
$ok(WhatsApp::phone('556192414304@s.whatsapp.net') === '+5561992414304' && WhatsApp::phone('5561992414304') === '+5561992414304', 'whatsapp: celular sem o 9 ganha o 9; com o 9 fica igual');
$ok(WhatsApp::phone('556132410000@s.whatsapp.net') === '+556132410000', 'whatsapp: fixo não ganha 9');
$ok(WhatsApp::phone('120363000000@g.us') === null && WhatsApp::phone('status@broadcast') === null && WhatsApp::phone('123@lid') === null, 'whatsapp: grupo, status e lid sem número são ignorados');
$ok(WhatsApp::phone('14155550100@s.whatsapp.net') === '+14155550100', 'whatsapp: número estrangeiro mantido');
$evo = fn(array $msg, array $key = []) => ['event' => 'messages.upsert', 'data' => ['key' => $key + ['remoteJid' => '5561992414304@s.whatsapp.net', 'fromMe' => false, 'id' => 'ABC'], 'pushName' => "Ana\x07 Souza", 'message' => $msg, 'messageTimestamp' => 1795400000]];
$r = WhatsApp::parse($evo(['conversation' => 'Oi, quero um projeto']));
$ok(count($r) === 1 && $r[0]['phone'] === '+5561992414304' && $r[0]['text'] === 'Oi, quero um projeto' && $r[0]['name'] === 'Ana Souza' && $r[0]['id'] === 'ABC' && !$r[0]['from_me'] && $r[0]['at'] === 1795400000, 'whatsapp: texto simples da Evolution');
$r = WhatsApp::parse($evo(['extendedTextMessage' => ['text' => 'com link https://x.y']], ['fromMe' => true]));
$ok($r[0]['text'] === 'com link https://x.y' && $r[0]['from_me'] && $r[0]['name'] === null, 'whatsapp: extendedText e mensagem enviada pelo celular');
$r = WhatsApp::parse($evo(['imageMessage' => ['caption' => 'planta']]));
$ok($r[0]['kind'] === 'imagem' && $r[0]['text'] === 'planta', 'whatsapp: imagem com legenda');
$ok(WhatsApp::parse($evo(['reactionMessage' => ['text' => '👍']])) === [] && WhatsApp::parse(['event' => 'connection.update', 'data' => ['state' => 'open']]) === [], 'whatsapp: reação e eventos de conexão ignorados');
$ok(WhatsApp::parse($evo(['conversation' => 'x'], ['remoteJid' => '120363@g.us'])) === [], 'whatsapp: mensagem de grupo ignorada');
$ok(WhatsApp::parse($evo(['conversation' => 'x'], ['remoteJid' => '99@lid', 'senderPn' => '5561992414304@s.whatsapp.net']))[0]['phone'] === '+5561992414304', 'whatsapp: lid usa o número real (senderPn)');
$r = WhatsApp::parse(['phone' => '5561992414304', 'fromMe' => false, 'messageId' => 'Z1', 'senderName' => 'Bia', 'text' => ['message' => 'olá'], 'momment' => 1795400000000]);
$ok($r[0]['text'] === 'olá' && $r[0]['name'] === 'Bia' && $r[0]['at'] === 1795400000, 'whatsapp: formato Z-API também é aceito');

$dr = fn(array $m, string $ev = 'messages') => WhatsApp::parse(['EventType' => 'messages', 'message' => $m + ['chatid' => '556192414304@s.whatsapp.net', 'messageid' => '3EB0X', 'fromMe' => false, 'isGroup' => false, 'senderName' => 'Ana', 'messageType' => 'Conversation', 'messageTimestamp' => 1795400000000]], $ev);
$r = $dr(['text' => 'Oi, vi o anúncio']);
$ok(count($r) === 1 && $r[0]['phone'] === '+5561992414304' && $r[0]['text'] === 'Oi, vi o anúncio' && $r[0]['id'] === '3EB0X' && $r[0]['at'] === 1795400000 && $r[0]['name'] === 'Ana', 'whatsapp DROPE: mensagem recebida (timestamp em ms)');
$ok($dr(['text' => 'x', 'isGroup' => true]) === [] && $dr(['text' => 'x'], 'messages_update') === [], 'whatsapp DROPE: grupo e outros eventos ignorados');
$ok($dr(['text' => 'planta', 'messageType' => 'ImageMessage'])[0]['kind'] === 'imagem' && $dr(['text' => '', 'messageType' => 'AudioMessage'])[0]['kind'] === 'audio', 'whatsapp DROPE: mídia vira imagem/áudio');
$ok($dr(['text' => 'x', 'chatid' => '99@lid', 'sender_pn' => '5561992414304@s.whatsapp.net'])[0]['phone'] === '+5561992414304', 'whatsapp DROPE: lid usa sender_pn');
$ok(WhatsApp::parse(['success' => true, 'data' => ['messageid' => 'A1', 'chatid' => '5561992414304@s.whatsapp.net', 'fromMe' => true, 'text' => 'ok', 'messageType' => 'text']])[0]['from_me'], 'whatsapp DROPE: corpo no formato data{} também é lido');
$raw = '{"a":1}'; $sig = 'sha256=' . hash_hmac('sha256', $raw, 'seg');
$ok(WhatsApp::signatureOk($raw, $sig, 'seg') && !WhatsApp::signatureOk($raw, $sig, 'outro') && !WhatsApp::signatureOk($raw . ' ', $sig, 'seg') && !WhatsApp::signatureOk($raw, '', ''), 'whatsapp DROPE: assinatura HMAC conferida');
echo $fail ? "\n$fail falha(s)\n" : "\nTudo certo\n";
exit($fail ? 1 : 0);
