<?php
// Shared proposal document: client page (/p/{token}) and internal preview. Expects $p (items, schedule) and $c (name).
$sub = array_sum(array_column($p['items'], 'line_total'));
$first = explode(' ', trim($c['name']))[0];
$qty = fn($q) => rtrim(rtrim(number_format((float) $q, 2, ',', '.'), '0'), ',');
$lines = fn(?string $t) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $t))));
$deliv = $lines($p['deliverables'] ?? ''); $excl = $lines($p['exclusions'] ?? '');
$sec = 0; $n = function () use (&$sec) { return sprintf('%02d', ++$sec); };
?>
<article class="doc">
  <header class="doc-cover">
    <img class="doc-cover-img" src="/assets/login-render.webp" alt="" width="1200" height="900">
    <div class="doc-cover-in">
      <div class="d-flex justify-content-between align-items-start gap-3">
        <img src="<?= e(App\Brand::logoUrl(true)) ?>" alt="<?= e(App\Brand::name()) ?>" height="40" class="doc-logo">
        <span class="doc-ver">Proposta nº <?= (int) $p['id'] ?><?= (int) ($p['version'] ?? 1) > 1 ? ' · versão ' . (int) $p['version'] : '' ?></span>
      </div>
      <div>
        <h1 class="doc-title"><?= e($p['title']) ?></h1>
        <p class="doc-for">Preparada para <?= e($c['name']) ?></p>
      </div>
    </div>
  </header>
  <dl class="doc-meta">
    <div><dt>Emitida em</dt><dd><?= e(date('d/m/Y', strtotime($p['created_at']))) ?></dd></div>
    <div><dt>Válida até</dt><dd><?= e(date('d/m/Y', strtotime($p['valid_until']))) ?></dd></div>
    <div class="doc-total"><dt>Investimento</dt><dd class="tnum"><?= e(money((float) $p['total'])) ?></dd></div>
  </dl>

  <section class="doc-sec doc-about">
    <h2><span><?= $n() ?></span>Sobre o estúdio</h2>
    <p class="doc-lead"><?= e(App\Brand::get('company_about')) ?></p>
    <dl class="doc-facts"><?php foreach (App\Brand::pairs(App\Brand::get('company_facts')) as [$fv, $fl]): ?><div><dt><?= e($fv) ?></dt><dd><?= e($fl) ?></dd></div><?php endforeach; ?></dl>
    <?php $people = App\Brand::pairs(App\Brand::get('company_people')); if ($people): ?>
    <div class="doc-founders">
      <?php foreach ($people as [$pn, $pr]): ?><div><span class="doc-ini" aria-hidden="true"><?= e(App\Brand::initials($pn)) ?></span><p><b><?= e($pn) ?></b><span><?= e($pr) ?></span></p></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (App\Brand::get('company_registry')): ?><p class="small text-body-secondary mb-0"><?= e(App\Brand::get('company_registry')) ?>.</p><?php endif; ?>
  </section>

  <?php if (!empty($p['project_summary'])): ?>
  <section class="doc-sec"><h2><span><?= $n() ?></span>Entendimento do projeto</h2><p class="doc-lead pre-wrap"><?= e($p['project_summary']) ?></p></section>
  <?php endif; ?>

  <?php if (!empty($p['scope']) || $deliv || $excl): ?>
  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Escopo</h2>
    <?php if (!empty($p['scope'])): ?><p class="pre-wrap"><?= e($p['scope']) ?></p><?php endif; ?>
    <div class="doc-cols">
      <?php if ($deliv): ?><div><h3>Você recebe</h3><ul class="doc-check"><?php foreach ($deliv as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if ($excl): ?><div><h3>Não está incluído</h3><ul class="doc-x"><?php foreach ($excl as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($p['schedule'])): ?>
  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Cronograma</h2>
    <ol class="doc-timeline"><?php foreach ($p['schedule'] as $row): ?><li><b><?= e($row['stage']) ?></b><span><?= e($row['duration_text']) ?></span></li><?php endforeach; ?></ol>
    <p class="small text-body-secondary mb-0">Os prazos contam a partir da aprovação da etapa anterior.</p>
  </section>
  <?php endif; ?>

  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Investimento</h2>
    <ul class="doc-items">
      <?php foreach ($p['items'] as $i): ?>
      <li><span class="doc-item"><?= e($i['label']) ?><small><?= e($qty($i['quantity'])) ?> × <?= e(money((float) $i['unit_price'])) ?></small></span><b class="tnum"><?= e(money((float) $i['line_total'])) ?></b></li>
      <?php endforeach; ?>
    </ul>
    <dl class="doc-sum">
      <?php if ((float) $p['credit'] > 0): ?>
      <div><dt>Subtotal</dt><dd class="tnum"><?= e(money($sub)) ?></dd></div>
      <div><dt>Abatimento da etapa já paga</dt><dd class="tnum">− <?= e(money((float) $p['credit'])) ?></dd></div>
      <?php endif; ?>
      <div class="grand"><dt>Total</dt><dd class="tnum"><?= e(money((float) $p['total'])) ?></dd></div>
    </dl>
    <?php if (!empty($p['payment_terms'])): ?><div class="doc-box"><h3>Forma de pagamento</h3><p class="pre-wrap mb-0"><?= e($p['payment_terms']) ?></p></div><?php endif; ?>
  </section>

  <?php if (!empty($p['revision_policy']) || !empty($p['notes'])): ?>
  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Revisões e condições</h2>
    <?php if (!empty($p['revision_policy'])): ?><p class="pre-wrap"><?= e($p['revision_policy']) ?></p><?php endif; ?>
    <?php if (!empty($p['notes'])): ?><p class="pre-wrap text-body-secondary mb-0"><?= e($p['notes']) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Projetos do estúdio</h2>
    <div class="doc-gallery">
      <img src="/assets/portfolio/render-3d-sala-integrada-cozinha-jantar-jardim-vertical.jpg" alt="Sala integrada com cozinha, jantar e jardim vertical" width="900" height="675" loading="lazy">
      <img src="/assets/portfolio/render-3d-cozinha-azul-ilha-marmorizada.jpg" alt="Cozinha azul com ilha marmorizada" width="900" height="675" loading="lazy">
      <img src="/assets/portfolio/render-3d-quarto-casal-design-de-interiores.jpg" alt="Quarto de casal" width="900" height="675" loading="lazy">
    </div>
  </section>

  <section class="doc-sec">
    <h2><span><?= $n() ?></span>Como seguimos</h2>
    <ol class="doc-steps">
      <li><b>Você aprova</b><span>Aceite por esta página, <?= e($first) ?>, com o seu nome completo.</span></li>
      <li><b>Falamos no WhatsApp</b><span>A equipe confirma os detalhes e agenda a reunião online de início.</span></li>
      <li><b>O projeto começa</b><span>Seguimos o cronograma acima, com as revisões combinadas em cada etapa.</span></li>
    </ol>
  </section>
  <footer class="doc-foot"><span><?= e(App\Brand::footer(true)) ?></span><span><?= e(implode(' · ', array_filter([App\Brand::get('company_whatsapp') ? 'WhatsApp ' . App\Brand::get('company_whatsapp') : '', App\Brand::get('company_instagram') ? 'instagram.com/' . App\Brand::get('company_instagram') : '']))) ?></span></footer>
</article>
