<?php
use App\Projects as PJ; use App\Controllers\TaskController as TC;
$today = date('Y-m-d');
$pid = (int) $p['id'];
$phaseName = array_column($phases, 'name', 'id');
$cur = PJ::current($phases);
$nDone = count(array_filter($phases, fn($ph) => $ph['status'] === 'concluida'));
$hm = fn(int $m) => intdiv($m, 60) . 'h' . ($m % 60 ? sprintf('%02d', $m % 60) : '');
$late = $p['status'] === 'ativo' && $p['due_date'] && $p['due_date'] < $today;
$openTasks = array_filter($tasks, fn($t) => !$t['done_at']);
?>
<div class="page-head">
  <div class="min-w-0">
    <p class="small mb-1"><a class="link-secondary" href="/projetos">Projetos</a> / Projeto #<?= $pid ?></p>
    <h1 class="h3"><?= e($p['title']) ?></h1>
    <p><span class="badge pstatus-<?= e($p['status']) ?>"><?= e(PJ::STATUS[$p['status']]) ?></span>
      · <?php if (App\Auth::can('crm')): ?><a href="/clientes/<?= (int) $p['client_id'] ?>"><?= e($p['client_name']) ?></a><?php else: ?><?= e($p['client_name']) ?><?php endif; ?>
      <?php if ($p['due_date']): ?>· entrega <?= $late ? '<span class="due due-late">' . e(due($p['due_date'])[0]) . '</span>' : e(date('d/m/Y', strtotime($p['due_date']))) ?><?php endif; ?></p>
  </div>
  <button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#editProject" aria-expanded="false" aria-controls="editProject">Editar projeto</button>
</div>

