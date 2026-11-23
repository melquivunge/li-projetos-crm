<?php
use App\{Auth, Clients, Opportunities};
use App\Controllers\TaskController as TC;
$waDigits = ltrim((string) $c['whatsapp'], '+');
$icons = ['nota' => 'Nota', 'etapa' => 'Etapa', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'proposta' => 'Proposta', 'sistema' => 'Sistema', 'reuniao' => 'Reunião', 'ligacao' => 'Ligação'];
$today = date('Y-m-d');
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4">
  <div>
    <a class="small link-secondary" href="/clientes">← Clientes</a>
    <h1 class="h3 mb-1"><?= e($c['name']) ?></h1>
    <span class="badge stage-<?= e($c['stage']) ?>"><?= e(Clients::STAGES[$c['stage']]) ?></span><?php if (count($opps) > 1): ?> <span class="small text-body-secondary"><?= count($opps) ?> oportunidades</span><?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if ($waDigits): ?><a class="btn btn-success" href="https://wa.me/<?= e($waDigits) ?>" target="_blank" rel="noopener noreferrer">Abrir WhatsApp</a><?php endif; ?>
    <a class="btn btn-dark" href="/clientes/<?= (int) $c['id'] ?>/propostas/nova">Nova proposta</a>
    <a class="btn btn-outline-dark" href="/clientes/<?= (int) $c['id'] ?>/editar">Editar</a>
  </div>
</div>
<div class="row g-4">
  <div class="col-lg-4">
