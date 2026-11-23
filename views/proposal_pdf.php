<?php
$sub = array_sum(array_column($p['items'], 'line_total'));
$qty = fn($q) => rtrim(rtrim(number_format((float) $q, 2, ',', '.'), '0'), ',');
$lines = fn(?string $t) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $t))));
$deliv = $lines($p['deliverables'] ?? ''); $excl = $lines($p['exclusions'] ?? '');
$asset = fn(string $f, string $mime) => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents(__DIR__ . '/../public/assets/' . $f));
$pal = App\Brand::palette(App\Brand::validColor(App\Brand::get('brand_color')) ?? App\Brand::DEFAULT_COLOR);
$sec = 0; $n = function () use (&$sec) { return sprintf('%02d', ++$sec); };
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>
@page { margin: 0 0 46px 0; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1918; margin: 0; line-height: 1.45; }
.cover { background: #1A1918; color: #FAF8F5; padding: 34px 48px 30px; border-bottom: 3px solid <?= $pal['accent_light'] ?>; }
.cover img { height: 40px; }
.kick { margin: 30px 0 6px; font-size: 8.5px; letter-spacing: 2px; color: <?= $pal['accent_light'] ?>; font-weight: bold; }
h1 { font-family: DejaVu Serif, serif; font-size: 25px; font-weight: normal; margin: 0 0 6px; line-height: 1.15; color: #FFFDF9; }
.for { margin: 0; color: #CFC6B8; font-size: 11px; }
.wrap { padding: 22px 48px 10px; }
.meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
.meta td { width: 33%; padding: 11px 14px; background: #F6F1E8; vertical-align: top; }
.meta td + td { border-left: 6px solid #fff; }
.meta small { display: block; color: #6B645C; font-size: 8px; letter-spacing: 1px; margin-bottom: 3px; }
.meta b { font-size: 12.5px; } .meta .total b { color: <?= $pal['accent'] ?>; font-size: 15px; }
h2 { font-family: DejaVu Serif, serif; font-size: 13.5px; font-weight: normal; margin: 20px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #E4DCCD; page-break-after: avoid; }
h2 span { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: <?= $pal['accent'] ?>; font-weight: bold; margin-right: 6px; }
h3 { font-size: 10px; margin: 8px 0 5px; }
p { margin: 0 0 6px; } .muted { color: #6B645C; } .pre { white-space: pre-wrap; }
.facts { width: 100%; border-collapse: collapse; margin: 8px 0; } .facts td { padding: 0 18px 0 0; vertical-align: top; }
.facts b { display: block; font-family: DejaVu Serif, serif; font-size: 19px; color: <?= $pal['accent'] ?>; font-weight: normal; }
.cols { width: 100%; border-collapse: collapse; } .cols td { vertical-align: top; width: 55%; padding-right: 16px; } .cols td + td { width: 45%; }
ul { margin: 0; padding-left: 14px; } li { margin-bottom: 3px; } .x li { color: #6B645C; }
.items, .sched { width: 100%; border-collapse: collapse; }
.items td, .sched td { padding: 7px 0; border-bottom: 1px solid #EFE9DE; vertical-align: top; }
.items small { display: block; color: #6B645C; font-size: 8.5px; margin-top: 1px; }
.r { text-align: right; white-space: nowrap; }
.sum { width: 55%; margin-left: 45%; border-collapse: collapse; margin-top: 6px; }
.sum td { padding: 4px 0; } .sum .grand td { border-top: 2px solid #1A1918; padding-top: 8px; font-size: 13px; font-weight: bold; }
.box { margin-top: 12px; padding: 10px 14px; background: #F6F1E8; }
.gallery { width: 100%; border-collapse: collapse; } .gallery td { width: 33%; padding-right: 6px; } .gallery img { width: 100%; height: 118px; }
.steps { width: 100%; border-collapse: collapse; } .steps td { width: 33%; vertical-align: top; padding-right: 14px; }
.steps .n { color: <?= $pal['accent'] ?>; font-family: DejaVu Serif, serif; font-size: 16px; } .steps b { display: block; margin: 2px 0 2px; }
.sign { margin-top: 18px; padding: 12px 14px; border: 1px solid #E4DCCD; }
.foot { position: fixed; bottom: -46px; left: 0; right: 0; height: 46px; padding: 14px 48px 0; background: #1A1918; color: #E9E2D6; font-size: 8px; }
.avoid { page-break-inside: avoid; }
</style></head><body>
<div class="foot"><?= e(App\Brand::footer(true)) ?> · <?= e(implode(' · ', array_filter([App\Brand::get('company_whatsapp') ? 'WhatsApp ' . App\Brand::get('company_whatsapp') : '', App\Brand::get('company_instagram') ? 'instagram.com/' . App\Brand::get('company_instagram') : '']))) ?></div>
<div class="cover">
  <img src="<?= App\Brand::logoDataUri(true) ?>" alt="<?= e(App\Brand::name()) ?>">
  <p class="kick">PROPOSTA Nº <?= (int) $p['id'] ?><?= (int) ($p['version'] ?? 1) > 1 ? ' · VERSÃO ' . (int) $p['version'] : '' ?></p>
  <h1><?= e($p['title']) ?></h1>
  <p class="for">Preparada para <?= e($c['name']) ?></p>
</div>
<div class="wrap">
  <table class="meta"><tr>
    <td><small>EMITIDA EM</small><b><?= e(date('d/m/Y', strtotime($p['created_at']))) ?></b></td>
    <td><small>VÁLIDA ATÉ</small><b><?= e(date('d/m/Y', strtotime($p['valid_until']))) ?></b></td>
    <td class="total"><small>INVESTIMENTO</small><b><?= e(money((float) $p['total'])) ?></b></td>
  </tr></table>

  <div class="avoid">
  <h2><span><?= $n() ?></span>Sobre o estúdio</h2>
  <p><?= e(App\Brand::get('company_about')) ?></p>
  <table class="facts"><tr><?php foreach (App\Brand::pairs(App\Brand::get('company_facts')) as [$fv, $fl]): ?><td><b><?= e($fv) ?></b><?= e($fl) ?></td><?php endforeach; ?></tr></table>
  <?php $people = App\Brand::pairs(App\Brand::get('company_people')); if ($people): ?><p class="muted">Equipe: <?= e(implode('; ', array_map(fn($x) => $x[0] . ($x[1] ? ' (' . mb_strtolower($x[1]) . ')' : ''), $people))) ?>.<?= App\Brand::get('company_registry') ? ' ' . e(App\Brand::get('company_registry')) . '.' : '' ?></p><?php endif; ?>
  </div>

  <?php if (!empty($p['project_summary'])): ?><h2><span><?= $n() ?></span>Entendimento do projeto</h2><p class="pre"><?= e($p['project_summary']) ?></p><?php endif; ?>

  <?php if (!empty($p['scope']) || $deliv || $excl): ?>
  <h2><span><?= $n() ?></span>Escopo</h2>
  <?php if (!empty($p['scope'])): ?><p class="pre"><?= e($p['scope']) ?></p><?php endif; ?>
  <table class="cols"><tr>
    <td><?php if ($deliv): ?><h3>Você recebe</h3><ul><?php foreach ($deliv as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
    <td><?php if ($excl): ?><h3>Não está incluído</h3><ul class="x"><?php foreach ($excl as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
  </tr></table>
  <?php endif; ?>

  <?php if (!empty($p['schedule'])): ?>
  <div class="avoid">
  <h2><span><?= $n() ?></span>Cronograma</h2>
  <table class="sched"><?php foreach ($p['schedule'] as $k => $row): ?><tr><td><?= $k + 1 ?>. <?= e($row['stage']) ?></td><td class="r muted"><?= e($row['duration_text']) ?></td></tr><?php endforeach; ?></table>
  <p class="muted">Os prazos contam a partir da aprovação da etapa anterior.</p>
  </div>
  <?php endif; ?>

  <div class="avoid">
  <h2><span><?= $n() ?></span>Investimento</h2>
  <table class="items">
  <?php foreach ($p['items'] as $i): ?><tr><td><?= e($i['label']) ?><small><?= e($qty($i['quantity'])) ?> × <?= e(money((float) $i['unit_price'])) ?></small></td><td class="r"><?= e(money((float) $i['line_total'])) ?></td></tr><?php endforeach; ?>
  </table>
  <table class="sum">
    <?php if ((float) $p['credit'] > 0): ?><tr><td>Subtotal</td><td class="r"><?= e(money($sub)) ?></td></tr><tr><td>Abatimento da etapa já paga</td><td class="r">− <?= e(money((float) $p['credit'])) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="r"><?= e(money((float) $p['total'])) ?></td></tr>
  </table>
  <?php if (!empty($p['payment_terms'])): ?><div class="box"><h3>Forma de pagamento</h3><p class="pre"><?= e($p['payment_terms']) ?></p></div><?php endif; ?>
  <div class="box"><h3>Pagamento e entrega dos arquivos</h3><p><?= e(App\Finance::DELIVERY_CLAUSE) ?></p></div>
  </div>

  <?php if (!empty($p['revision_policy']) || !empty($p['notes'])): ?>
  <h2><span><?= $n() ?></span>Revisões e condições</h2>
  <?php if (!empty($p['revision_policy'])): ?><p class="pre"><?= e($p['revision_policy']) ?></p><?php endif; ?>
  <?php if (!empty($p['notes'])): ?><p class="pre muted"><?= e($p['notes']) ?></p><?php endif; ?>
  <?php endif; ?>

  <div class="avoid">
  <h2><span><?= $n() ?></span>Projetos do estúdio</h2>
  <table class="gallery"><tr>
    <td><img src="<?= $asset('portfolio/render-3d-sala-integrada-cozinha-jantar-jardim-vertical.jpg', 'image/jpeg') ?>" alt=""></td>
    <td><img src="<?= $asset('portfolio/render-3d-cozinha-azul-ilha-marmorizada.jpg', 'image/jpeg') ?>" alt=""></td>
    <td><img src="<?= $asset('portfolio/render-3d-quarto-casal-design-de-interiores.jpg', 'image/jpeg') ?>" alt=""></td>
  </tr></table>
  </div>

  <div class="avoid">
  <h2><span><?= $n() ?></span>Como seguimos</h2>
  <table class="steps"><tr>
    <td><span class="n">1</span><b>Você aprova</b>Pelo link da proposta, com o seu nome completo.</td>
    <td><span class="n">2</span><b>Falamos no WhatsApp</b>Confirmamos os detalhes e agendamos a reunião online de início.</td>
    <td><span class="n">3</span><b>O projeto começa</b>Seguimos o cronograma, com as revisões combinadas em cada etapa.</td>
  </tr></table>
  <div class="sign">Para aceitar, use o link da proposta: <b><?= e(rtrim((string) env('APP_URL', ''), '/') . '/p/' . $p['public_token']) ?></b><br><span class="muted">O aceite registra o seu nome, a data e a versão da proposta.</span></div>
  </div>
</div>
</body></html>
