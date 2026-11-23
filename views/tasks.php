<?php use App\Auth; use App\Controllers\TaskController as TC; $base = '/tarefas?' . ($mine ? '' : 'de=todos&'); ?>
<div class="page-head">
  <div><h1 class="h3">Tarefas</h1><p><?= $mine ? 'As suas tarefas.' : 'Tarefas de toda a equipe.' ?></p></div>
  <?php if (Auth::isAdmin()): ?><div class="seg" role="group" aria-label="De quem"><a class="seg-btn" href="/tarefas"<?= $mine ? ' aria-current="page"' : '' ?>>Minhas</a><a class="seg-btn" href="/tarefas?de=todos"<?= !$mine ? ' aria-current="page"' : '' ?>>Equipe</a></div><?php endif; ?>
</div>
<div class="row g-4">
  <div class="col-xl-8">
    <nav class="tabs mb-3" aria-label="Filtrar">
      <?php foreach (TC::VIEWS as $k => $l): ?><a class="tab" href="<?= e($base . 'ver=' . $k) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?> <span><?= (int) $counts[$k] ?></span></a><?php endforeach; ?>
    </nav>
    <?php if (!$rows): ?>
      <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('check') ?></div><p class="mb-0 text-body-secondary"><?= $view === 'atrasadas' ? 'Nada atrasado. Ótimo.' : ($view === 'feitas' ? 'Nenhuma tarefa concluída nos últimos 7 dias.' : 'Nenhuma tarefa aqui.') ?></p></div>
    <?php else: ?>
    <div class="card"><ul class="task-list task-list-lg">
      <?php foreach ($rows as $t): [$dl, $dt] = due($t['due_at']); ?>
      <li class="<?= $t['done_at'] ? 'is-done' : '' ?>">
        <form method="post" action="/tarefas/<?= (int) $t['id'] ?>/feita"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/tarefas') ?>"><?php if ($t['done_at']): ?><input type="hidden" name="undo" value="1"><?php endif; ?>
          <button class="task-check" aria-label="<?= $t['done_at'] ? 'Reabrir' : 'Concluir' ?>: <?= e($t['title']) ?>"><?= $t['done_at'] ? icon('check', 'icon icon-sm') : '' ?></button></form>
        <span class="min-w-0 flex-grow-1"><span class="d-block"><?= e($t['title']) ?><?= $t['priority'] === 'alta' ? ' <span class="badge prio-alta">Alta</span>' : '' ?></span>
          <small class="text-body-secondary"><?php if ($t['client_id']): ?><a class="link-secondary" href="/clientes/<?= (int) $t['client_id'] ?>"><?= e($t['client_name']) ?></a> · <?php endif; ?><?php if ($t['project_id']): ?><a class="link-secondary" href="/projetos/<?= (int) $t['project_id'] ?>#tarefas"><?= e($t['project_title']) ?></a> · <?php endif; ?><?= !$mine ? e($t['owner_name'] ?? '') : '' ?></small></span>
        <?php if (!$t['done_at']): ?><span class="due due-<?= $dt ?>"><?= e($dl) ?></span><?php else: ?><small class="text-body-secondary">feita <?= e(date('d/m', strtotime($t['done_at']))) ?></small><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
  </div>
  <div class="col-xl-4">
    <form method="post" action="/tarefas" class="card card-body p-4 d-grid gap-2">
      <?= csrf_field() ?>
      <h2 class="h6">Nova tarefa</h2>
      <label class="form-label mb-0" for="nt-title">O que fazer</label><input class="form-control" id="nt-title" name="title" maxlength="200" required>
      <div class="row g-2"><div class="col-7"><label class="form-label" for="nt-due">Quando</label><input class="form-control" type="date" id="nt-due" name="due_at" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-5"><label class="form-label" for="nt-prio">Prioridade</label><select class="form-select" id="nt-prio" name="priority"><?php foreach (TC::PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
      <?php if (Auth::isAdmin()): ?><label class="form-label mb-0" for="nt-owner">Para</label><select class="form-select" id="nt-owner" name="owner_id"><?php foreach ($owners as $id => $n): ?><option value="<?= (int) $id ?>" <?= (int) $id === Auth::id() ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select><?php endif; ?>
      <p class="small text-body-secondary mb-1">Tarefas de um cliente específico são criadas na ficha dele.</p>
      <button class="btn btn-dark">Adicionar</button>
    </form>
  </div>
</div>
