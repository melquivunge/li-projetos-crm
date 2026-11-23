<?php
use App\Projects as PJ; use App\Controllers\ProjectController as PC;
$q = fn(array $over) => '/projetos?' . http_build_query(array_filter($over + ['ver' => $view, 'status' => $done ? 'encerrados' : null]));
$today = date('Y-m-d');
$late = fn(array $r) => $r['status'] === 'ativo' && $r['due_date'] && $r['due_date'] < $today;
$months = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$wd = ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
?>
<div class="page-head">
  <div><h1 class="h3">Projetos</h1>
    <p><?= (int) $counts['abertos'] ?> em andamento<?php if ((int) $counts['atrasados']): ?> · <span class="text-danger fw-semibold"><?= (int) $counts['atrasados'] ?> com prazo vencido</span><?php endif; ?>. Um projeto nasce quando o cliente aceita a proposta.</p></div>
  <div class="seg" role="group" aria-label="Visualização">
    <?php foreach (PC::VIEWS as $k => $l): ?><a class="seg-btn" href="<?= e($q(['ver' => $k])) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
  </div>
</div>

<?php if ($view !== 'calendario'): ?>
<nav class="tabs mb-3" aria-label="Situação">
  <a class="tab" href="<?= e($q(['status' => null])) ?>"<?= !$done ? ' aria-current="page"' : '' ?>>Em andamento <span><?= (int) $counts['abertos'] ?></span></a>
  <a class="tab" href="<?= e($q(['status' => 'encerrados'])) ?>"<?= $done ? ' aria-current="page"' : '' ?>>Encerrados <span><?= (int) $counts['encerrados'] ?></span></a>
</nav>
<?php endif; ?>

<?php if ($view === 'lista'): ?>
  <?php if (!$rows): ?>
    <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('folder') ?></div>
      <p class="mb-1 fw-semibold"><?= $done ? 'Nenhum projeto encerrado.' : 'Nenhum projeto em andamento.' ?></p>
      <p class="mb-0 small text-body-secondary">Quando uma proposta for aceita, o projeto aparece aqui com as fases do cronograma.</p></div>
  <?php else: ?>
  <div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Projeto</th><th>Fase atual</th><th class="d-none d-md-table-cell">Progresso</th><th>Entrega</th><th class="d-none d-lg-table-cell">Responsável</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$dl] = due($r['due_date']); ?>
      <tr>
        <td class="min-w-0"><a class="link-dark fw-semibold text-decoration-none" href="/projetos/<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a>
          <small class="d-block text-body-secondary"><?= e($r['client_name']) ?><?= $r['status'] === 'pausado' ? ' · <span class="badge pstatus-pausado">Pausado</span>' : '' ?><?php if ((int) $r['late_tasks']): ?> · <span class="text-danger"><?= (int) $r['late_tasks'] ?> tarefa<?= (int) $r['late_tasks'] > 1 ? 's atrasadas' : ' atrasada' ?></span><?php endif; ?></small></td>
        <td><?= e($r['phase_name'] ?? 'Todas concluídas') ?><?php if ($r['phase_due'] && !$done): [$pl, $pt] = due($r['phase_due']); ?><small class="d-block mt-1"><span class="due due-<?= $pt ?>"><?= e($pl) ?></span></small><?php endif; ?></td>
        <td class="d-none d-md-table-cell"><progress class="prog" max="<?= max(1, (int) $r['n_phases']) ?>" value="<?= (int) $r['n_done'] ?>" aria-label="Fases concluídas"></progress><small class="text-body-secondary tnum"><?= (int) $r['n_done'] ?> de <?= (int) $r['n_phases'] ?> fases</small></td>
        <td><?php if ($r['status'] === 'concluido'): ?><small class="text-body-secondary">entregue <?= e(date('d/m/Y', strtotime((string) ($r['done_at'] ?? $r['updated_at'])))) ?></small>
          <?php elseif ($r['due_date']): ?><span class="due due-<?= $late($r) ? 'late' : 'soon' ?>"><?= e($late($r) ? $dl : date('d/m/Y', strtotime($r['due_date']))) ?></span>
          <?php else: ?><small class="text-body-secondary">sem data</small><?php endif; ?></td>
        <td class="d-none d-lg-table-cell"><?php if ($r['manager_id']): ?><span class="d-inline-flex align-items-center gap-2"><?= avatar(['id' => $r['manager_id'], 'name' => $r['manager_name'], 'avatar' => $r['manager_avatar']], 28) ?><small><?= e($r['manager_name']) ?></small></span><?php else: ?><small class="text-body-secondary">—</small><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>

