<?php
use App\{Auth, Finance as F}; use App\Controllers\FinanceController as FC;
$today = date('Y-m-d');
$tile = fn(string $label, float $v, string $hint, string $cls = '') => '<div class="col-6 col-xl-3"><div class="card h-100 stat ' . $cls . '"><div class="card-body"><div class="label">' . e($label) . '</div><div class="value tnum">' . e(money($v)) . '</div><div class="hint">' . e($hint) . '</div></div></div></div>';
?>
<div class="page-head">
  <div><h1 class="h3">Financeiro</h1><p>Parcelas dos projetos. As parcelas nascem da forma de pagamento da proposta aceita; o pagamento é registrado aqui.</p></div>
</div>
<div class="row g-3 mb-4">
  <?= $tile('Vencido', (float) $tot['vencido'], (int) $counts['vencidas'] . ' parcela' . ((int) $counts['vencidas'] === 1 ? '' : 's') . ' em atraso', (float) $tot['vencido'] > 0 ? 'stat-late' : '') ?>
  <?= $tile('Vence em 7 dias', (float) $tot['semana'], 'para lembrar antes') ?>
  <?= $tile('A receber', (float) $tot['a_receber'], (int) $counts['abertas'] . ' parcela' . ((int) $counts['abertas'] === 1 ? '' : 's') . ' em dia') ?>
  <?= $tile('Recebido no mês', (float) $tot['recebido_mes'], 'desde o dia 1º') ?>
</div>

