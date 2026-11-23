<?php use App\Controllers\ProposalController as P; $all = array_sum($counts); ?>
<div class="page-head"><div><h1 class="h3">Propostas</h1><p>Para criar uma proposta, abra o cliente e use "Nova proposta".</p></div></div>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100 stat"><div class="card-body"><div class="label">Em aberto</div><div class="value tnum"><?= e(money((float) $sum['open_v'])) ?></div><div class="hint"><?= (int) (($counts['enviada'] ?? 0) + ($counts['vista'] ?? 0)) ?> enviadas ou vistas</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100 stat"><div class="card-body"><div class="label">Aceitas</div><div class="value tnum"><?= e(money((float) $sum['won_v'])) ?></div><div class="hint"><?= (int) ($counts['aceita'] ?? 0) ?> propostas</div></div></div></div>
</div>
<nav class="tabs mb-3" aria-label="Filtrar por status">
  <a class="tab" href="/propostas"<?= $status === '' ? ' aria-current="page"' : '' ?>>Todas <span><?= (int) $all ?></span></a>
  <?php foreach (P::STATUS as $k => $l): ?><a class="tab" href="/propostas?status=<?= e($k) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?> <span><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach; ?>
</nav>
<?php if (!$rows): ?>
  <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('file') ?></div><p class="mb-0 text-body-secondary">Nenhuma proposta aqui. Abra um cliente e clique em "Nova proposta".</p></div>
<?php else: ?>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Proposta</th><th>Cliente</th><th>Status</th><th class="text-end">Total</th><th>Validade</th><th>Enviada</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): $expired = in_array($p['status'], ['enviada', 'vista'], true) && $p['valid_until'] < date('Y-m-d'); ?>
    <tr>
      <td><a class="row-link fw-semibold" href="/propostas/<?= (int) $p['id'] ?>">#<?= (int) $p['id'] ?> · <?= e($p['title']) ?></a></td>
      <td><a class="link-dark" href="/clientes/<?= (int) $p['client_id'] ?>"><?= e($p['client_name']) ?></a></td>
      <td><span class="badge status-<?= e($p['status']) ?>"><?= e(P::STATUS[$p['status']]) ?></span><?= $expired ? ' <span class="due due-late">vencida</span>' : '' ?></td>
      <td class="text-end tnum fw-semibold"><?= e(money((float) $p['total'])) ?></td>
      <td class="small tnum"><?= e(date('d/m/Y', strtotime($p['valid_until']))) ?></td>
      <td class="small text-body-secondary"><?= $p['sent_at'] ? e(date('d/m', strtotime($p['sent_at']))) . ($p['viewed_at'] ? ' · vista' : '') : 'rascunho' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
