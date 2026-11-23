<div class="text-center py-5">
  <h1 class="display-6"><?= (int) $code ?></h1>
  <p class="lead"><?= e($message) ?></p>
  <a href="<?= e($home ?? '/') ?>" class="btn btn-outline-dark"><?= ($home ?? '/') === '/portal' ? 'Voltar ao portal' : 'Voltar ao painel' ?></a>
</div>
