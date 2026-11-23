<?php use App\Auth; ?>
<div class="page-head"><div><h1 class="h3">Usuários</h1><p>Uma pessoa pode ter vários papéis. Admin vê e faz tudo.</p></div></div>
<?php foreach ($errors as $er): ?><div class="alert alert-danger" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<div class="row g-4">
  <div class="col-xl-8 d-grid gap-3 align-content-start">
    <?php foreach ($users as $u): ?>
    <div class="card card-body">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <?= avatar($u, 40) ?>
        <div class="min-w-0 flex-grow-1"><strong class="d-block"><?= e($u['name']) ?></strong><small class="text-body-secondary"><?= e($u['email']) ?></small></div>
        <?= $u['active'] ? '<span class="badge status-aceita">Ativo</span>' : '<span class="badge status-recusada">Inativo</span>' ?>
        <form method="post" action="/usuarios/<?= (int) $u['id'] ?>/ativo"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark"><?= $u['active'] ? 'Desativar' : 'Ativar' ?></button></form>
      </div>
      <form method="post" action="/usuarios/<?= (int) $u['id'] ?>/papeis" class="d-flex flex-wrap align-items-center gap-2 mt-3">
        <?= csrf_field() ?>
        <?php foreach (Auth::ROLES as $k => $l): ?><label class="chip-toggle"><input type="checkbox" name="roles[]" value="<?= $k ?>" <?= in_array($k, $u['roles'], true) ? 'checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?>
        <button class="btn btn-sm btn-dark">Salvar papéis</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="col-xl-4">
    <form method="post" action="/usuarios" class="card card-body p-4" novalidate>
      <h2 class="h6">Novo usuário</h2>
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label" for="u-name">Nome</label><input class="form-control" id="u-name" name="name" required maxlength="120"></div>
      <div class="mb-2"><label class="form-label" for="u-email">E-mail</label><input class="form-control" id="u-email" type="email" name="email" required maxlength="190"></div>
      <fieldset class="mb-2"><legend class="form-label">Papéis</legend>
        <?php foreach (Auth::ROLES as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="nr-<?= $k ?>" name="roles[]" value="<?= $k ?>" <?= $k === 'comercial' ? 'checked' : '' ?>><label class="form-check-label" for="nr-<?= $k ?>"><?= e($l) ?></label></div><?php endforeach; ?>
      </fieldset>
      <div class="mb-3"><label class="form-label" for="u-pass">Senha inicial (mín. 10 caracteres)</label><input class="form-control" id="u-pass" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
      <button class="btn btn-dark">Criar usuário</button>
    </form>
  </div>
</div>
