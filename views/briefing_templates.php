<div class="page-head"><div><h1 class="h3">Perguntas do briefing</h1><p>Uma pergunta por linha: <code>Pergunta | tipo | opções</code>. Tipos: text, textarea, number, select (opções separadas por ;). Valem para os próximos briefings.</p></div>
  <div class="seg" role="group" aria-label="Seção"><a class="seg-btn" href="/precos">Preços</a><a class="seg-btn" href="/precos/modelos">Propostas</a><a class="seg-btn" aria-current="page" href="/briefings/modelos">Briefing</a></div></div>
<form method="post" action="/briefings/modelos" class="d-grid gap-4">
  <?= csrf_field() ?>
  <?php foreach ($rows as $t): ?>
  <section class="card card-body p-4"><label class="h5 mb-3" for="bq-<?= (int) $t['id'] ?>"><?= e($t['service']) ?></label>
    <textarea class="form-control font-monospace small" id="bq-<?= (int) $t['id'] ?>" name="q[<?= (int) $t['id'] ?>]" rows="<?= min(16, substr_count($t['questions'], "\n") + 2) ?>"><?= e($t['questions']) ?></textarea></section>
  <?php endforeach; ?>
  <div><button class="btn btn-dark btn-lg">Salvar perguntas</button></div>
</form>
