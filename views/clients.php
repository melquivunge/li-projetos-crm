<?php use App\{Auth, Clients}; $qs = fn(array $o) => '?' . http_build_query(array_filter($o + ['q' => $f['q'], 'stage' => $f['stage'], 'owner' => $f['owner'] ?: '', 'atrasadas' => $f['late'] ? 1 : ''])); $all = array_sum($counts); ?>
<div class="page-head">
  <div><h1 class="h3">Clientes</h1><p><?= (int) $total ?> <?= $total === 1 ? 'cliente' : 'clientes' ?> nesta visão</p></div>
  <div class="d-flex flex-wrap gap-2">
    <div class="seg" role="group" aria-label="Visualização"><a class="seg-btn" href="/funil">Funil</a><a class="seg-btn" aria-current="page" href="/clientes">Lista</a></div>
    <a class="btn btn-outline-dark" href="/clientes<?= e($qs(['export' => 'csv'])) ?>">Exportar CSV</a>
    <a class="btn btn-dark" href="/clientes/novo"><?= icon('plus', 'icon icon-sm') ?>Novo cliente</a>
  </div>
</div>

<nav class="tabs mb-3" aria-label="Filtrar por etapa">
  <a class="tab" href="/clientes<?= e($qs(['stage' => null])) ?>"<?= $f['stage'] === '' ? ' aria-current="page"' : '' ?>>Todos <span><?= (int) $all ?></span></a>
  <?php foreach (Clients::STAGES as $k => $l): ?><a class="tab" href="/clientes<?= e($qs(['stage' => $k])) ?>"<?= $f['stage'] === $k ? ' aria-current="page"' : '' ?>><i class="dot-<?= e($k) ?>"></i><?= e($l) ?> <span><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach; ?>
</nav>

<form class="toolbar mb-3" method="get" action="/clientes">
  <?php if ($f['stage']): ?><input type="hidden" name="stage" value="<?= e($f['stage']) ?>"><?php endif; ?>
  <div class="search flex-grow-1"><?= icon('search') ?><label class="visually-hidden" for="f-q">Buscar</label><input class="form-control" id="f-q" name="q" value="<?= e($f['q']) ?>" placeholder="Nome, e-mail, WhatsApp ou cidade" maxlength="100"></div>
  <?php if (Auth::isAdmin()): ?><label class="visually-hidden" for="f-owner">Responsável</label>
  <select class="form-select w-auto" id="f-owner" name="owner"><option value="">Todos os responsáveis</option><?php foreach ($owners as $id => $n): ?><option value="<?= (int) $id ?>" <?= $f['owner'] === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select><?php endif; ?>
  <label class="chip-toggle"><input type="checkbox" name="atrasadas" value="1" <?= $f['late'] ? 'checked' : '' ?> data-autosubmit><span><?= icon('clock', 'icon icon-sm') ?>Tarefa atrasada<?= $late ? " <b>$late</b>" : '' ?></span></label>
  <button class="btn btn-outline-dark">Filtrar</button>
</form>

<?php if (!$clients): ?>
  <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('users') ?></div><p class="mb-3 text-body-secondary">Nenhum cliente nesta visão.</p><div><a class="btn btn-dark" href="/clientes/novo">Cadastrar cliente</a></div></div>
<?php else: ?>
<div class="card table-card d-none d-md-block"><div class="table-responsive"><table class="table table-hover align-middle mb-0 rows-link">
  <thead><tr><th>Cliente</th><th>Projeto</th><th>Etapa</th><th>Próxima tarefa</th><th>Origem</th><?php if (Auth::isAdmin()): ?><th class="text-center">Resp.</th><?php endif; ?><th class="text-end">Atualizado</th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): [$dl, $dt] = due($c['next_action_at']); $open = !in_array($c['stage'], ['ganho', 'perdido'], true); ?>
    <tr>
      <td><a class="row-link d-flex align-items-center gap-3" href="/clientes/<?= (int) $c['id'] ?>"><?= avatar(['name' => $c['name']], 36) ?><span class="min-w-0"><strong class="d-block text-truncate"><?= e($c['name']) ?></strong><small class="text-body-secondary"><?= e($c['city'] ?: ($c['whatsapp'] ?? $c['email'] ?? '')) ?></small></span></a></td>
      <td><span class="d-block"><?= e($c['service'] ?? '—') ?></span><small class="text-body-secondary tnum"><?= e(area($c['area_m2'])) ?></small></td>
      <td><span class="badge stage-<?= e($c['stage']) ?>"><?= e(Clients::STAGES[$c['stage']]) ?></span></td>
      <td><?php if ($c['next_action'] && $open): ?><span class="d-block text-truncate mw-220"><?= e($c['next_action']) ?></span><span class="due due-<?= $dt ?>"><?= e($dl) ?></span><?php elseif ($open): ?><span class="text-body-secondary small">Sem tarefa</span><?php else: ?><span class="text-body-secondary small">—</span><?php endif; ?></td>
      <td><span class="origin"><?= e($c['origin'] ?? '—') ?></span></td>
      <?php if (Auth::isAdmin()): ?><td class="text-center"><?= $c['owner_id'] ? avatar(['id' => $c['owner_id'], 'name' => $c['owner_name'], 'avatar' => $c['owner_avatar']], 28) : '<span class="text-body-secondary">—</span>' ?></td><?php endif; ?>
      <td class="text-end small text-body-secondary text-nowrap"><?= days_since($c['updated_at']) === 0 ? 'hoje' : 'há ' . days_since($c['updated_at']) . ' d' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<div class="d-md-none d-grid gap-2">
  <?php foreach ($clients as $c): [$dl, $dt] = due($c['next_action_at']); ?>
  <a class="card card-body mobile-row" href="/clientes/<?= (int) $c['id'] ?>">
    <div class="d-flex align-items-center gap-3"><?= avatar(['name' => $c['name']], 40) ?><div class="min-w-0 flex-grow-1"><strong class="d-block text-truncate"><?= e($c['name']) ?></strong><small class="text-body-secondary"><?= e(trim(($c['service'] ?? '') . ' · ' . area($c['area_m2']), ' ·')) ?></small></div><span class="badge stage-<?= e($c['stage']) ?>"><?= e(Clients::STAGES[$c['stage']]) ?></span></div>
    <?php if ($c['next_action'] && !in_array($c['stage'], ['ganho', 'perdido'], true)): ?><div class="small mt-2 d-flex gap-2 align-items-center"><?= icon('clock', 'icon icon-sm') ?><span class="text-truncate"><?= e($c['next_action']) ?></span><span class="due due-<?= $dt ?> ms-auto"><?= e($dl) ?></span></div><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Páginas"><ul class="pagination">
  <?php for ($i = 1; $i <= $pages; $i++): ?><li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="/clientes<?= e($qs(['p' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
</ul></nav>
<?php endif; endif; ?>
