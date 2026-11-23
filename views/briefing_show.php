<?php use App\Briefings; $done = Briefings::answered($questions, $answers); $total = count($questions); $readonly = false; ?>
<a class="small link-secondary" href="/clientes/<?= (int) $c['id'] ?>">← <?= e($c['name']) ?></a>
<div class="page-head mt-1">
  <div><h1 class="h3">Briefing<?= (int) $b['version'] > 1 ? ' <small class="text-body-secondary fs-6">versão ' . (int) $b['version'] . '</small>' : '' ?></h1>
    <p><span class="badge bstatus-<?= e($b['status']) ?>"><?= e(Briefings::STATUS[$b['status']]) ?></span> · <?= e($b['service'] ?? '') ?> · <?= $done ?>/<?= $total ?> respostas</p></div>
  <form method="post" action="/briefings/<?= (int) $b['id'] ?>/enviar" target="_blank"><?= csrf_field() ?><button class="btn btn-success"><?= icon('whatsapp', 'icon icon-sm') ?><?= $b['sent_at'] ? 'Reenviar ao cliente' : 'Enviar ao cliente' ?></button></form>
</div>
<div class="row g-4">
  <div class="col-xl-8">
    <form method="post" action="/briefings/<?= (int) $b['id'] ?>" enctype="multipart/form-data" class="card card-body p-4">
      <?= csrf_field() ?>
      <?php if ($b['status'] === 'validado'): ?><div class="alert alert-warning">O cliente validou esta versão. Se você alterar as respostas e salvar, vira a versão <?= (int) $b['version'] + 1 ?> e será preciso validar de novo.</div><?php endif; ?>
      <?php foreach ($questions as $q) require __DIR__ . '/_briefing_field.php'; ?>
      <div class="mb-3"><label class="form-label" for="b-files">Anexar fotos, medidas ou plantas</label><input class="form-control" type="file" id="b-files" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/heic,application/pdf,.dwg"><div class="form-text">JPG, PNG, WebP, HEIC ou PDF, até 15 MB cada.</div></div>
      <div><button class="btn btn-dark">Salvar briefing</button></div>
    </form>
  </div>
  <div class="col-xl-4 d-grid gap-3 align-content-start">
    <div class="card card-body">
      <h2 class="h6">Link do cliente</h2>
      <input class="form-control form-control-sm mb-2" value="<?= e($url) ?>" readonly aria-label="Link do briefing">
      <p class="small text-body-secondary mb-0"><?= $b['sent_at'] ? 'O cliente responde, anexa arquivos e confirma pelo link.' : 'O link abre depois do primeiro envio.' ?></p>
    </div>
    <div class="card card-body">
      <h2 class="h6">Arquivos (<?= count($files) ?>)</h2>
      <?php if (!$files): ?><p class="small text-body-secondary mb-0">Nenhum arquivo ainda.</p><?php endif; ?>
      <ul class="file-list">
        <?php foreach ($files as $fl): ?>
        <li><a href="/arquivos/<?= (int) $fl['id'] ?>" target="_blank" rel="noopener"><?= icon(str_starts_with($fl['mime'], 'image/') && $fl['mime'] !== 'image/vnd.dwg' ? 'eye' : 'file', 'icon icon-sm') ?><span class="text-truncate"><?= e($fl['name']) ?><?= (int) $fl['version'] > 1 ? ' (v' . (int) $fl['version'] . ')' : '' ?></span></a>
          <small class="text-body-secondary"><?= $fl['from_client'] ? 'cliente' : 'equipe' ?> · <?= number_format($fl['size'] / 1048576, 1, ',', '') ?> MB</small>
          <form method="post" action="/arquivos/<?= (int) $fl['id'] ?>/excluir"><?= csrf_field() ?><button class="btn btn-sm btn-link link-secondary p-0" aria-label="Excluir <?= e($fl['name']) ?>">Excluir</button></form></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="card card-body small">
      <h2 class="h6">Andamento</h2>
      <div>Enviado: <?= $b['sent_at'] ? e(date('d/m H:i', strtotime($b['sent_at']))) : '—' ?></div>
      <div>Respondido: <?= $b['answered_at'] ? e(date('d/m H:i', strtotime($b['answered_at']))) : '—' ?></div>
      <div>Validado: <?= $b['validated_at'] ? e(date('d/m H:i', strtotime($b['validated_at']))) : '—' ?></div>
      <?php foreach ($history as $a): ?><p class="mb-0 mt-2"><b>Validado</b> por <?= e($a['name']) ?> em <?= e(date('d/m/Y H:i', strtotime($a['created_at']))) ?> (v<?= (int) $a['version'] ?>)</p><?php endforeach; ?>
    </div>
  </div>
</div>
