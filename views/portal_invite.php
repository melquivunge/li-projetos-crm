<div class="auth">
  <section class="auth-form">
    <a href="/portal/login" class="d-inline-block"><img src="<?= e(App\Brand::logoUrl(false)) ?>" alt="<?= e(App\Brand::name()) ?>" height="44" class="auth-logo"></a>
    <div class="inner py-5">
      <?php if (!$u): ?>
        <h1 class="auth-title">Link expirado</h1>
        <p class="auth-sub">Este convite já foi usado ou passou de <?= App\Portal::INVITE_DAYS ?> dias. Peça um novo à equipe pelo WhatsApp<?= App\Brand::get('company_whatsapp') ? ' ' . e(App\Brand::get('company_whatsapp')) : '' ?>.</p>
        <a class="btn btn-outline-dark" href="/portal/login">Ir para o login</a>
      <?php else: ?>
        <h1 class="auth-title">Olá, <?= e(explode(' ', trim($u['name']))[0]) ?></h1>
        <p class="auth-sub">Crie a sua senha para entrar no portal do cliente. O seu login é <strong><?= e($u['email']) ?></strong>.</p>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="/portal/convite/<?= e($token) ?>" novalidate>
          <?= csrf_field() ?>
          <input type="email" name="email" value="<?= e($u['email']) ?>" autocomplete="username" hidden>
          <div class="mb-3">
            <label for="password" class="form-label">Nova senha</label>
            <div class="pw-wrap">
              <input type="password" id="password" name="password" class="form-control form-control-lg" autocomplete="new-password" required minlength="10" maxlength="200" aria-describedby="pw-help" autofocus>
              <button class="pw-toggle" type="button" data-pw-toggle aria-controls="password" aria-pressed="false" aria-label="Mostrar senha"><?= icon('eye') ?></button>
            </div>
            <div id="pw-help" class="form-text">Pelo menos 10 caracteres.</div>
          </div>
          <div class="mb-4">
            <label for="password2" class="form-label">Repita a senha</label>
            <input type="password" id="password2" name="password2" class="form-control form-control-lg" autocomplete="new-password" required maxlength="200">
          </div>
          <button class="btn btn-dark btn-lg w-100">Criar senha e entrar</button>
        </form>
      <?php endif; ?>
    </div>
    <p class="auth-foot mb-0"><?= e(App\Brand::footer()) ?></p>
  </section>
  <aside class="auth-art" aria-hidden="true">
    <img src="/assets/login-render.webp" alt="" width="1200" height="900">
  </aside>
</div>
