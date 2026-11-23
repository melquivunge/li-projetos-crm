<?php use App\{Auth, Opportunities}; ?>
<div class="page-head">
  <div><h1 class="h3">Funil</h1><p>Cada cartão é uma oportunidade. Arraste para a próxima etapa. Ganhos e perdidos dos últimos 30 dias.</p></div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (Auth::isAdmin()): ?><form method="get" action="/funil"><label class="visually-hidden" for="b-owner">Responsável</label><select class="form-select w-auto" id="b-owner" name="owner" data-autosubmit><option value="">Toda a equipe</option><?php foreach ($owners as $id => $n): ?><option value="<?= (int) $id ?>" <?= $owner === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></form><?php endif; ?>
    <div class="seg" role="group" aria-label="Visualização"><a class="seg-btn" aria-current="page" href="/funil">Funil</a><a class="seg-btn" href="/clientes">Lista</a></div>
    <a class="btn btn-dark" href="/clientes/novo"><?= icon('plus', 'icon icon-sm') ?>Novo cliente</a>
  </div>
</div>
<p id="boardMsg" class="alert alert-danger d-none" role="alert"></p>
<div class="board" data-csrf="<?= e(App\Csrf::token()) ?>">
  <?php foreach (Opportunities::STAGES as $k => $label): $items = $cols[$k]; $sum = array_sum(array_map(fn($r) => (float) $r['value'], $items)); ?>
  <section class="col-stage" data-stage="<?= e($k) ?>" aria-labelledby="h-<?= e($k) ?>">
    <header><h2 id="h-<?= e($k) ?>"><i class="dot-<?= e($k) ?>"></i><?= e($label) ?> <span class="n"><?= count($items) ?></span></h2><?php if ($sum > 0): ?><small class="tnum"><?= e(money($sum)) ?></small><?php endif; ?></header>
    <div class="col-cards" data-drop>
      <?php foreach ($items as $c): [$dl, $dt] = due($c['next_action_at']); ?>
      <article class="deal" draggable="true" data-id="<?= (int) $c['id'] ?>">
        <a class="stretched" href="/clientes/<?= (int) $c['client_id'] ?>"><strong><?= e($c['name']) ?></strong></a>
        <p class="meta"><?= e(trim($c['title'] . ' · ' . area($c['area_m2']), ' ·')) ?></p>
        <?php if ($c['value']): ?><p class="val tnum"><?= e(money((float) $c['value'])) ?></p><?php endif; ?>
        <?php if ($c['next_action'] && !in_array($k, ['ganho', 'perdido'], true)): ?><p class="act"><?= icon('clock', 'icon icon-sm') ?><span class="text-truncate"><?= e($c['next_action']) ?></span><span class="due due-<?= $dt ?>"><?= e($dl) ?></span></p><?php endif; ?>
        <footer><span class="origin"><?= e($c['origin'] ?? '—') ?></span><span class="since" title="Tempo nesta etapa"><?= days_since($c['since']) ?> d</span><?= $c['owner_id'] ? avatar(['id' => $c['owner_id'], 'name' => $c['owner_name'], 'avatar' => $c['owner_avatar']], 28) : '' ?></footer>
      </article>
      <?php endforeach; ?>
      <?php if (!$items): ?><p class="empty">Nenhuma oportunidade</p><?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<dialog id="lostDialog" class="lost-dialog" aria-labelledby="lostTitle">
  <form method="dialog" class="p-4">
    <h2 class="h5" id="lostTitle">Por que perdemos este cliente?</h2>
    <label class="form-label" for="lostReason">Motivo</label>
    <input class="form-control mb-3" id="lostReason" maxlength="255" placeholder="Ex.: preço, adiou a obra, fechou com outro" required>
    <div class="d-flex gap-2 justify-content-end"><button class="btn btn-outline-dark" value="cancel">Cancelar</button><button class="btn btn-dark" value="ok">Marcar como perdido</button></div>
  </form>
</dialog>