<?php
$row = fn(string $label, ?string $val) => $val !== null && $val !== '' ? '<dt class="col-5">' . e($label) . '</dt><dd class="col-7 text-break">' . e($val) . '</dd>' : '';
$addr = trim(implode(', ', array_filter([trim(($c['address_street'] ?? '') . ' ' . ($c['address_number'] ?? '')), $c['address_extra'] ?? null, $c['address_district'] ?? null, trim(($c['city'] ?? '') . ($c['address_state'] ? ' / ' . $c['address_state'] : ''))])) . ($c['address_zip'] ? ' · CEP ' . $c['address_zip'] : ''), ', ');
$age = $c['birth_date'] ? (int) date_diff(date_create($c['birth_date']), date_create('today'))->y : null;
$missing = array_keys(array_filter(['CPF/CNPJ' => !$c['document'], 'endereço' => !$c['address_street'], 'e-mail' => !$c['email']]));
?>
    <div class="card card-body mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0">Perfil</h2><span class="badge text-bg-<?= e($payer['badge'][1]) ?>" title="Histórico de pagamentos"><?= e($payer['badge'][0]) ?></span></div>
      <dl class="row small mb-0">
        <dt class="col-5">WhatsApp</dt><dd class="col-7"><?= e($c['whatsapp'] ?? '—') ?></dd>
        <dt class="col-5">E-mail</dt><dd class="col-7 text-break"><?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?php else: ?>—<?php endif; ?></dd>
        <?= $row('Prefere', isset(Clients::CONTACT_PREF[$c['contact_pref'] ?? '']) ? Clients::CONTACT_PREF[$c['contact_pref']] . ($c['contact_time'] ? ', ' . $c['contact_time'] : '') : $c['contact_time']) ?>
        <?= $row('Decisor', $c['partner_name'] ? $c['partner_name'] . ($c['partner_whatsapp'] ? ' · ' . $c['partner_whatsapp'] : '') : null) ?>
        <?= $row('Nascimento', $c['birth_date'] ? date('d/m/Y', strtotime($c['birth_date'])) . " ($age anos)" : null) ?>
        <?= $row('Profissão', $c['profession']) ?>
      </dl>
      <?php if ($missing): ?><p class="small text-body-secondary mt-2 mb-0 profile-missing">Falta para o contrato: <?= e(implode(', ', $missing)) ?>. <a href="/clientes/<?= (int) $c['id'] ?>/editar">Completar</a></p><?php endif; ?>
      <h3 class="profile-sub">Documentos e endereço</h3>
      <dl class="row small mb-0">
        <dt class="col-5">CPF/CNPJ</dt><dd class="col-7"><?= e($c['document'] ?? '—') ?></dd>
        <?= $row('RG', $c['rg']) ?>
        <dt class="col-5">Endereço</dt><dd class="col-7"><?= e($addr ?: ($c['city'] ?? '—')) ?></dd>
      </dl>
      <h3 class="profile-sub">Imóvel e projeto</h3>
      <dl class="row small mb-0">
        <dt class="col-5">Serviço</dt><dd class="col-7"><?= e($c['service'] ?? '—') ?></dd>
        <dt class="col-5">Área</dt><dd class="col-7"><?= $c['area_m2'] ? e(rtrim(rtrim((string) $c['area_m2'], '0'), '.')) . ' m²' : '—' ?></dd>
        <?= $row('Imóvel', trim(implode(' · ', array_filter([Clients::PROPERTY_TYPES[$c['property_type'] ?? ''] ?? null, Clients::PROPERTY_STATUS[$c['property_status'] ?? ''] ?? null, $c['property_condo']])))) ?>
        <?= $row('Endereço', $c['property_address']) ?>
      </dl>
      <h3 class="profile-sub">Origem</h3>
      <dl class="row small mb-0">
        <dt class="col-5">Origem</dt><dd class="col-7"><?= e($c['origin'] ?? '—') ?><?= $c['gclid'] ? ' <span class="badge text-bg-light">gclid</span>' : '' ?></dd>
        <?= $row('Indicação', $c['referral']) ?>
        <dt class="col-5">Responsável</dt><dd class="col-7"><?= e($c['owner_name'] ?? '—') ?></dd>
        <dt class="col-5">Cadastro</dt><dd class="col-7"><?= e(date('d/m/Y H:i', strtotime($c['created_at']))) ?></dd>
        <dt class="col-5">1º contato</dt><dd class="col-7"><?= $c['first_contact_at'] ? e(date('d/m/Y H:i', strtotime($c['first_contact_at']))) : '—' ?></dd>
        <dt class="col-5">Consentimento</dt><dd class="col-7"><?= $c['consent_at'] ? e(date('d/m/Y H:i', strtotime($c['consent_at']))) : '—' ?></dd>
      </dl>
    </div>
    <?php if ($payer['count'] && Auth::can('finance')): ?>
    <section class="card card-body mb-3" aria-labelledby="h-fin">
      <h2 class="h6 d-flex align-items-center gap-2" id="h-fin"><?= icon('coins', 'icon icon-sm') ?>Financeiro</h2>
      <dl class="row small mb-0">
        <dt class="col-5">Contratado</dt><dd class="col-7"><?= e(money($payer['contracted'])) ?></dd>
        <dt class="col-5">Pago</dt><dd class="col-7"><?= e(money($payer['paid'])) ?></dd>
        <dt class="col-5">Em aberto</dt><dd class="col-7"><?= e(money($payer['open'])) ?></dd>
        <?php if ($payer['overdue'] > 0): ?><dt class="col-5 text-danger">Vencido</dt><dd class="col-7 text-danger"><?= e(money($payer['overdue'])) ?> · <?= (int) $payer['max_late'] ?> dia<?= $payer['max_late'] > 1 ? 's' : '' ?> de atraso</dd><?php endif; ?>
        <?php if ($payer['late_paid']): ?><dt class="col-5">Pagou atrasado</dt><dd class="col-7"><?= (int) $payer['late_paid'] ?> de <?= (int) $payer['count'] ?> parcelas</dd><?php endif; ?>
      </dl>
      <a class="small mt-2" href="/financeiro">Abrir no financeiro</a>
    </section>
    <?php endif; ?>
    <section class="card card-body mb-3" aria-labelledby="h-tasks">
      <h2 class="h6 d-flex align-items-center gap-2" id="h-tasks"><?= icon('check', 'icon icon-sm') ?>Tarefas</h2>
      <?php $open = array_filter($tasks, fn($t) => !$t['done_at']); if (!$open): ?><p class="small text-body-secondary">Nenhuma tarefa aberta. Todo cliente em andamento deveria ter um próximo passo com data.</p><?php endif; ?>
      <ul class="task-list">
        <?php foreach ($tasks as $t): [$dl, $dt] = due($t['due_at']); if ($t['done_at'] && count($tasks) > 6) continue; ?>
        <li class="<?= $t['done_at'] ? 'is-done' : '' ?>">
          <form method="post" action="/tarefas/<?= (int) $t['id'] ?>/feita"><?= csrf_field() ?><input type="hidden" name="back" value="/clientes/<?= (int) $c['id'] ?>"><?php if ($t['done_at']): ?><input type="hidden" name="undo" value="1"><?php endif; ?>
            <button class="task-check" aria-label="<?= $t['done_at'] ? 'Reabrir' : 'Concluir' ?>: <?= e($t['title']) ?>"><?= $t['done_at'] ? icon('check', 'icon icon-sm') : '' ?></button></form>
          <span class="min-w-0"><span class="d-block text-truncate"><?= e($t['title']) ?></span><small class="text-body-secondary"><?php if (!$t['done_at']): ?><span class="due due-<?= $dt ?>"><?= e($dl) ?></span> <?php endif; ?><?= e($t['owner_name'] ?? '') ?><?= $t['priority'] === 'alta' ? ' · prioridade alta' : '' ?></small></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="/tarefas" class="d-grid gap-2 mt-2">
        <?= csrf_field() ?><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
        <label class="visually-hidden" for="t-title">Nova tarefa</label><input class="form-control" id="t-title" name="title" maxlength="200" placeholder="Nova tarefa, ex.: ligar para marcar a reunião" required>
        <div class="d-flex gap-2">
          <label class="visually-hidden" for="t-due">Data</label><input class="form-control" type="date" id="t-due" name="due_at" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
          <label class="visually-hidden" for="t-prio">Prioridade</label><select class="form-select w-auto" id="t-prio" name="priority"><?php foreach (TC::PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        </div>
        <?php if (Auth::isAdmin()): ?><label class="visually-hidden" for="t-owner">Responsável</label><select class="form-select" id="t-owner" name="owner_id"><?php foreach ($owners as $id => $n): ?><option value="<?= (int) $id ?>" <?= (int) $id === Auth::id() ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if (count($opps) > 1): ?><label class="visually-hidden" for="t-opp">Oportunidade</label><select class="form-select" id="t-opp" name="opportunity_id"><option value="">Sem oportunidade específica</option><?php foreach ($opps as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['title']) ?></option><?php endforeach; ?></select><?php elseif ($opps): ?><input type="hidden" name="opportunity_id" value="<?= (int) $opps[0]['id'] ?>"><?php endif; ?>
        <button class="btn btn-dark">Adicionar tarefa</button>
      </form>
    </section>
    <?php $portal = App\Db::q('SELECT active, password_hash IS NOT NULL AS has_pw, invite_expires, last_login_at FROM client_users WHERE client_id = ?', [$c['id']])->fetch(); ?>
    <section class="card card-body mb-3" aria-labelledby="h-portal">
      <h2 class="h6 d-flex align-items-center gap-2" id="h-portal"><?= icon('user', 'icon icon-sm') ?>Portal do cliente</h2>
      <p class="small text-body-secondary mb-2"><?php if (!$portal || !$portal['active']): ?>O cliente acompanha fases, arquivos, aprovações e pagamentos com login próprio (o e-mail do cadastro).<?php elseif ($portal['has_pw']): ?>Acesso ativo<?= $portal['last_login_at'] ? ' · último acesso em ' . e(date('d/m/Y H:i', strtotime($portal['last_login_at']))) : '' ?>.<?php else: ?>Convite enviado<?= $portal['invite_expires'] ? ', vale até ' . e(date('d/m H:i', strtotime($portal['invite_expires']))) : '' ?>. O cliente ainda não criou a senha.<?php endif; ?></p>
      <div class="d-flex flex-wrap gap-2">
        <form method="post" action="/clientes/<?= (int) $c['id'] ?>/portal"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark"<?= $c['email'] ? '' : ' disabled title="Cadastre o e-mail primeiro"' ?>><?= $portal && $portal['active'] ? 'Enviar novo convite' : 'Convidar para o portal' ?></button></form>
        <?php if ($portal && $portal['active']): ?><form method="post" action="/clientes/<?= (int) $c['id'] ?>/portal"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn btn-sm btn-link link-danger">Desativar acesso</button></form><?php endif; ?>
      </div>
    </section>
    <?php if (Auth::isAdmin()): ?>
    <form method="post" action="/clientes/<?= (int) $c['id'] ?>/excluir" class="card card-body border-danger-subtle">
      <?= csrf_field() ?>
      <h2 class="h6 text-danger">Excluir dados (LGPD)</h2>
      <p class="small text-body-secondary mb-2">Apaga o cliente e todo o histórico. Não tem como desfazer.</p>
      <label class="form-label small" for="d-confirm">Digite EXCLUIR para confirmar</label>
      <input class="form-control mb-2" id="d-confirm" name="confirm" autocomplete="off">
      <button class="btn btn-outline-danger">Excluir definitivamente</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <form method="post" action="/clientes/<?= (int) $c['id'] ?>/nota" class="card card-body mb-3">
      <?= csrf_field() ?>
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"><label class="form-label h6 mb-0" for="n-body">Registrar atividade</label>
        <div class="seg" role="radiogroup" aria-label="Tipo"><?php foreach (['nota' => 'Nota', 'ligacao' => 'Ligação', 'reuniao' => 'Reunião'] as $k => $l): ?><label class="seg-btn seg-radio"><input type="radio" name="type" value="<?= $k ?>" <?= $k === 'nota' ? 'checked' : '' ?>><?= $l ?></label><?php endforeach; ?></div></div>
      <textarea class="form-control mb-2" id="n-body" name="body" rows="3" maxlength="5000" required placeholder="O que foi conversado, próximo passo, data combinada…"></textarea>
      <div><button class="btn btn-dark">Registrar</button></div>
    </form>
    <section class="mb-4" aria-labelledby="h-opps">
      <div class="d-flex align-items-center justify-content-between mb-2"><h2 class="h5 mb-0" id="h-opps">Oportunidades</h2>
        <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#newOpp" aria-expanded="false" aria-controls="newOpp"><?= icon('plus', 'icon icon-sm') ?>Nova oportunidade</button></div>
      <form method="post" action="/clientes/<?= (int) $c['id'] ?>/oportunidades" class="collapse card card-body mb-2" id="newOpp">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-md-5"><label class="form-label" for="o-title">Nome</label><input class="form-control" id="o-title" name="title" maxlength="160" required placeholder="Ex.: Interiores do apartamento"></div>
          <div class="col-md-4"><label class="form-label" for="o-service">Serviço</label><select class="form-select" id="o-service" name="service"><option value="">—</option><?php foreach (Clients::SERVICES as $sv): ?><option><?= e($sv) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label" for="o-value">Valor estimado</label><input class="form-control" id="o-value" name="estimated_value" inputmode="decimal" placeholder="R$"></div>
        </div>
        <div class="mt-2"><button class="btn btn-dark">Criar oportunidade</button></div>
      </form>
      <div class="d-grid gap-2">
        <?php foreach ($opps as $o): $closed = in_array($o['stage'], ['ganho', 'perdido'], true); ?>
        <div class="card card-body opp">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="min-w-0"><strong class="d-block"><?= e($o['title']) ?></strong>
              <small class="text-body-secondary"><?= e(implode(' · ', array_filter([$o['service'], $o['estimated_value'] ? money((float) $o['estimated_value']) : null, $o['expected_close'] ? 'fecha em ' . date('d/m', strtotime($o['expected_close'])) : null, days_since($o['stage_changed_at']) . ' d nesta etapa']))) ?></small></div>
            <span class="badge stage-<?= e($o['stage']) ?>"><?= e(Opportunities::STAGES[$o['stage']]) ?></span>
          </div>
          <?php if ($o['stage'] === 'perdido' && $o['lost_reason']): ?><p class="small text-body-secondary mb-0 mt-1">Motivo: <?= e($o['lost_reason']) ?></p><?php endif; ?>
          <?php $bm = $briefings[$o['id']]['reuniao'] ?? null; $bf = $briefings[$o['id']]['servico'] ?? null; ?>
          <div class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
            <?= icon('calendar', 'icon icon-sm') ?>
            <?php if ($bm): ?><a href="/briefings/<?= (int) $bm['id'] ?>">Reunião inicial</a>
            <?php else: ?><form method="post" action="/oportunidades/<?= (int) $o['id'] ?>/briefing"><?= csrf_field() ?><input type="hidden" name="kind" value="reuniao"><button class="btn btn-link btn-sm p-0">Roteiro da reunião</button></form><?php endif; ?>
            <span aria-hidden="true">·</span> <?= icon('file', 'icon icon-sm') ?>
            <?php if ($bf): ?><a href="/briefings/<?= (int) $bf['id'] ?>">Briefing</a> <span class="badge bstatus-<?= e($bf['status']) ?>"><?= e(App\Briefings::STATUS[$bf['status']]) ?></span>
            <?php else: ?><form method="post" action="/oportunidades/<?= (int) $o['id'] ?>/briefing"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0">Criar briefing</button></form><?php endif; ?>
            <?php foreach (App\Db::q('SELECT id, status FROM projects WHERE opportunity_id = ?', [$o['id']]) as $pj): ?><span aria-hidden="true">·</span> <?= icon('folder', 'icon icon-sm') ?><a href="/projetos/<?= (int) $pj['id'] ?>">Projeto #<?= (int) $pj['id'] ?></a> <span class="badge pstatus-<?= e($pj['status']) ?>"><?= e(App\Projects::STATUS[$pj['status']]) ?></span><?php endforeach; ?>
          </div>
          <form method="post" action="/oportunidades/<?= (int) $o['id'] ?>/etapa" class="opp-stage mt-2">
            <?= csrf_field() ?>
            <label class="visually-hidden" for="os-<?= (int) $o['id'] ?>">Etapa</label>
            <select class="form-select form-select-sm" id="os-<?= (int) $o['id'] ?>" name="stage"><?php foreach (Opportunities::STAGES as $k => $l): ?><option value="<?= e($k) ?>" <?= $o['stage'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="visually-hidden" for="or-<?= (int) $o['id'] ?>">Motivo da perda</label>
            <input class="form-control form-control-sm" id="or-<?= (int) $o['id'] ?>" name="lost_reason" maxlength="255" placeholder="Motivo, se perdido">
            <button class="btn btn-sm btn-outline-dark">Mover</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php if ($proposals): ?>
    <h2 class="h5">Propostas</h2>
    <div class="list-group mb-4">
      <?php foreach ($proposals as $pr): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="/propostas/<?= (int) $pr['id'] ?>">
        <span>#<?= (int) $pr['id'] ?> · <?= e($pr['title']) ?><?= (int) $pr['version'] > 1 ? ' (v' . (int) $pr['version'] . ')' : '' ?> <span class="badge status-<?= e($pr['status']) ?>"><?= e(App\Controllers\ProposalController::STATUS[$pr['status']]) ?></span></span><span class="fw-semibold"><?= e(money((float) $pr['total'])) ?></span></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <h2 class="h5">Linha do tempo</h2>
    <?php if (!$events): ?><p class="text-body-secondary">Sem registros ainda.</p><?php endif; ?>
    <ol class="list-unstyled timeline">
      <?php foreach ($events as $ev): ?>
      <li class="card card-body mb-2 py-2">
        <div class="small text-body-secondary"><span class="badge text-bg-light"><?= e($icons[$ev['type']] ?? $ev['type']) ?></span>
          <?= e(date('d/m/Y H:i', strtotime($ev['created_at']))) ?> · <?= e($ev['user_name'] ?? 'Sistema') ?></div>
        <div class="mt-1 pre-wrap"><?= e($ev['body']) ?></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</div>
