<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h1 class="h3 mb-0">Tabela de preços</h1><div class="seg" role="group" aria-label="Seção"><a class="seg-btn" aria-current="page" href="/precos">Preços</a><a class="seg-btn" href="/precos/modelos">Propostas</a><a class="seg-btn" href="/briefings/modelos">Briefing</a></div></div>
<p class="text-body-secondary small mb-4">Vale para as próximas propostas. As já criadas não mudam. Lembre de manter o site igual (<code>site.config.js</code>).</p>
<form method="post" action="/precos" class="card card-body mw-860">
  <?= csrf_field() ?>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Item</th><th>Unidade</th><th class="w-9rem">Preço (R$)</th><th class="w-9rem">Mínimo (R$)</th><th>Ativo</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): $id = (int) $i['id']; ?>
      <tr><td><?= e($i['label']) ?></td><td><?= e(['fixo' => 'fixo', 'm2' => 'por m²', 'hora' => 'por hora'][$i['unit']]) ?></td>
        <td><input class="form-control form-control-sm" name="price[<?= $id ?>]" value="<?= e($i['price']) ?>" inputmode="decimal" aria-label="Preço de <?= e($i['label']) ?>"></td>
        <td><input class="form-control form-control-sm" name="min_total[<?= $id ?>]" value="<?= e($i['min_total'] ?? '') ?>" inputmode="decimal" aria-label="Mínimo de <?= e($i['label']) ?>"></td>
        <td><input class="form-check-input" type="checkbox" name="active[<?= $id ?>]" <?= $i['active'] ? 'checked' : '' ?> aria-label="Ativo"></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div><button class="btn btn-dark">Salvar preços</button></div>
</form>