<div class="row g-4">
  <div class="<?= Auth::isAdmin() ? 'col-xl-9' : 'col-12' ?>">
    <nav class="tabs mb-3" aria-label="Filtrar">
      <?php foreach (FC::VIEWS as $k => $l): ?><a class="tab" href="/financeiro?ver=<?= $k ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?> <span><?= (int) $counts[$k] ?></span></a><?php endforeach; ?>
    </nav>
    <?php if (!$rows): ?>
      <div class="card card-body text-center py-5"><div class="mx-auto mb-2 text-body-secondary"><?= icon('coins') ?></div>
        <p class="mb-0 text-body-secondary"><?= ['vencidas' => 'Nenhuma parcela vencida. Ótimo.', 'abertas' => 'Nenhuma parcela a receber.', 'pagas' => 'Nenhum pagamento nos últimos 30 dias.'][$view] ?></p></div>
    <?php else: ?>
    <div class="card"><ul class="inst-list">
      <?php foreach ($rows as $i): $late = F::daysLate($i['due_date'], $today); $back = '/financeiro?ver=' . $view; ?>
      <li class="inst<?= $i['status'] === 'aberta' && $late > 0 ? ' is-late' : '' ?>">
        <div class="min-w-0 flex-grow-1">
          <a class="link-dark fw-semibold text-decoration-none" href="/projetos/<?= (int) $i['project_id'] ?>#financeiro"><?= e($i['client_name']) ?></a>
          <small class="d-block text-body-secondary text-truncate"><?= e($i['project_title']) ?> · <?= e($i['description'] ?: 'Parcela ' . $i['number']) ?></small>
          <?php if ($i['reminders']): ?><small class="d-block text-body-secondary">Cobrado <?= (int) $i['reminders'] ?>× · último em <?= e(date('d/m', strtotime($i['last_reminder_at']))) ?></small><?php endif; ?>
        </div>
        <div class="inst-amount text-end">
          <strong class="tnum d-block"><?= e(money((float) ($i['status'] === 'paga' ? ($i['paid_amount'] ?? $i['amount']) : $i['amount']))) ?></strong>
          <?php if ($i['status'] === 'paga'): ?><small class="text-body-secondary"><?= e(F::METHODS[$i['method']] ?? '') ?> · <?= e(date('d/m', strtotime($i['paid_at']))) ?></small>
          <?php elseif ($late > 0): ?><span class="due due-late"><?= $late ?> dia<?= $late > 1 ? 's' : '' ?> de atraso</span>
          <?php else: [$dl, $dt] = due($i['due_date']); ?><span class="due due-<?= $dt ?>"><?= e($late === 0 ? 'vence hoje' : 'vence ' . $dl) ?></span><?php endif; ?>
        </div>
        <div class="inst-actions">
          <?php if ($i['status'] === 'aberta'): ?>
            <form method="post" action="/parcelas/<?= (int) $i['id'] ?>/cobrar" target="_blank"><?= csrf_field() ?><input type="hidden" name="canal" value="whatsapp"><input type="hidden" name="back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-outline-dark" <?= $i['whatsapp'] ? '' : 'disabled title="Sem WhatsApp cadastrado"' ?>><?= icon('whatsapp', 'icon icon-sm') ?>Cobrar</button></form>
            <form method="post" action="/parcelas/<?= (int) $i['id'] ?>/cobrar"><?= csrf_field() ?><input type="hidden" name="canal" value="email"><input type="hidden" name="back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-outline-dark" <?= $i['email'] ? '' : 'disabled title="Sem e-mail cadastrado"' ?>>E-mail</button></form>
            <details class="pay">
              <summary class="btn btn-sm btn-dark">Recebi</summary>
              <form method="post" action="/parcelas/<?= (int) $i['id'] ?>/pagar" class="pay-form"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <label class="form-label small" for="pd-<?= (int) $i['id'] ?>">Dia</label><input class="form-control form-control-sm" type="date" id="pd-<?= (int) $i['id'] ?>" name="paid_at" value="<?= $today ?>" max="<?= $today ?>">
                <label class="form-label small" for="pm-<?= (int) $i['id'] ?>">Forma</label><select class="form-select form-select-sm" id="pm-<?= (int) $i['id'] ?>" name="method"><?php foreach (F::METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
                <label class="form-label small" for="pa-<?= (int) $i['id'] ?>">Valor recebido</label><input class="form-control form-control-sm" id="pa-<?= (int) $i['id'] ?>" name="paid_amount" inputmode="decimal" value="<?= e(number_format((float) $i['amount'], 2, ',', '.')) ?>">
                <button class="btn btn-sm btn-dark mt-2 w-100">Registrar pagamento</button>
              </form>
            </details>
          <?php else: ?>
            <form method="post" action="/parcelas/<?= (int) $i['id'] ?>/estornar"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-sm btn-link link-secondary">Estornar</button></form>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
  </div>
  <?php if (Auth::isAdmin()): ?>
  <div class="col-xl-3">
    <form method="post" action="/financeiro/config" class="card card-body p-4 d-grid gap-2"><?= csrf_field() ?>
      <h2 class="h6 mb-0">Configurações</h2>
      <p class="small text-body-secondary mb-1">A chave Pix vai no fim de cada mensagem de cobrança.</p>
      <label class="form-label small mb-0" for="cf-pix">Chave Pix</label><input class="form-control" id="cf-pix" name="pix_key" maxlength="140" value="<?= e($settings['pix_key']) ?>" placeholder="CNPJ, e-mail ou telefone">
      <label class="form-label small mb-0" for="cf-pixn">Nome do recebedor</label><input class="form-control" id="cf-pixn" name="pix_name" maxlength="120" value="<?= e($settings['pix_name']) ?>" placeholder="LI Projetos">
      <label class="form-label small mb-0" for="cf-cost">Custo da hora da equipe (R$)</label><input class="form-control" id="cf-cost" name="hourly_cost" inputmode="decimal" value="<?= e((float) $settings['hourly_cost'] ? number_format((float) $settings['hourly_cost'], 2, ',', '.') : '') ?>" placeholder="Ex.: 60,00">
      <p class="small text-body-secondary mb-1">Usado na margem do projeto: horas lançadas × custo.</p>
      <button class="btn btn-dark">Salvar</button>
    </form>
  </div>
  <?php endif; ?>
</div>