<?php elseif ($view === 'kanban'):
  $cols = array_fill_keys([...PJ::DEFAULT_PHASES, PC::ALL_DONE], []);
  foreach ($rows as $r) $cols[$r['phase_name'] ?? PC::ALL_DONE][] = $r; ?>
  <p class="small text-body-secondary mb-2">Arraste um projeto para outra fase: as fases anteriores são concluídas e, ao voltar, as seguintes são reabertas.</p>
  <p id="boardMsg" class="alert alert-danger d-none" role="alert"></p>
  <div class="board" data-csrf="<?= e(App\Csrf::token()) ?>" data-url="/projetos/{id}/fase">
    <?php foreach ($cols as $name => $items): ?>
    <section class="col-stage" data-stage="<?= e($name) ?>" aria-label="<?= e($name) ?>">
      <header><h2><?= e($name) ?> <span class="n"><?= count($items) ?></span></h2></header>
      <div class="col-cards" data-drop>
        <?php foreach ($items as $r): ?>
        <article class="deal<?= $late($r) ? ' is-late' : '' ?>" draggable="true" data-id="<?= (int) $r['id'] ?>">
          <a class="stretched" href="/projetos/<?= (int) $r['id'] ?>"><strong><?= e($r['title']) ?></strong></a>
          <p class="meta"><?= e($r['client_name']) ?><?= $r['status'] === 'pausado' ? ' · pausado' : '' ?></p>
          <progress class="prog mb-2" max="<?= max(1, (int) $r['n_phases']) ?>" value="<?= (int) $r['n_done'] ?>" aria-label="Fases concluídas"></progress>
          <footer>
            <?php if ($r['phase_due'] && !$done): [$pl, $pt] = due($r['phase_due']); ?><span class="due due-<?= $pt ?>" title="Prazo da fase"><?= e($pl) ?></span><?php endif; ?>
            <?php if ((int) $r['late_tasks']): ?><span class="since text-danger" title="Tarefas atrasadas"><?= (int) $r['late_tasks'] ?> atrasada<?= (int) $r['late_tasks'] > 1 ? 's' : '' ?></span><?php else: ?><span class="since tnum" title="Fases concluídas"><?= (int) $r['n_done'] ?>/<?= (int) $r['n_phases'] ?></span><?php endif; ?>
            <?= $r['manager_id'] ? avatar(['id' => $r['manager_id'], 'name' => $r['manager_name'], 'avatar' => $r['manager_avatar']], 28) : '' ?>
          </footer>
        </article>
        <?php endforeach; ?>
        <?php if (!$items): ?><p class="empty">Nenhum projeto</p><?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

<?php else:
  $t = strtotime("$month-01"); $prev = date('Y-m', strtotime('-1 month', $t)); $next = date('Y-m', strtotime('+1 month', $t)); ?>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h2 class="h5 mb-0"><span class="text-capitalize"><?= e($months[(int) date('n', $t)]) ?></span> de <?= date('Y', $t) ?></h2>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-dark" href="<?= e($q(['mes' => $prev])) ?>" aria-label="Mês anterior"><?= icon('chevron', 'icon icon-sm rot-l') ?></a>
      <a class="btn btn-sm btn-outline-dark" href="<?= e($q(['mes' => null])) ?>">Hoje</a>
      <a class="btn btn-sm btn-outline-dark" href="<?= e($q(['mes' => $next])) ?>" aria-label="Próximo mês"><?= icon('chevron', 'icon icon-sm rot-r') ?></a>
    </div>
  </div>
  <div class="cal card" aria-label="Prazos de fases e tarefas">
    <div class="cal-row cal-head" aria-hidden="true"><?php foreach (array_slice($wd, 1) as $d): ?><div><?= $d ?></div><?php endforeach; ?></div>
    <?php foreach ($grid as $week): ?>
    <div class="cal-row">
      <?php foreach ($week as $d): $ev = $events[$d] ?? []; $out = substr($d, 0, 7) !== $month; ?>
      <div class="cal-day<?= $out ? ' is-out' : '' ?><?= $d === $today ? ' is-today' : '' ?><?= $ev ? ' has-ev' : '' ?>">
        <span class="cal-n"><span class="cal-wd"><?= $wd[(int) date('N', strtotime($d))] ?> </span><?= (int) substr($d, 8) ?></span>
        <?php foreach (array_slice($ev, 0, 3) as $e): ?>
          <a class="cal-ev cal-<?= $e['kind'] ?><?= $e['done'] ? ' is-done' : (!$e['done'] && $d < $today ? ' is-late' : '') ?>" href="<?= e($e['href']) ?>" title="<?= e(($e['kind'] === 'phase' ? 'Fim da fase: ' : 'Tarefa: ') . $e['label']) ?>"><?= e($e['label']) ?></a>
        <?php endforeach; ?>
        <?php if (count($ev) > 3): ?><small class="cal-more">+<?= count($ev) - 3 ?></small><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="small text-body-secondary mt-2 d-flex gap-3 flex-wrap"><span><i class="cal-key cal-phase"></i>Fim de fase</span><span><i class="cal-key cal-task"></i>Tarefa</span><span><i class="cal-key is-late"></i>Atrasado</span></p>
<?php endif; ?>
