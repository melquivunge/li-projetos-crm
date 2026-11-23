<div class="auth">
  <section class="auth-form">
    <a href="/portal/login" class="d-inline-block"><img src="<?= e(App\Brand::logoUrl(false)) ?>" alt="<?= e(App\Brand::name()) ?>" height="44" class="auth-logo"></a>
    <div class="inner py-5">
      <h1 class="auth-title">Portal do cliente</h1>
      <p class="auth-sub">Acompanhe as fases do seu projeto, veja os arquivos, aprove as entregas e confira os pagamentos.</p>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/portal/login" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label for="email" class="form-label">E-mail</label>
          <input type="email" id="email" name="email" class="form-control form-control-lg" value="<?= e($email) ?>" autocomplete="username" inputmode="email" required maxlength="190" autofocus>
        </div>
        <div class="mb-4">
          <label for="password" class="form-label">Senha</label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" class="form-control form-control-lg" autocomplete="current-password" required maxlength="200">
            <button class="pw-toggle" type="button" data-pw-toggle aria-controls="password" aria-pressed="false" aria-label="Mostrar senha"><?= icon('eye') ?></button>
          </div>
        </div>
        <button class="btn btn-dark btn-lg w-100">Entrar</button>
      </form>
      <p class="auth-foot mt-4 mb-0">Primeiro acesso ou esqueceu a senha? Peça um novo convite à equipe pelo WhatsApp<?= App\Brand::get('company_whatsapp') ? ' ' . e(App\Brand::get('company_whatsapp')) : '' ?>.</p>
    </div>
    <p class="auth-foot mb-0"><?= e(App\Brand::footer()) ?></p>
  </section>
  <aside class="auth-art" aria-hidden="true">
    <img src="/assets/login-render.webp" alt="" width="1200" height="900">
  </aside>
</div>
