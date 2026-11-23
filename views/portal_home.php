<?php
use App\Projects;
$first = explode(' ', trim($me['name']))[0];
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+7 days'));
$dLabel = ['enviado' => 'Esperando a sua resposta', 'ajustes' => 'Ajustes em andamento', 'aprovado' => 'Aprovado', 'pendente' => 'Em produção', 'producao' => 'Em produção'];
$todo = [];
foreach ($cards as $x) {
    foreach ($x['deliv'] as $d) if ($d['status'] === 'enviado' && $d['public_token']) $todo[] = ['Ver e aprovar "' . $d['title'] . '"', $x['p']['title'], '/e/' . $d['public_token'], 'Abrir'];
    foreach ($x['costs'] as $r) $todo[] = ['Aprovar o valor de um ajuste extra (' . money((float) $r['extra_cost']) . ')', $x['p']['title'], '/e/' . $r['public_token'] . '#custo-' . (int) $r['id'], 'Ver valor'];
    foreach ($x['inst'] as $i) if ($i['status'] === 'aberta' && $i['due_date'] <= $soon) $todo[] = [($i['due_date'] < $today ? 'Pagamento vencido' : 'Pagamento') . ': ' . money((float) $i['amount']) . ' · ' . due($i['due_date'])[0], $x['p']['title'], '#pagamentos-' . (int) $x['p']['id'], 'Ver'];
}
?>
<div class="doc-page portal">
  <header class="portal-top">
    <img src="<?= e(App\Brand::logoUrl(false)) ?>" alt="<?= e(App\Brand::name()) ?>" height="36">
    <form method="post" action="/portal/sair"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark"><?= icon('logout', 'icon icon-sm') ?>Sair</button></form>
  </header>
  <h1 class="portal-hello">Olá, <?= e($first) ?></h1>
  <p class="text-body-secondary mb-4">Aqui você acompanha o andamento do seu projeto com a <?= e(App\Brand::name()) ?>.</p>

  <section class="doc mb-4" aria-labelledby="h-todo">
    <div class="doc-sec border-0">
      <h2 id="h-todo">O que precisa de você</h2>
      <?php if (!$todo): ?><p class="doc-state ok mb-0"><?= icon('check') ?>Nada pendente com você agora. A gente avisa quando tiver novidade.</p><?php else: ?>
      <ul class="portal-todo">
        <?php foreach ($todo as [$what, $proj, $href, $cta]): ?>
        <li><span><strong><?= e($what) ?></strong><small><?= e($proj) ?></small></span><a class="btn btn-sm btn-dark" href="<?= e($href) ?>"><?= e($cta) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </section>

  <?php if (!$cards): ?>
  <section class="doc"><div class="doc-sec border-0"><p class="mb-0">O seu projeto aparece aqui assim que a proposta for aceita.</p></div></section>
  <?php endif; ?>

  <?php foreach ($cards as $x): $p = $x['p']; $cur = Projects::current($x['phases']); $done = count(array_filter($x['phases'], fn($ph) => $ph['status'] === 'concluida')); ?>
  <article class="doc mb-4" aria-labelledby="h-p<?= (int) $p['id'] ?>">
    <div class="doc-sec border-0">
      <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-1">
        <h2 class="mb-0" id="h-p<?= (int) $p['id'] ?>"><?= e($p['title']) ?></h2>
        <span class="badge pstatus-<?= e($p['status']) ?>"><?= e(Projects::STATUS[$p['status']]) ?></span>
      </div>
      <p class="small text-body-secondary mb-0"><?= $x['phases'] ? $done . ' de ' . count($x['phases']) . ' fases concluídas' : '' ?><?= $p['due_date'] && $p['status'] === 'ativo' ? ' · entrega prevista para ' . e(date('d/m/Y', strtotime($p['due_date']))) : '' ?></p>
    </div>

    <?php if ($p['status'] === 'pausado' && $p['hold_reason'] === 'pagamento'): ?>
    <div class="doc-sec pt-0 border-0"><div class="alert alert-warning mb-0" role="alert"><strong>Projeto pausado por pagamento em atraso.</strong> Assim que a parcela for paga, o trabalho continua de onde parou. <a href="#pagamentos-<?= (int) $p['id'] ?>">Ver pagamentos</a></div></div>
    <?php endif; ?>
    <?php if ($x['phases']): ?>
    <section class="doc-sec">
      <h3>Fases</h3>
      <ol class="portal-steps">
        <?php foreach ($x['phases'] as $ph): $is = $ph['status'] === 'concluida' ? 'done' : ($cur && $cur['id'] === $ph['id'] ? 'now' : 'next'); ?>
        <li class="is-<?= $is ?>"<?= $is === 'now' ? ' aria-current="step"' : '' ?>>
          <span class="dot" aria-hidden="true"><?= $is === 'done' ? icon('check', 'icon icon-sm') : '' ?></span>
          <span><strong><?= e($ph['name']) ?></strong><small><?= $is === 'done' ? 'Concluída' : ($is === 'now' ? 'Em andamento' : 'A seguir') ?><?= $ph['due_date'] && $is !== 'done' ? ' · até ' . e(date('d/m', strtotime($ph['due_date']))) : '' ?></small></span>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <section class="doc-sec">
      <h3>Entregas e arquivos</h3>
      <?php if ($x['deliv'] && !$x['paid']): ?><p class="small text-body-secondary">As imagens aparecem como prévia. Os arquivos originais (alta resolução, PDF e DWG) são liberados quando o pagamento do projeto estiver completo.</p><?php endif; ?>
      <?php if (!$x['deliv']): ?><p class="small text-body-secondary mb-0">Os arquivos aparecem aqui quando a equipe enviar a primeira entrega.</p><?php endif; ?>
      <?php foreach ($x['deliv'] as $d): ?>
      <div class="portal-deliv">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div><strong><?= e($d['title']) ?></strong> <span class="badge dstatus-<?= e($d['status']) ?>"><?= e($dLabel[$d['status']] ?? '') ?></span></div>
          <?php if ($d['public_token'] && $d['status'] !== 'aprovado'): ?><a class="btn btn-sm <?= $d['status'] === 'enviado' ? 'btn-dark' : 'btn-outline-dark' ?>" href="/e/<?= e($d['public_token']) ?>"><?= $d['status'] === 'enviado' ? 'Aprovar ou pedir ajustes' : 'Ver pedido' ?></a><?php endif; ?>
        </div>
        <div class="deliv-gallery">
          <?php foreach ($x['files'][$d['id']] ?? [] as $f): $url = '/portal/arquivos/' . (int) $f['id']; ?>
            <?php if (in_array($f['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)): ?>
              <a class="deliv-img" href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= $url ?>" alt="<?= e($f['name']) ?>" loading="lazy"><span><?= e($f['name']) ?><?= (int) $f['version'] > 1 ? ' · versão ' . (int) $f['version'] : '' ?></span></a>
            <?php elseif (!$x['paid']): ?>
              <div class="deliv-doc is-locked"><?= icon('shield') ?><span><strong><?= e($f['name']) ?></strong><small>Liberado após a quitação</small></span></div>
            <?php else: ?>
              <a class="deliv-doc" href="<?= $url ?><?= $f['mime'] === 'application/pdf' ? '' : '?baixar=1' ?>" target="_blank" rel="noopener"><?= icon('file') ?><span><strong><?= e($f['name']) ?></strong><small><?= $f['mime'] === 'application/pdf' ? 'Abrir PDF' : 'Baixar' ?><?= (int) $f['version'] > 1 ? ' · versão ' . (int) $f['version'] : '' ?></small></span></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </section>

    <?php if ($x['inst']): $paid = array_sum(array_map(fn($i) => $i['status'] === 'paga' ? (float) ($i['paid_amount'] ?? $i['amount']) : 0, $x['inst'])); $open = array_sum(array_map(fn($i) => $i['status'] === 'aberta' ? (float) $i['amount'] : 0, $x['inst'])); ?>
    <section class="doc-sec" id="pagamentos-<?= (int) $p['id'] ?>">
      <h3>Pagamentos</h3>
      <p class="small mb-3">Pago: <strong><?= e(money($paid)) ?></strong> · A pagar: <strong><?= e(money($open)) ?></strong></p>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th scope="col">Parcela</th><th scope="col">Vencimento</th><th scope="col" class="text-end">Valor</th><th scope="col">Situação</th></tr></thead>
          <tbody>
          <?php foreach ($x['inst'] as $i): $late = $i['status'] === 'aberta' && $i['due_date'] < $today; ?>
            <tr>
              <td><?= e($i['description'] ?: 'Parcela ' . (int) $i['number']) ?></td>
              <td><?= e(date('d/m/Y', strtotime($i['due_date']))) ?></td>
              <td class="text-end text-nowrap"><?= e(money((float) $i['amount'])) ?></td>
              <td><?php if ($i['status'] === 'paga'): ?><span class="badge text-bg-success">Paga<?= $i['paid_at'] ? ' em ' . e(date('d/m', strtotime($i['paid_at']))) : '' ?></span><?php elseif ($late): ?><span class="badge text-bg-danger">Vencida</span><?php else: ?><span class="badge text-bg-light">Em aberto</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($open > 0 && $pix[0] !== ''): ?><p class="small text-body-secondary mt-3 mb-0">Pix: <strong class="text-body"><?= e($pix[0]) ?></strong><?= $pix[1] !== '' ? ' (' . e($pix[1]) . ')' : '' ?>. Depois de pagar, mande o comprovante pelo WhatsApp.</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>

  <footer class="doc-foot"><span><?= e(App\Brand::footer()) ?></span><span><?= e(implode(' · ', array_filter([App\Brand::get('company_whatsapp') ? 'WhatsApp ' . App\Brand::get('company_whatsapp') : '', App\Brand::get('company_instagram') ? 'instagram.com/' . App\Brand::get('company_instagram') : '']))) ?></span></footer>
</div>
