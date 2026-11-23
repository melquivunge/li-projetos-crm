<?php $open = in_array($p['status'], ['enviada', 'vista'], true) && !$expired; $err = $_SESSION['accept_error'] ?? null; unset($_SESSION['accept_error']); ?>
<div class="doc-page">
  <?php require __DIR__ . '/_proposal_doc.php'; ?>
  <section class="doc-accept card" id="aceite">
    <?php if ($open): ?>
      <h2 class="doc-accept-title">Aceite da proposta</h2>
      <p class="text-body-secondary">Ao aceitar, você concorda com o escopo, o investimento e as condições acima. Registramos o seu nome, a data e a versão da proposta.</p>
      <?php if ($err): ?><div class="alert alert-danger" role="alert"><?= e($err) ?></div><?php endif; ?>
      <form method="post" action="/p/<?= e($p['public_token']) ?>/resposta" class="d-grid gap-3">
        <?= csrf_field() ?><input type="hidden" name="answer" value="aceitar">
        <div><label class="form-label" for="signer">Seu nome completo</label><input class="form-control form-control-lg" id="signer" name="signer" required maxlength="160" autocomplete="name"></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="agree" name="agree" value="1" required><label class="form-check-label" for="agree">Li a proposta nº <?= (int) $p['id'] ?><?= (int) $p['version'] > 1 ? ' (versão ' . (int) $p['version'] . ')' : '' ?> e concordo com as condições.</label></div>
        <button class="btn btn-dark btn-lg"><?= icon('check', 'icon icon-sm') ?>Aceitar proposta</button>
      </form>
      <form method="post" action="/p/<?= e($p['public_token']) ?>/resposta" class="mt-3 text-center"><?= csrf_field() ?><input type="hidden" name="answer" value="recusar"><button class="btn btn-link link-secondary">Não vou seguir com esta proposta</button></form>
    <?php elseif ($p['status'] === 'aceita'): ?><p class="doc-state ok mb-0"><?= icon('check') ?>Proposta aceita. Obrigado! A nossa equipe vai falar com você pelo WhatsApp.</p>
    <?php elseif ($p['status'] === 'recusada'): ?><p class="doc-state mb-0">Proposta recusada. Se quiser rever algum ponto, é só chamar no WhatsApp.</p>
    <?php else: ?><p class="doc-state mb-0">Esta proposta venceu. Fale com a gente no WhatsApp para atualizar.</p><?php endif; ?>
  </section>
</div>
