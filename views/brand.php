<?php use App\Brand; $low = $contrast < 4.5; ?>
<div class="page-head">
  <div><h1 class="h3">Empresa</h1><p>Nome, logo, cor e textos que aparecem no sistema, nas páginas do cliente, no PDF da proposta e nas mensagens.</p></div>
</div>
<form method="post" action="/empresa" enctype="multipart/form-data" class="row g-4">
  <?= csrf_field() ?>
  <div class="col-xl-8 d-grid gap-4 align-content-start">
    <section class="card card-body p-4 d-grid gap-3">
      <h2 class="h5 fw-bold mb-0">Identidade</h2>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="b-name">Nome da empresa</label><input class="form-control" id="b-name" name="company_name" maxlength="120" required value="<?= e($v['company_name']) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="b-tag">O que faz (curto)</label><input class="form-control" id="b-tag" name="company_tagline" maxlength="120" value="<?= e($v['company_tagline']) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="b-reg">Onde atende</label><input class="form-control" id="b-reg" name="company_region" maxlength="160" value="<?= e($v['company_region']) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="b-cau">Registro profissional</label><input class="form-control" id="b-cau" name="company_registry" maxlength="120" value="<?= e($v['company_registry']) ?>" placeholder="Ex.: Registro no CAU/DF"></div>
        <div class="col-md-6"><label class="form-label" for="b-wa">WhatsApp (rodapé)</label><input class="form-control" id="b-wa" name="company_whatsapp" maxlength="40" value="<?= e($v['company_whatsapp']) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="b-ig">Instagram (sem @)</label><input class="form-control" id="b-ig" name="company_instagram" maxlength="60" value="<?= e($v['company_instagram']) ?>"></div>
      </div>
      <p class="small text-body-secondary mb-0">Rodapé: <?= e(Brand::footer(true)) ?></p>
    </section>

    <section class="card card-body p-4 d-grid gap-3">
      <h2 class="h5 fw-bold mb-0">Sobre a empresa (proposta)</h2>
      <div><label class="form-label" for="b-about">Apresentação</label><textarea class="form-control" id="b-about" name="company_about" rows="4" maxlength="1200"><?= e($v['company_about']) ?></textarea></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="b-facts">Números (um por linha: valor | texto)</label><textarea class="form-control" id="b-facts" name="company_facts" rows="4" maxlength="400"><?= e($v['company_facts']) ?></textarea><p class="small text-body-secondary mt-1 mb-0">Aparecem no login e na proposta. Ex.: <code>50+ | projetos entregues</code></p></div>
        <div class="col-md-6"><label class="form-label" for="b-people">Equipe (um por linha: nome | função)</label><textarea class="form-control" id="b-people" name="company_people" rows="4" maxlength="600"><?= e($v['company_people']) ?></textarea><p class="small text-body-secondary mt-1 mb-0">Deixe vazio para não mostrar a equipe.</p></div>
      </div>
    </section>
  </div>

  <div class="col-xl-4 d-grid gap-4 align-content-start">
    <section class="card card-body p-4 d-grid gap-3">
      <h2 class="h5 fw-bold mb-0">Logo</h2>
      <?php foreach (['brand_logo' => [false, 'Para fundo claro'], 'brand_logo_light' => [true, 'Para fundo escuro']] as $k => [$dark, $label]): ?>
      <div>
        <p class="form-label mb-1"><?= $label ?></p>
        <div class="logo-prev<?= $dark ? ' is-dark' : '' ?>"><img src="<?= e(Brand::logoUrl($dark)) ?>" alt="Logo atual, <?= mb_strtolower($label) ?>"></div>
        <label class="visually-hidden" for="<?= $k ?>">Novo logo, <?= mb_strtolower($label) ?></label>
        <input class="form-control form-control-sm mt-2" type="file" id="<?= $k ?>" name="<?= $k ?>" accept="image/png,image/jpeg,image/webp">
        <?php if ($v[$k] !== ''): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="rm-<?= $k ?>" name="remove_<?= $k ?>" value="1"><label class="form-check-label small" for="rm-<?= $k ?>">Voltar ao logo original</label></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <p class="small text-body-secondary mb-0">PNG, JPG ou WebP, até 2 MB, de preferência na horizontal e com fundo transparente. Se faltar uma das versões, o sistema usa a outra.</p>
    </section>

    <section class="card card-body p-4 d-grid gap-3">
      <h2 class="h5 fw-bold mb-0">Cor da marca</h2>
      <div class="d-flex align-items-center gap-3">
        <input class="form-control form-control-color" type="color" id="b-color" name="brand_color" value="<?= e($v['brand_color']) ?>" aria-label="Cor da marca">
        <div><strong class="d-block tnum"><?= e($v['brand_color']) ?></strong><small class="text-body-secondary">Destaques, links, contadores e o PDF. Os tons claros e escuros são calculados.</small></div>
      </div>
      <?php $pal = Brand::palette($v['brand_color']); ?>
      <div class="swatches"><?php foreach (['accent' => 'Principal', 'accent_strong' => 'Forte', 'accent_soft' => 'Suave', 'dark_accent' => 'Tema escuro'] as $k => $l): ?><span><i data-color="<?= e($pal[$k]) ?>"></i><?= $l ?></span><?php endforeach; ?></div>
      <?php if ($low): ?><p class="alert alert-warning small mb-0">Esta cor tem contraste baixo com o fundo (<?= e(number_format($contrast, 1, ',', '')) ?>:1). Textos e links podem ficar difíceis de ler; prefira um tom mais escuro.</p><?php endif; ?>
      <p class="small text-body-secondary mb-0">Para voltar à cor original, use <code><?= Brand::DEFAULT_COLOR ?></code>.</p>
    </section>
    <button class="btn btn-dark btn-lg">Salvar</button>
  </div>
</form>
