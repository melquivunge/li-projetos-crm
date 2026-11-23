<?php $readonly = $b['status'] === 'validado'; $first = explode(' ', trim($c['name']))[0]; ?>
<div class="doc-page">
  <article class="doc">
    <header class="doc-cover doc-cover-sm">
      <img class="doc-cover-img" src="/assets/login-render.webp" alt="" width="1200" height="900">
      <div class="doc-cover-in">
        <img src="<?= e(App\Brand::logoUrl(true)) ?>" alt="<?= e(App\Brand::name()) ?>" height="40" class="doc-logo">
        <div><h1 class="doc-title">Briefing do seu projeto</h1><p class="doc-for"><?= e($first) ?>, conte como você imagina o projeto. Leva uns 5 minutos.</p></div>
      </div>
    </header>
    <?php if ($readonly): ?>
      <section class="doc-sec" id="ok"><p class="doc-state ok mb-0"><?= icon('check') ?>Obrigado! Você confirmou este briefing. A nossa equipe vai usar estas respostas no seu projeto.</p></section>
    <?php elseif ($b['status'] === 'respondido' && !$error): ?>
      <section class="doc-sec" id="ok"><p class="doc-state mb-0">Recebemos as suas respostas. Você pode completar ou corrigir abaixo e, quando estiver tudo certo, confirmar.</p></section>
    <?php endif; ?>
    <form method="post" action="/b/<?= e($b['public_token']) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <section class="doc-sec">
        <?php foreach ($questions as $q) require __DIR__ . '/_briefing_field.php'; ?>
      </section>
      <?php if (!$readonly): ?>
      <section class="doc-sec" id="enviar">
        <h2>Fotos, medidas e referências</h2>
        <p class="text-body-secondary">Fotos do imóvel, planta, medidas ou imagens de referência ajudam muito. JPG, PNG, WebP, HEIC, PDF ou DWG, até 15 MB cada.</p>
        <input class="form-control mb-2" type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/heic,application/pdf,.dwg" aria-label="Escolher arquivos">
        <?php if ($files): ?><p class="small mb-0">Você já enviou: <?= e(implode(', ', array_map(fn($f) => $f['name'], $files))) ?></p><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger mt-3" role="alert"><?= e($error) ?></div><?php endif; ?>
        <div class="doc-box mt-4">
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="confirm" name="confirm" value="1"><label class="form-check-label" for="confirm">Confirmo que as informações estão corretas (opcional agora; você pode salvar e voltar depois)</label></div>
          <label class="form-label" for="signer">Seu nome completo, para confirmar</label><input class="form-control" id="signer" name="signer" maxlength="160" autocomplete="name">
        </div>
        <div class="mt-3 d-grid d-sm-flex"><button class="btn btn-dark btn-lg">Enviar respostas</button></div>
      </section>
      <?php endif; ?>
    </form>
    <footer class="doc-foot"><span><?= e(App\Brand::footer()) ?></span><span><?= e(implode(' · ', array_filter([App\Brand::get('company_whatsapp') ? 'WhatsApp ' . App\Brand::get('company_whatsapp') : '', App\Brand::get('company_instagram') ? 'instagram.com/' . App\Brand::get('company_instagram') : '']))) ?></span></footer>
  </article>
</div>
