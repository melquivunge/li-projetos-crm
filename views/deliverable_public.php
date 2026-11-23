<?php
$first = explode(' ', trim($c['name']))[0];
$inc = (int) $p['revisions_included'];
$approved = $d['status'] === 'aprovado';
$left = max(0, $inc - $used);
?>
<div class="doc-page">
  <article class="doc">
    <header class="doc-cover doc-cover-sm">
      <img class="doc-cover-img" src="/assets/login-render.webp" alt="" width="1200" height="900">
      <div class="doc-cover-in">
        <img src="<?= e(App\Brand::logoUrl(true)) ?>" alt="<?= e(App\Brand::name()) ?>" height="40" class="doc-logo">
        <div><h1 class="doc-title"><?= e($d['title']) ?></h1><p class="doc-for"><?= e($first) ?>, este é o material do seu projeto <?= e($p['title']) ?> para você ver.</p></div>
      </div>
    </header>

    <?php if ($msg): ?><section class="doc-sec pb-0"><div class="alert alert-<?= e($msg[0]) ?> mb-0" role="alert"><?= e($msg[1]) ?></div></section><?php endif; ?>
    <?php if ($approved && $approval): ?>
      <section class="doc-sec"><p class="doc-state ok mb-0"><?= icon('check') ?>Aprovado por <?= e($approval['name']) ?> em <?= e(date('d/m/Y', strtotime($approval['created_at']))) ?>. Obrigada!</p></section>
    <?php endif; ?>

    <section class="doc-sec">
      <h2>Arquivos</h2>
      <?php if ($latest && !$paid): ?><p class="small text-body-secondary">As imagens aparecem como prévia, suficiente para aprovar. Os arquivos originais (alta resolução, PDF e DWG) são liberados quando o pagamento do projeto estiver completo.</p><?php endif; ?>
      <?php if (!$latest): ?><p class="text-body-secondary">Os arquivos ainda não estão disponíveis.</p><?php endif; ?>
      <div class="deliv-gallery">
        <?php foreach ($latest as $f): $url = '/e/' . e($token) . '/arquivos/' . (int) $f['id']; ?>
          <?php if (in_array($f['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)): ?>
            <a class="deliv-img" href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= $url ?>" alt="<?= e($f['name']) ?>" loading="lazy"><span><?= e($f['name']) ?><?= (int) $f['version'] > 1 ? ' · versão ' . (int) $f['version'] : '' ?></span></a>
          <?php elseif (!$paid): ?>
              <div class="deliv-doc is-locked"><?= icon('shield') ?><span><strong><?= e($f['name']) ?></strong><small>Liberado após a quitação</small></span></div>
          <?php else: ?>
            <a class="deliv-doc" href="<?= $url ?><?= $f['mime'] === 'application/pdf' ? '' : '?baixar=1' ?>" target="_blank" rel="noopener"><?= icon('file') ?><span><strong><?= e($f['name']) ?></strong><small><?= $f['mime'] === 'application/pdf' ? 'Abrir PDF' : 'Baixar' ?><?= (int) $f['version'] > 1 ? ' · versão ' . (int) $f['version'] : '' ?></small></span></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>

    <?php foreach ($pending as $r): ?>
    <section class="doc-sec" id="custo-<?= (int) $r['id'] ?>">
      <h2>Ajuste com custo extra</h2>
      <div class="doc-box">
        <p class="mb-2"><?= nl2br(e($r['description'])) ?></p>
        <p class="mb-3"><?= $r['type'] === 'escopo' ? 'Este pedido muda o escopo combinado' : 'Este pedido passa das ' . $inc . ' rodadas de revisão incluídas nesta etapa' ?>. Valor: <strong><?= e(money((float) $r['extra_cost'])) ?></strong>, cobrado em uma parcela extra.</p>
        <form method="post" action="/e/<?= e($token) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_cost"><input type="hidden" name="revision_id" value="<?= (int) $r['id'] ?>">
          <label class="form-label" for="cs-<?= (int) $r['id'] ?>">Seu nome completo</label><input class="form-control mb-2" id="cs-<?= (int) $r['id'] ?>" name="signer" maxlength="160" autocomplete="name" required>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="ca-<?= (int) $r['id'] ?>" name="agree" value="1" required><label class="form-check-label" for="ca-<?= (int) $r['id'] ?>">Concordo com o valor de <?= e(money((float) $r['extra_cost'])) ?> para este ajuste</label></div>
          <button class="btn btn-dark">Aprovar o valor</button>
        </form>
      </div>
    </section>
    <?php endforeach; ?>

    <?php if (!$approved): ?>
    <section class="doc-sec" id="responder">
      <h2>O que você achou?</h2>
      <?php if ($open): ?><p class="small text-body-secondary">Você já pediu ajustes em <?= count($open) ?> ponto<?= count($open) > 1 ? 's' : '' ?> que a equipe está vendo.</p><?php endif; ?>
      <div class="row g-4">
        <div class="col-md-6">
          <form method="post" action="/e/<?= e($token) ?>" class="doc-box h-100 d-grid gap-2 align-content-start"><?= csrf_field() ?><input type="hidden" name="action" value="approve">
            <h3 class="h6 mb-0">Está tudo certo</h3>
            <label class="form-label mb-0" for="ap-name">Seu nome completo</label><input class="form-control" id="ap-name" name="signer" maxlength="160" autocomplete="name" required>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="ap-ok" name="agree" value="1" required><label class="form-check-label" for="ap-ok">Conferi os arquivos e aprovo</label></div>
            <button class="btn btn-dark">Aprovar</button>
          </form>
        </div>
        <div class="col-md-6">
          <form method="post" action="/e/<?= e($token) ?>" class="doc-box h-100 d-grid gap-2 align-content-start"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
            <h3 class="h6 mb-0">Quero ajustes</h3>
            <label class="form-label mb-0" for="aj-desc">O que você gostaria de mudar</label><textarea class="form-control" id="aj-desc" name="description" rows="4" maxlength="4000" required></textarea>
            <label class="form-label mb-0" for="aj-name">Seu nome completo</label><input class="form-control" id="aj-name" name="signer" maxlength="160" autocomplete="name" required>
            <p class="small text-body-secondary mb-1"><?= $left > 0 ? 'Esta etapa ainda tem ' . $left . ' rodada' . ($left > 1 ? 's' : '') . ' de revisão incluída' . ($left > 1 ? 's' : '') . ' no contrato.' : 'As rodadas incluídas nesta etapa já foram usadas: um novo ajuste tem custo extra, e a gente te manda o valor antes de começar.' ?></p>
            <button class="btn btn-outline-dark">Pedir ajustes</button>
          </form>
        </div>
      </div>
    </section>
    <?php endif; ?>
    <footer class="doc-foot"><span><?= e(App\Brand::footer()) ?></span><span><?= e(implode(' · ', array_filter([App\Brand::get('company_whatsapp') ? 'WhatsApp ' . App\Brand::get('company_whatsapp') : '', App\Brand::get('company_instagram') ? 'instagram.com/' . App\Brand::get('company_instagram') : '']))) ?></span></footer>
  </article>
</div>
