<?php
use App\{Auth, Clients};
$editing = !empty($c['id']);
$v = fn(string $k) => e($c[$k] ?? '');
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="err-' . $k . '">' . e($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="err-' . $k : '';
?>
<h1 class="h3 mb-4"><?= $editing ? 'Editar cliente' : 'Novo cliente' ?></h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Confira os campos destacados.</div><?php endif; ?>
<form method="post" action="<?= $editing ? '/clientes/' . (int) $c['id'] : '/clientes' ?>" class="card card-body mw-760" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-12"><label class="form-label" for="c-name">Nome *</label><input class="form-control<?= $inv('name') ?>" id="c-name" name="name" value="<?= $v('name') ?>" required maxlength="160" autocomplete="off"><?= $err('name') ?></div>
    <div class="col-md-6"><label class="form-label" for="c-wa">WhatsApp</label><input class="form-control<?= $inv('whatsapp') ?>" id="c-wa" name="whatsapp" value="<?= $v('whatsapp') ?>" inputmode="tel" placeholder="(61) 99999-9999" maxlength="25"><?= $err('whatsapp') ?></div>
    <div class="col-md-6"><label class="form-label" for="c-email">E-mail</label><input class="form-control<?= $inv('email') ?>" id="c-email" type="email" name="email" value="<?= $v('email') ?>" maxlength="190"><?= $err('email') ?></div>
    <div class="col-md-6"><label class="form-label" for="c-city">Cidade / bairro</label><input class="form-control" id="c-city" name="city" value="<?= $v('city') ?>" maxlength="120" placeholder="Ex.: Águas Claras, DF"></div>
    <div class="col-md-6"><label class="form-label" for="c-area">Área (m²)</label><input class="form-control<?= $inv('area_m2') ?>" id="c-area" name="area_m2" value="<?= $v('area_m2') ?>" inputmode="decimal" maxlength="10"><?= $err('area_m2') ?></div>
    <div class="col-md-6"><label class="form-label" for="c-service">Serviço</label>
      <select class="form-select<?= $inv('service') ?>" id="c-service" name="service"><option value="">—</option>
        <?php foreach (Clients::SERVICES as $s): ?><option <?= ($c['service'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      </select><?= $err('service') ?></div>
    <div class="col-md-6"><label class="form-label" for="c-origin">Origem</label><input class="form-control" id="c-origin" name="origin" value="<?= $v('origin') ?>" maxlength="160" placeholder="Ex.: indicação, instagram, google / df / interiores"></div>
    <?php if (Auth::isAdmin()): ?>
    <div class="col-md-6"><label class="form-label" for="c-owner">Responsável</label>
      <select class="form-select" id="c-owner" name="owner_id"><option value="">Ninguém</option>
        <?php foreach ($owners as $id => $n): ?><option value="<?= (int) $id ?>" <?= (int) ($c['owner_id'] ?? 0) === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <input type="hidden" name="gclid" value="<?= $v('gclid') ?>">
  </div>
  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-dark">Salvar</button>
    <a class="btn btn-outline-secondary" href="<?= $editing ? '/clientes/' . (int) $c['id'] : '/clientes' ?>">Cancelar</a>
  </div>
</form>
