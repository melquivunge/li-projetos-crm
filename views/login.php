<div class="auth">
  <section class="auth-form">
    <a href="/login" class="d-inline-block"><img src="/assets/logo-li-projetos.svg" alt="LI Projetos" width="190" height="44" class="auth-logo"></a>
    <div class="inner py-5">
      <h1 class="auth-title">Boas-vindas</h1>
      <p class="auth-sub">Entre para ver os leads, o funil e as propostas do estúdio.</p>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/login" novalidate>
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
      <p class="auth-foot mt-4 mb-0">Esqueceu a senha? Peça a uma das sócias para redefinir em Usuários.</p>
    </div>
    <p class="auth-foot mb-0">LI Projetos · Arquitetura e interiores · DF e Goiânia</p>
  </section>
  <aside class="auth-art" aria-hidden="true">
    <img src="/assets/login-render.webp" alt="" width="1200" height="900">
    <div class="auth-facts">
      <div><strong>10+</strong><span>anos de estúdio</span></div>
      <div><strong>50+</strong><span>projetos entregues</span></div>
      <div><strong>15.000</strong><span>m² projetados</span></div>
    </div>
  </aside>
</div>
