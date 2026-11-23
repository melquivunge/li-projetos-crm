<div class="page-head"><div><h1 class="h3">Modelos de proposta</h1><p>Texto inicial de cada serviço. Valem para as próximas propostas; cada proposta pode ser ajustada.</p></div>
  <div class="seg" role="group" aria-label="Seção"><a class="seg-btn" href="/precos">Preços</a><a class="seg-btn" aria-current="page" href="/precos/modelos">Propostas</a><a class="seg-btn" href="/briefings/modelos">Briefing</a></div></div>
<form method="post" action="/precos/modelos" class="d-grid gap-4">
  <?= csrf_field() ?>
  <?php foreach ($rows as $t): $id = (int) $t['id']; ?>
  <section class="card card-body p-4">
    <h2 class="h5 mb-3"><?= e($t['service']) ?></h2>
    <div class="row g-3">
      <div class="col-lg-6"><label class="form-label" for="s-<?= $id ?>">O que vamos fazer</label><textarea class="form-control" id="s-<?= $id ?>" name="t[<?= $id ?>][scope]" rows="4"><?= e($t['scope']) ?></textarea></div>
      <div class="col-lg-6"><label class="form-label" for="d-<?= $id ?>">Entregáveis (um por linha)</label><textarea class="form-control" id="d-<?= $id ?>" name="t[<?= $id ?>][deliverables]" rows="4"><?= e($t['deliverables']) ?></textarea></div>
      <div class="col-lg-6"><label class="form-label" for="x-<?= $id ?>">Não está incluído (um por linha)</label><textarea class="form-control" id="x-<?= $id ?>" name="t[<?= $id ?>][exclusions]" rows="3"><?= e($t['exclusions']) ?></textarea></div>
      <div class="col-lg-6"><label class="form-label" for="r-<?= $id ?>">Revisões e alterações</label><textarea class="form-control" id="r-<?= $id ?>" name="t[<?= $id ?>][revision_policy]" rows="3"><?= e($t['revision_policy']) ?></textarea></div>
    </div>
  </section>
  <?php endforeach; ?>
  <div><button class="btn btn-dark btn-lg">Salvar modelos</button></div>
</form>