<form method="post" action="/projetos/<?= $pid ?>" class="collapse card card-body p-4 mb-4" id="editProject">
  <?= csrf_field() ?>
  <div class="row g-3 align-items-end">
    <div class="col-lg-5"><label class="form-label" for="pj-title">Nome do projeto</label><input class="form-control" id="pj-title" name="title" maxlength="160" value="<?= e($p['title']) ?>" required></div>
    <div class="col-sm-6 col-lg-3"><label class="form-label" for="pj-status">Situação</label><select class="form-select" id="pj-status" name="status"><?php foreach (PJ::STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-6 col-lg-3"><label class="form-label" for="pj-man">Responsável</label><select class="form-select" id="pj-man" name="manager_id"><option value="">—</option><?php foreach ($users as $id => $n): ?><option value="<?= (int) $id ?>" <?= (int) $p['manager_id'] === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-1 d-grid"><button class="btn btn-dark">Salvar</button></div>
  </div>
</form>

<div class="row g-4">
  <div class="col-xl-8 d-grid gap-4 align-content-start">

    <section class="card card-body p-4" id="fases" aria-labelledby="h-fases">
      <div class="d-flex justify-content-between align-items-baseline gap-2 mb-3">
        <h2 class="h5 fw-bold mb-0" id="h-fases">Fases</h2>
        <small class="text-body-secondary tnum"><?= $nDone ?> de <?= count($phases) ?> concluídas</small>
      </div>
      <progress class="prog mb-3" max="<?= max(1, count($phases)) ?>" value="<?= $nDone ?>" aria-label="Fases concluídas"></progress>
      <ol class="phase-list">
        <?php foreach ($phases as $ph): $isLate = $ph['status'] !== 'concluida' && $ph['due_date'] && $ph['due_date'] < $today; ?>
        <li class="phase is-<?= e($ph['status']) ?><?= $isLate ? ' is-late' : '' ?>">
          <span class="phase-dot" aria-hidden="true"><?= $ph['status'] === 'concluida' ? icon('check', 'icon icon-sm') : '' ?></span>
          <div class="min-w-0 flex-grow-1">
            <strong class="d-block"><?= e($ph['name']) ?></strong>
            <small class="text-body-secondary"><?= e(PJ::PHASE_STATUS[$ph['status']]) ?>
              <?php if ($ph['status'] === 'concluida' && $ph['done_at']): ?>em <?= e(date('d/m', strtotime($ph['done_at']))) ?>
              <?php elseif ($ph['due_date']): ?>· <span class="due due-<?= $isLate ? 'late' : ($ph['due_date'] === $today ? 'today' : 'soon') ?>"><?= e($isLate ? due($ph['due_date'])[0] : 'até ' . date('d/m', strtotime($ph['due_date']))) ?></span>
              <?php else: ?>· sem prazo<?php endif; ?></small>
          </div>
          <form method="post" action="/fases/<?= (int) $ph['id'] ?>"><?= csrf_field() ?>
            <?php if ($ph['status'] === 'concluida'): ?><button class="btn btn-sm btn-link link-secondary" name="action" value="reopen">Reabrir</button>
            <?php elseif ($cur && (int) $cur['id'] === (int) $ph['id']): ?><button class="btn btn-sm btn-dark" name="action" value="done">Concluir fase</button><?php endif; ?>
          </form>
          <details class="phase-edit">
            <summary class="btn btn-sm btn-link link-secondary">Editar</summary>
            <form method="post" action="/fases/<?= (int) $ph['id'] ?>" class="d-flex flex-wrap gap-2 align-items-end pt-2"><?= csrf_field() ?>
              <div class="flex-grow-1"><label class="form-label small" for="ph-n-<?= (int) $ph['id'] ?>">Nome</label><input class="form-control form-control-sm" id="ph-n-<?= (int) $ph['id'] ?>" name="name" maxlength="120" value="<?= e($ph['name']) ?>"></div>
              <div><label class="form-label small" for="ph-d-<?= (int) $ph['id'] ?>">Prazo</label><input class="form-control form-control-sm" type="date" id="ph-d-<?= (int) $ph['id'] ?>" name="due_date" value="<?= e((string) $ph['due_date']) ?>"></div>
              <button class="btn btn-sm btn-dark" name="action" value="save">Salvar</button>
              <button class="btn btn-sm btn-outline-dark" name="action" value="delete" formnovalidate>Excluir fase</button>
            </form>
          </details>
        </li>
        <?php endforeach; ?>
      </ol>
      <form method="post" action="/projetos/<?= $pid ?>/fases" class="d-flex flex-wrap gap-2 align-items-end mt-3 pt-3 border-top"><?= csrf_field() ?>
        <div class="flex-grow-1"><label class="form-label small" for="nf-name">Nova fase</label><input class="form-control form-control-sm" id="nf-name" name="name" maxlength="120" placeholder="Ex.: Projeto legal" required></div>
        <div><label class="form-label small" for="nf-due">Prazo</label><input class="form-control form-control-sm" type="date" id="nf-due" name="due_date"></div>
        <button class="btn btn-sm btn-outline-dark"><?= icon('plus', 'icon icon-sm') ?>Adicionar</button>
      </form>
    </section>

    <section class="card" id="tarefas" aria-labelledby="h-tarefas">
      <div class="d-flex justify-content-between align-items-baseline gap-2 p-4 pb-2">
        <h2 class="h5 fw-bold mb-0" id="h-tarefas">Tarefas</h2>
        <small class="text-body-secondary"><?= count($openTasks) ?> em aberto</small>
      </div>
      <?php if (!$tasks): ?><p class="small text-body-secondary px-4">Nenhuma tarefa ainda. Distribua o trabalho de cada fase abaixo.</p><?php else: ?>
      <ul class="task-list task-list-lg">
        <?php foreach ($tasks as $t): [$dl, $dt] = due($t['due_at']); $blocked = $t['blocked_by'] && !$t['blocker_done'] && !$t['done_at']; ?>
        <li class="<?= $t['done_at'] ? 'is-done' : '' ?>">
          <form method="post" action="/tarefas/<?= (int) $t['id'] ?>/feita"><?= csrf_field() ?><input type="hidden" name="back" value="/projetos/<?= $pid ?>#tarefas"><?php if ($t['done_at']): ?><input type="hidden" name="undo" value="1"><?php endif; ?>
            <button class="task-check" <?= $blocked ? 'disabled title="Espera outra tarefa"' : '' ?> aria-label="<?= $t['done_at'] ? 'Reabrir' : 'Concluir' ?>: <?= e($t['title']) ?>"><?= $t['done_at'] ? icon('check', 'icon icon-sm') : '' ?></button></form>
          <span class="min-w-0 flex-grow-1"><span class="d-block"><?= e($t['title']) ?><?= $t['priority'] === 'alta' ? ' <span class="badge prio-alta">Alta</span>' : '' ?></span>
            <small class="text-body-secondary"><?= e(implode(' · ', array_filter([$t['phase_id'] ? ($phaseName[$t['phase_id']] ?? null) : null, $t['owner_name']]))) ?><?php if ($blocked): ?> · <span class="text-danger">espera: <?= e($t['blocker_title']) ?></span><?php endif; ?></small></span>
          <?php if (!$t['done_at']): ?><span class="due due-<?= $dt ?>"><?= e($dl) ?></span><?php else: ?><small class="text-body-secondary">feita <?= e(date('d/m', strtotime($t['done_at']))) ?></small><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <form method="post" action="/projetos/<?= $pid ?>/tarefas" class="row g-2 align-items-end p-4 pt-3 border-top m-0"><?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small" for="pt-title">Nova tarefa</label><input class="form-control" id="pt-title" name="title" maxlength="200" required></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pt-due">Quando</label><input class="form-control" type="date" id="pt-due" name="due_at" value="<?= e($cur['due_date'] ?? $today) ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pt-prio">Prioridade</label><select class="form-select" id="pt-prio" name="priority"><?php foreach (TC::PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small" for="pt-owner">Para</label><select class="form-select" id="pt-owner" name="owner_id"><?php foreach ($users as $id => $n): ?><option value="<?= (int) $id ?>" <?= (int) $id === App\Auth::id() ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small" for="pt-phase">Fase</label><select class="form-select" id="pt-phase" name="phase_id"><option value="">—</option><?php foreach ($phases as $ph): ?><option value="<?= (int) $ph['id'] ?>" <?= $cur && (int) $cur['id'] === (int) $ph['id'] ? 'selected' : '' ?>><?= e($ph['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small" for="pt-block">Depois de</label><select class="form-select" id="pt-block" name="blocked_by"><option value="">Pode começar já</option><?php foreach ($openTasks as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-dark">Adicionar tarefa</button></div>
      </form>
    </section>

    <section class="card card-body p-4" id="entregas" aria-labelledby="h-entregas">
      <h2 class="h5 fw-bold mb-1" id="h-entregas">Entregáveis</h2>
      <p class="small text-body-secondary mb-3">Pranchas, renders, PDFs e DWG (até 15 MB cada). Um arquivo com o mesmo nome vira uma nova versão.</p>
      <?php if (!$deliverables): ?><p class="small text-body-secondary">Nenhum entregável ainda.</p><?php endif; ?>
      <div class="d-grid gap-3">
        <?php foreach ($deliverables as $d): $fl = $files[$d['id']] ?? []; ?>
        <article class="deliv">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div class="min-w-0"><strong class="d-block"><?= e($d['title']) ?></strong><small class="text-body-secondary"><?= e($d['phase_id'] ? ($phaseName[$d['phase_id']] ?? '') : 'Sem fase') ?> · <?= count($fl) ?> arquivo<?= count($fl) === 1 ? '' : 's' ?></small></div>
            <span class="badge dstatus-<?= e($d['status']) ?>"><?= e(PJ::DELIVERABLE_STATUS[$d['status']]) ?></span>
          </div>
          <?php if ($fl): ?>
          <ul class="file-list mt-2">
            <?php foreach ($fl as $f): ?>
            <li><a href="/entregas/arquivos/<?= (int) $f['id'] ?>" target="_blank" rel="noopener"><?= icon(str_starts_with($f['mime'], 'image/') && $f['mime'] !== 'image/vnd.dwg' ? 'eye' : 'file', 'icon icon-sm') ?><span class="text-truncate"><?= e($f['name']) ?><?= (int) $f['version'] > 1 ? ' (v' . (int) $f['version'] . ')' : '' ?></span></a>
              <small class="text-body-secondary"><?= e(date('d/m H:i', strtotime($f['created_at']))) ?> · <?= number_format($f['size'] / 1048576, 1, ',', '') ?> MB</small>
              <form method="post" action="/entregas/arquivos/<?= (int) $f['id'] ?>/excluir"><?= csrf_field() ?><button class="btn btn-sm btn-link link-secondary p-0" aria-label="Excluir <?= e($f['name']) ?>">Excluir</button></form></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <form method="post" action="/entregas/<?= (int) $d['id'] ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end mt-3"><?= csrf_field() ?>
            <div><label class="form-label small" for="ds-<?= (int) $d['id'] ?>">Situação</label><select class="form-select form-select-sm" id="ds-<?= (int) $d['id'] ?>" name="status"><?php foreach (PJ::DELIVERABLE_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $d['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="flex-grow-1"><label class="form-label small" for="df-<?= (int) $d['id'] ?>">Enviar arquivos</label><input class="form-control form-control-sm" type="file" id="df-<?= (int) $d['id'] ?>" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/heic,application/pdf,.dwg"></div>
            <button class="btn btn-sm btn-dark">Salvar</button>
          </form>
        </article>
        <?php endforeach; ?>
      </div>
      <form method="post" action="/projetos/<?= $pid ?>/entregas" class="d-flex flex-wrap gap-2 align-items-end mt-3 pt-3 border-top"><?= csrf_field() ?>
        <div class="flex-grow-1"><label class="form-label small" for="nd-title">Novo entregável</label><input class="form-control form-control-sm" id="nd-title" name="title" maxlength="160" placeholder="Ex.: Planta de layout" required></div>
        <div><label class="form-label small" for="nd-phase">Fase</label><select class="form-select form-select-sm" id="nd-phase" name="phase_id"><option value="">—</option><?php foreach ($phases as $ph): ?><option value="<?= (int) $ph['id'] ?>" <?= $cur && (int) $cur['id'] === (int) $ph['id'] ? 'selected' : '' ?>><?= e($ph['name']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-sm btn-outline-dark"><?= icon('plus', 'icon icon-sm') ?>Adicionar</button>
      </form>
    </section>
  </div>

  <div class="col-xl-4 d-grid gap-4 align-content-start">
    <section class="card card-body p-4" aria-labelledby="h-resumo">
      <h2 class="h6" id="h-resumo">Resumo</h2>
      <dl class="facts mb-0">
        <dt>Fase atual</dt><dd><?= e($cur['name'] ?? 'Todas concluídas') ?></dd>
        <dt>Responsável</dt><dd><?= e($p['manager_name'] ?? '—') ?></dd>
        <dt>Início</dt><dd><?= e(date('d/m/Y', strtotime($p['start_date']))) ?></dd>
        <dt>Entrega</dt><dd><?= $p['due_date'] ? e(date('d/m/Y', strtotime($p['due_date']))) : 'sem data' ?></dd>
        <dt>Horas</dt><dd class="tnum"><?= e($hm($totalMin)) ?></dd>
        <?php if ($p['proposal_id'] && App\Auth::can('proposals')): ?><dt>Proposta</dt><dd><a href="/propostas/<?= (int) $p['proposal_id'] ?>">#<?= (int) $p['proposal_id'] ?></a></dd><?php endif; ?>
      </dl>
    </section>

    <section class="card card-body p-4" id="horas" aria-labelledby="h-horas">
      <div class="d-flex justify-content-between align-items-baseline"><h2 class="h6 mb-0" id="h-horas">Horas</h2><strong class="tnum"><?= e($hm($totalMin)) ?></strong></div>
      <form method="post" action="/projetos/<?= $pid ?>/horas" class="row g-2 mt-1"><?= csrf_field() ?>
        <div class="col-5"><label class="form-label small" for="h-h">Tempo</label><input class="form-control" id="h-h" name="hours" inputmode="decimal" placeholder="1,5 ou 1:30" required></div>
        <div class="col-7"><label class="form-label small" for="h-d">Dia</label><input class="form-control" type="date" id="h-d" name="date" value="<?= $today ?>" max="<?= $today ?>" required></div>
        <?php if ($openTasks): ?><div class="col-12"><label class="form-label small" for="h-t">Tarefa</label><select class="form-select" id="h-t" name="task_id"><option value="">—</option><?php foreach ($openTasks as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-12"><label class="form-label small" for="h-n">O que foi feito</label><input class="form-control" id="h-n" name="note" maxlength="200"></div>
        <div class="col-12"><button class="btn btn-outline-dark w-100">Lançar horas</button></div>
      </form>
      <?php if ($hours): ?>
      <ul class="hours-list mt-3">
        <?php foreach ($hours as $h): ?><li><span class="min-w-0"><span class="d-block text-truncate"><?= e($h['note'] ?: ($h['task_title'] ?: 'Horas no projeto')) ?></span><small class="text-body-secondary"><?= e(($h['user_name'] ?? '—') . ' · ' . date('d/m', strtotime($h['date']))) ?></small></span><strong class="tnum"><?= e($hm((int) $h['minutes'])) ?></strong></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
