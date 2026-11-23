<?php
$editing = !empty($p);
$action = $editing ? '/propostas/' . (int) $p['id'] : '/clientes/' . (int) $c['id'] . '/propostas';
$area = (float) ($c['area_m2'] ?? 0);
$qtyFor = function (array $i) use ($d, $area, $suggested, $editing) {
    if (array_key_exists($i['code'], $d['qty'])) return $d['qty'][$i['code']];
    if ($editing) return '';
    return $i['unit'] === 'm2' ? ($area ?: '') : ($i['code'] === $suggested ? 1 : '');
};
$ta = fn(string $k, int $rows, string $label, string $help = '') => '<div class="mb-3"><label class="form-label" for="f-' . $k . '">' . e($label) . '</label>' . ($help ? '<div class="form-text mt-0 mb-1">' . e($help) . '</div>' : '') . '<textarea class="form-control" id="f-' . $k . '" name="' . $k . '" rows="' . $rows . '">' . e($d[$k] ?? '') . '</textarea></div>';
?>
<a class="small link-secondary" href="<?= $editing ? '/propostas/' . (int) $p['id'] : '/clientes/' . (int) $c['id'] ?>">← <?= e($editing ? 'Proposta #' . $p['id'] : $c['name']) ?></a>
<div class="page-head mt-1"><div><h1 class="h3"><?= $editing ? 'Editar proposta' : 'Nova proposta' ?></h1><p>Para <?= e($c['name']) ?><?= $editing && $p['status'] !== 'rascunho' ? '. Como já foi enviada, salvar cria a versão ' . ((int) $p['version'] + 1) . '.' : '' ?></p></div></div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" action="<?= $action ?>" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-8 d-grid gap-4">
      <section class="card card-body p-4">
        <h2 class="h6 mb-3">1. Identificação</h2>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label" for="f-title">Título</label><input class="form-control" id="f-title" name="title" required maxlength="160" value="<?= e($d['title']) ?>"></div>
          <div class="col-md-4"><label class="form-label" for="f-valid">Válida até</label><input class="form-control" id="f-valid" type="date" name="valid_until" required min="<?= date('Y-m-d') ?>" value="<?= e($d['valid_until']) ?>"></div>
        </div>
        <div class="mt-3"><?= $ta('project_summary', 3, 'Entendimento do projeto', 'O que entendemos do pedido do cliente, com as palavras dele.') ?></div>
      </section>
      <section class="card card-body p-4">
        <h2 class="h6 mb-1">2. Escopo</h2>
        <p class="small text-body-secondary mb-3">Pré-preenchido pelo modelo do serviço. Ajuste para este cliente. Uma linha = um item da lista.</p>
        <?= $ta('scope', 3, 'O que vamos fazer') ?>
        <?= $ta('deliverables', 5, 'Entregáveis', 'Uma linha por entregável.') ?>
        <?= $ta('exclusions', 3, 'O que não está incluído', 'Uma linha por item.') ?>
      </section>
      <section class="card card-body p-4">
        <h2 class="h6 mb-1">3. Investimento</h2>
        <p class="small text-body-secondary mb-3">Deixe em branco o que não entra.</p>
        <div class="table-responsive"><table class="table align-middle mb-3">
          <thead><tr><th>Item</th><th>Preço</th><th class="w-9rem">Quantidade</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): $unit = ['fixo' => 'un.', 'm2' => 'm²', 'hora' => 'h'][$i['unit']]; ?>
            <tr><td><label for="q-<?= e($i['code']) ?>"><?= e($i['label']) ?></label><?= $i['code'] === $suggested ? ' <span class="badge text-bg-light">faixa sugerida pela área</span>' : '' ?></td>
              <td class="small text-nowrap"><?= e(money((float) $i['price'])) ?>/<?= e($unit) ?><?= $i['min_total'] ? '<br>mín. ' . e(money((float) $i['min_total'])) : '' ?></td>
              <td><input class="form-control form-control-sm" id="q-<?= e($i['code']) ?>" name="qty[<?= e($i['code']) ?>]" inputmode="decimal" value="<?= e((string) $qtyFor($i)) ?>" aria-label="Quantidade (<?= e($unit) ?>)"></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <fieldset class="mb-3"><legend class="form-label">Etapas que o cliente já pagou</legend>
          <p class="small text-body-secondary mb-2">Só a etapa mais alta é abatida, e só do projeto completo (itens por m²).</p>
          <?php foreach ($items as $i): if ((int) $i['credit_rank'] === 0) continue; ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="paid-<?= e($i['code']) ?>" name="paid[]" value="<?= e($i['code']) ?>" <?= in_array($i['code'], $d['paid'], true) ? 'checked' : '' ?>><label class="form-check-label" for="paid-<?= e($i['code']) ?>"><?= e($i['label']) ?> (<?= e(money((float) $i['price'])) ?>)</label></div>
          <?php endforeach; ?>
        </fieldset>
        <?= $ta('payment_terms', 3, 'Forma de pagamento', 'Combinada com este cliente. Ex.: entrada na assinatura e o restante na entrega, PIX ou transferência.') ?>
      </section>
      <section class="card card-body p-4">
        <h2 class="h6 mb-1">4. Cronograma</h2>
        <p class="small text-body-secondary mb-3">Preencha o prazo das etapas que entram. Etapas sem prazo não aparecem na proposta.</p>
        <div class="d-grid gap-2 schedule">
          <?php $rows = $d['schedule']; for ($k = count($rows); $k < count($rows) + 2; $k++) $extra[] = $k; foreach (array_merge($rows, [['stage' => '', 'duration_text' => ''], ['stage' => '', 'duration_text' => '']]) as $k => $row): ?>
          <div class="row g-2"><div class="col-7"><label class="visually-hidden" for="st-<?= $k ?>">Etapa</label><input class="form-control" id="st-<?= $k ?>" name="stage[<?= $k ?>]" value="<?= e($row['stage']) ?>" maxlength="120" placeholder="Etapa"></div>
            <div class="col-5"><label class="visually-hidden" for="du-<?= $k ?>">Prazo</label><input class="form-control" id="du-<?= $k ?>" name="duration[<?= $k ?>]" value="<?= e($row['duration_text']) ?>" maxlength="80" placeholder="Ex.: 10 dias úteis"></div></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="card card-body p-4">
        <h2 class="h6 mb-3">5. Revisões e condições</h2>
        <?= $ta('revision_policy', 3, 'Revisões e alterações') ?>
        <?= $ta('notes', 3, 'Outras condições') ?>
      </section>
    </div>
    <aside class="col-xl-4">
      <div class="card card-body p-4 sticky-xl">
        <h2 class="h6">O que o cliente vai ver</h2>
        <ol class="small text-body-secondary ps-3 mb-4">
          <li>Capa com o título e o nome dele</li><li>Sobre o estúdio</li><li>Entendimento do projeto</li><li>Escopo, entregáveis e exclusões</li><li>Cronograma</li><li>Investimento e forma de pagamento</li><li>Revisões e condições</li><li>Projetos do estúdio</li><li>Aceite com nome completo</li>
        </ol>
        <button class="btn btn-dark btn-lg w-100"><?= $editing ? ($p['status'] === 'rascunho' ? 'Salvar rascunho' : 'Criar nova versão') : 'Criar proposta' ?></button>
        <p class="small text-body-secondary mt-2 mb-0">Fica como rascunho até você enviar por e-mail ou WhatsApp.</p>
      </div>
    </aside>
  </div>
</form>
