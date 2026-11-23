<?php $ago = fn(string $ts) => date('d/m/Y H:i', strtotime($ts)); ?>
<div class="page-head">
  <div><h1 class="h3">Notificações</h1><p>Leads novos e respostas de propostas.</p></div>
  <?php if (array_filter($items, fn($n) => !$n['read_at'])): ?><form method="post" action="/notificacoes/lidas"><?= csrf_field() ?><button class="btn btn-outline-dark"><?= icon('check', 'icon icon-sm') ?>Marcar todas como lidas</button></form><?php endif; ?>
</div>
<?php if (!$items): ?>
  <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('inbox') ?></div><p class="mb-0 text-body-secondary">Nada por aqui ainda. Quando um lead chegar pelo site ou um cliente responder a uma proposta, você vê aqui.</p></div>
<?php else: ?>
<div class="card"><div class="list-group list-group-flush rounded-4">
  <?php foreach ($items as $n): ?>
  <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3" href="/notificacoes/<?= (int) $n['id'] ?>">
    <span class="stat"><span class="ico"><?= icon(str_contains($n['body'], 'proposta') ? 'file' : 'users', 'icon icon-sm') ?></span></span>
    <span class="flex-grow-1"><span class="d-block<?= $n['read_at'] ? '' : ' fw-semibold' ?>"><?= e($n['body']) ?></span><small class="text-body-secondary"><?= e($ago($n['created_at'])) ?></small></span>
    <?php if (!$n['read_at']): ?><span class="badge text-bg-dark align-self-center">Nova</span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
