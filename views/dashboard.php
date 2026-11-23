<?php
use App\{Auth, Clients};
use App\Controllers\DashboardController as D;
$closed = (int) ($cohort['ganho'] ?? 0);
$conv = $leads ? round($closed / $leads * 100) : null;
$ticket = (int) $prop['won_n'] ? (float) $prop['won_v'] / (int) $prop['won_n'] : null;
$fc = $firstContact === null ? '—' : ($firstContact < 60 ? round($firstContact) . ' min' : ($firstContact < 2880 ? number_format($firstContact / 60, 1, ',', '') . ' h' : number_format($firstContact / 1440, 1, ',', '') . ' dias'));
$max = max(1, max($perDay));
$W = 720; $H = 160; $n = count($perDay); $bw = $W / $n; $gap = min(2, $bw * 0.3);
$tile = fn(string $label, string $value, string $hint = '', string $ic = 'trend') => '<div class="col-6 col-xl-3"><div class="card h-100 stat"><div class="card-body d-flex justify-content-between gap-2"><div class="min-w-0"><div class="label">' . e($label) . '</div><div class="value tnum">' . e($value) . '</div>' . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . '</div><span class="ico">' . icon($ic, 'icon icon-sm') . '</span></div></div></div>';
$hour = (int) date('G'); $greet = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
?>
<div class="page-head">
  <div><h1 class="greet"><?= $greet ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1><p>Como está o estúdio nos últimos <?= e(D::PERIODS[$days]) ?>.</p></div>
  <form class="d-flex flex-wrap gap-2" method="get" action="/">
    <label class="visually-hidden" for="d-dias">Período</label>
    <select class="form-select w-auto" id="d-dias" name="dias"><?php foreach (D::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>>Últimos <?= e($l) ?></option><?php endforeach; ?></select>
    <?php if (Auth::isAdmin()): ?><label class="visually-hidden" for="d-owner">Responsável</label>
    <select class="form-select w-auto" id="d-owner" name="owner"><option value="">Toda a equipe</option><?php foreach ($owners as $id => $nm): ?><option value="<?= (int) $id ?>" <?= $owner === (int) $id ? 'selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn btn-outline-dark">Aplicar</button>
  </form>
</div>

<div class="row g-3 mb-2">
  <?= $tile('Leads novos', (string) $leads, '', 'users') ?>
  <?= $tile('Ganhos', $conv === null ? '—' : "$closed · $conv%", 'dos leads do período', 'check') ?>
  <?= $tile('Tempo até o 1º contato', $fc, 'mediana', 'clock') ?>
  <?= $tile('Ticket médio', $ticket === null ? '—' : money($ticket), 'propostas aceitas', 'coins') ?>
  <?= $tile('Propostas enviadas', money((float) $prop['sent_v']), (int) $prop['sent_n'] . ' propostas', 'file') ?>
  <?= $tile('Propostas aceitas', money((float) $prop['won_v']), (int) $prop['won_n'] . ' propostas', 'trend') ?>
  <?php if ($clicks !== null): ?>
  <?= $tile('Cliques no WhatsApp', (string) (int) ($clicks['whatsapp_click'] ?? 0), 'no site', 'whatsapp') ?>
  <?= $tile('Cliques em "Agendar"', (string) (int) ($clicks['schedule_cta_click'] ?? 0), 'no site', 'calendar') ?>
  <?php endif; ?>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-8">
    <div class="card card-body">
      <h2 class="h6">Leads por dia</h2>
      <svg class="chart" viewBox="0 0 <?= $W ?> <?= $H + 22 ?>" role="img" aria-labelledby="ld-t"><title id="ld-t">Leads por dia nos últimos <?= e(D::PERIODS[$days]) ?>: <?= $leads ?> no total</title>
        <line x1="0" y1="<?= $H ?>" x2="<?= $W ?>" y2="<?= $H ?>" class="chart-axis"/>
        <?php $i = 0; foreach ($perDay as $d => $v): $h = $v ? max(4, $v / $max * ($H - 12)) : 0; $x = $i * $bw + $gap / 2; ?>
          <g class="chart-hit"><rect x="<?= round($i * $bw, 2) ?>" y="0" width="<?= round($bw, 2) ?>" height="<?= $H ?>" fill="transparent"/>
          <?php if ($h): ?><path class="chart-bar" d="<?= sprintf('M%.2f %.2f v%.2f a4 4 0 0 1 4 -4 h%.2f a4 4 0 0 1 4 4 v%.2f z', $x, $H, -($h - 4), max(0, $bw - $gap - 8), $h - 4) ?>"/><?php endif; ?>
          <title><?= e(date('d/m', strtotime($d))) ?>: <?= $v ?> lead<?= $v === 1 ? '' : 's' ?></title></g>
        <?php $i++; endforeach; ?>
        <text x="0" y="<?= $H + 16 ?>" class="chart-label"><?= e(date('d/m', strtotime(array_key_first($perDay)))) ?></text>
        <text x="<?= $W ?>" y="<?= $H + 16 ?>" class="chart-label" text-anchor="end">hoje</text>
        <text x="<?= $W ?>" y="10" class="chart-label" text-anchor="end">máx. <?= $max ?>/dia</text>
      </svg>
      <details class="mt-2"><summary class="small">Ver em tabela</summary>
        <table class="table table-sm small mt-2"><thead><tr><th>Dia</th><th class="text-end">Leads</th></tr></thead><tbody>
        <?php foreach (array_reverse($perDay, true) as $d => $v): if (!$v) continue; ?><tr><td><?= e(date('d/m/Y', strtotime($d))) ?></td><td class="text-end"><?= $v ?></td></tr><?php endforeach; ?>
        </tbody></table></details>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-body h-100">
      <h2 class="h6">De onde vieram</h2>
      <?php if (!$byOrigin): ?><p class="small text-body-secondary mb-0">Sem leads no período.</p><?php endif; ?>
      <?php $om = max([1, ...array_values($byOrigin)]); foreach ($byOrigin as $o => $v): ?>
        <div class="mb-2"><div class="d-flex justify-content-between small"><span class="text-truncate me-2"><?= e($o) ?></span><span class="fw-semibold"><?= (int) $v ?></span></div>
          <svg class="chart-row" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="chart-bar" x="0" y="0" height="6" rx="2" width="<?= round($v / $om * 100, 2) ?>"/></svg></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($myTasks): ?>
<section class="card card-body mt-4" aria-labelledby="h-mytasks">
  <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0" id="h-mytasks">Suas tarefas para hoje</h2><a class="small" href="/tarefas">Ver todas</a></div>
  <ul class="task-list">
    <?php foreach ($myTasks as $t): [$dl, $dt] = due($t['due_at']); ?>
    <li><form method="post" action="/tarefas/<?= (int) $t['id'] ?>/feita"><?= csrf_field() ?><input type="hidden" name="back" value="/"><button class="task-check" aria-label="Concluir: <?= e($t['title']) ?>"></button></form>
      <span class="min-w-0 flex-grow-1"><span class="d-block text-truncate"><?= e($t['title']) ?></span><?php if ($t['client_id']): ?><small><a class="link-secondary" href="/clientes/<?= (int) $t['client_id'] ?>"><?= e($t['client_name']) ?></a></small><?php endif; ?></span>
      <span class="due due-<?= $dt ?>"><?= e($dl) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<h2 class="h6 text-body-secondary mt-4">Funil hoje <span class="fw-normal">(oportunidades<?= Auth::isAdmin() ? '' : ' dos seus clientes' ?>)</span></h2>
<div class="row g-3">
  <?php foreach (Clients::STAGES as $k => $label): ?>
  <div class="col-6 col-md-3 col-xl">
    <a class="card h-100 text-decoration-none" href="/funil"><div class="card-body">
      <div class="text-body-secondary small"><?= e($label) ?></div>
      <div class="fs-3 fw-bold tnum"><?= (int) ($byStage[$k] ?? 0) ?></div>
    </div></a>
  </div>
  <?php endforeach; ?>
</div>
