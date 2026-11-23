<?php use App\Controllers\ProposalController as P; ?>
<a class="small link-secondary" href="/clientes/<?= (int) $c['id'] ?>">← <?= e($c['name']) ?></a>
<div class="page-head mt-1">
  <div><h1 class="h3">Proposta #<?= (int) $p['id'] ?><?= (int) $p['version'] > 1 ? ' <small class="text-body-secondary fs-6">versão ' . (int) $p['version'] . '</small>' : '' ?></h1><p><span class="badge status-<?= e($p['status']) ?>"><?= e(P::STATUS[$p['status']]) ?></span> · válida até <?= e(date('d/m/Y', strtotime($p['valid_until']))) ?><?php if ($p['status'] === 'aceita' && ($pj = App\Db::q('SELECT id FROM projects WHERE proposal_id = ?', [$p['id']])->fetchColumn()) && App\Auth::can('projects')): ?> · <a href="/projetos/<?= (int) $pj ?>">Ver projeto #<?= (int) $pj ?></a><?php endif; ?></p></div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (!in_array($p['status'], ['aceita', 'recusada', 'substituida'], true)): ?><a class="btn btn-outline-dark" href="/propostas/<?= (int) $p['id'] ?>/editar">Editar</a><?php endif; ?>
    <a class="btn btn-outline-dark" href="/propostas/<?= (int) $p['id'] ?>/pdf" target="_blank" rel="noopener"><?= icon('file', 'icon icon-sm') ?>PDF</a>
    <form method="post" action="/propostas/<?= (int) $p['id'] ?>/email"><?= csrf_field() ?><button class="btn btn-outline-dark" <?= $c['email'] ? '' : 'disabled title="Cliente sem e-mail"' ?>>Enviar por e-mail</button></form>
    <form method="post" action="/propostas/<?= (int) $p['id'] ?>/whatsapp" target="_blank"><?= csrf_field() ?><button class="btn btn-success" <?= $c['whatsapp'] ? '' : 'disabled title="Cliente sem WhatsApp"' ?>><?= icon('whatsapp', 'icon icon-sm') ?>Enviar por WhatsApp</button></form>
  </div>
</div>
<div class="row g-4">
  <div class="col-xl-8"><p class="small text-body-secondary mb-2">É assim que o cliente vê a proposta:</p><div class="doc-frame"><?php require __DIR__ . '/_proposal_doc.php'; ?></div></div>
  <div class="col-xl-4 d-grid gap-3 align-content-start">
    <div class="card card-body">
      <h2 class="h6">Link do cliente</h2>
      <input class="form-control form-control-sm mb-2" value="<?= e($url) ?>" readonly aria-label="Link público da proposta">
      <p class="small text-body-secondary mb-0"><?= $p['status'] === 'rascunho' ? 'O link abre depois do primeiro envio.' : 'O cliente vê, baixa e responde por aqui.' ?></p>
    </div>
    <div class="card card-body">
      <h2 class="h6">Andamento</h2>
      <ol class="timeline-mini">
        <li class="done">Criada <span><?= e(date('d/m H:i', strtotime($p['created_at']))) ?></span></li>
        <li class="<?= $p['sent_at'] ? 'done' : '' ?>">Enviada <span><?= $p['sent_at'] ? e(date('d/m H:i', strtotime($p['sent_at']))) : '—' ?></span></li>
        <li class="<?= $p['viewed_at'] ? 'done' : '' ?>">Vista pelo cliente <span><?= $p['viewed_at'] ? e(date('d/m H:i', strtotime($p['viewed_at']))) : '—' ?></span></li>
        <li class="<?= $p['decided_at'] ? 'done' : '' ?>"><?= $p['status'] === 'recusada' ? 'Recusada' : 'Aceita' ?> <span><?= $p['decided_at'] ? e(date('d/m H:i', strtotime($p['decided_at']))) : '—' ?></span></li>
      </ol>
      <?php foreach ($p['history'] as $a): ?><p class="small mb-1 mt-2"><b><?= e(ucfirst($a['decision'])) ?></b> por <?= e($a['name']) ?> em <?= e(date('d/m/Y H:i', strtotime($a['created_at']))) ?> (v<?= (int) $a['version'] ?>, IP <?= e($a['ip']) ?>)</p><?php endforeach; ?>
      <?php if ($p['status'] === 'substituida'): ?><p class="small text-body-secondary mt-2 mb-0">Esta versão foi substituída por uma mais nova.</p><?php endif; ?>
      <?php if (!in_array($p['status'], ['aceita', 'recusada', 'substituida'], true)): ?>
      <div class="d-flex gap-2 mt-2">
        <form method="post" action="/propostas/<?= (int) $p['id'] ?>/status" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="status" value="aceita"><button class="btn btn-sm btn-outline-dark w-100">Marcar aceita</button></form>
        <form method="post" action="/propostas/<?= (int) $p['id'] ?>/status" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="status" value="recusada"><button class="btn btn-sm btn-outline-dark w-100">Marcar recusada</button></form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
