<?php
use App\Controllers\ProfileController as PC;
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="err-' . $k . '">' . e($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="err-' . $k : '';
?>
<div class="page-head"><div><h1 class="h3">Meu perfil</h1><p>Como você aparece para a equipe e nas propostas.</p></div></div>
<div class="row g-4">
  <div class="col-xl-8">
    <form method="post" action="/perfil" enctype="multipart/form-data" class="card card-body p-4" novalidate>
      <?= csrf_field() ?>
      <h2 class="h6 mb-3">Dados pessoais</h2>
      <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
        <?php if (!empty($p['avatar'])): ?><img class="avatar avatar-96" id="avatarPreview" src="/avatar/<?= (int) $p['id'] ?>" alt="Sua foto" width="96" height="96">
        <?php else: ?><img class="avatar avatar-96" id="avatarPreview" alt="Prévia da nova foto" width="96" height="96" hidden><span id="avatarInitials"><?= avatar($p, 96) ?></span><?php endif; ?>
        <div>
          <label class="btn btn-outline-dark" for="avatarInput">Escolher foto</label>
          <input class="visually-hidden" type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp">
          <p class="small text-body-secondary mb-0 mt-1">JPG, PNG ou WebP, até 3 MB. Cortamos em quadrado.</p>
          <?php if (!empty($p['avatar'])): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="rmAvatar" name="remove_avatar" value="1"><label class="form-check-label small" for="rmAvatar">Remover foto</label></div><?php endif; ?>
          <?= $err('avatar') ?>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="pf-name">Nome</label><input class="form-control<?= $inv('name') ?>" id="pf-name" name="name" value="<?= e($p['name'] ?? '') ?>" required maxlength="120" autocomplete="name"><?= $err('name') ?></div>
        <div class="col-md-6"><label class="form-label" for="pf-job">Cargo</label><input class="form-control" id="pf-job" name="job_title" value="<?= e($p['job_title'] ?? '') ?>" maxlength="80" placeholder="Ex.: Arquiteta, Atendimento"></div>
        <div class="col-md-6"><label class="form-label" for="pf-phone">WhatsApp</label><input class="form-control<?= $inv('phone') ?>" id="pf-phone" name="phone" value="<?= e($p['phone'] ?? '') ?>" inputmode="tel" maxlength="25" placeholder="(61) 99999-9999" autocomplete="tel"><?= $err('phone') ?></div>
        <div class="col-md-6"><label class="form-label" for="pf-email">E-mail</label><input class="form-control" id="pf-email" value="<?= e($p['email'] ?? '') ?>" disabled><div class="form-text">Para trocar o e-mail, fale com uma sócia.</div></div>
        <div class="col-12"><label class="form-label" for="pf-bio">Sobre você</label><textarea class="form-control" id="pf-bio" name="bio" rows="3" maxlength="500" placeholder="Especialidade, formação, como gosta de atender…"><?= e($p['bio'] ?? '') ?></textarea></div>
      </div>
      <div class="mt-4"><button class="btn btn-dark">Salvar perfil</button></div>
    </form>
  </div>
  <div class="col-xl-4 d-grid gap-4 align-content-start">
    <form method="post" action="/perfil/tema" class="card card-body p-4">
      <?= csrf_field() ?><input type="hidden" name="back" value="/perfil">
      <fieldset><legend class="h6 mb-3">Tema</legend>
      <div class="row g-2">
        <?php foreach (PC::THEMES as $t => $label): ?>
        <div class="col-4"><label class="theme-opt"><input type="radio" name="theme" value="<?= $t ?>" <?= ($p['theme'] ?? 'brand') === $t ? 'checked' : '' ?> data-autosubmit>
          <span class="theme-swatch sw-<?= $t ?>"><i></i><span><b></b></span></span><span class="d-block small mt-2 fw-semibold text-center"><?= e($label) ?></span></label></div>
        <?php endforeach; ?>
      </div></fieldset>
      <noscript><button class="btn btn-outline-dark mt-3">Aplicar tema</button></noscript>
    </form>
    <form method="post" action="/perfil/senha" class="card card-body p-4">
      <?= csrf_field() ?>
      <h2 class="h6 mb-3">Trocar senha</h2>
      <div class="mb-2"><label class="form-label" for="pw-cur">Senha atual</label><input class="form-control" type="password" id="pw-cur" name="current_password" autocomplete="current-password" required></div>
      <div class="mb-2"><label class="form-label" for="pw-new">Nova senha</label><input class="form-control" type="password" id="pw-new" name="new_password" autocomplete="new-password" minlength="10" required><div class="form-text">Mínimo de 10 caracteres.</div></div>
      <div class="mb-3"><label class="form-label" for="pw-conf">Confirmar nova senha</label><input class="form-control" type="password" id="pw-conf" name="confirm_password" autocomplete="new-password" required></div>
      <button class="btn btn-outline-dark">Trocar senha</button>
    </form>
  </div>
</div>
