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
    <div class="col-md-6"><label class="form-label" for="c-city">Cidade</label><input class="form-control" id="c-city" name="city" value="<?= $v('city') ?>" maxlength="120" placeholder="Ex.: Brasília"></div>
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

    <fieldset class="col-12 form-group-sec"><legend>Documentos e endereço <small>para o contrato e a cobrança</small></legend>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="c-doc">CPF ou CNPJ</label><input class="form-control<?= $inv('document') ?>" id="c-doc" name="document" value="<?= $v('document') ?>" inputmode="numeric" maxlength="20" autocomplete="off"><?= $err('document') ?></div>
        <div class="col-md-6"><label class="form-label" for="c-rg">RG</label><input class="form-control" id="c-rg" name="rg" value="<?= $v('rg') ?>" maxlength="20" autocomplete="off"></div>
        <div class="col-md-4"><label class="form-label" for="c-zip">CEP</label><input class="form-control<?= $inv('address_zip') ?>" id="c-zip" name="address_zip" value="<?= $v('address_zip') ?>" inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000"><?= $err('address_zip') ?></div>
        <div class="col-md-8"><label class="form-label" for="c-street">Rua / quadra</label><input class="form-control" id="c-street" name="address_street" value="<?= $v('address_street') ?>" maxlength="160" autocomplete="address-line1"></div>
        <div class="col-md-3"><label class="form-label" for="c-num">Número</label><input class="form-control" id="c-num" name="address_number" value="<?= $v('address_number') ?>" maxlength="20"></div>
        <div class="col-md-5"><label class="form-label" for="c-extra">Complemento</label><input class="form-control" id="c-extra" name="address_extra" value="<?= $v('address_extra') ?>" maxlength="80" autocomplete="address-line2" placeholder="Apto, bloco, lote"></div>
        <div class="col-md-4"><label class="form-label" for="c-district">Bairro</label><input class="form-control" id="c-district" name="address_district" value="<?= $v('address_district') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label" for="c-uf">UF</label><select class="form-select<?= $inv('address_state') ?>" id="c-uf" name="address_state"><option value="">—</option><?php foreach (Clients::UF as $uf): ?><option <?= ($c['address_state'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option><?php endforeach; ?></select><?= $err('address_state') ?></div>
      </div>
    </fieldset>

    <fieldset class="col-12 form-group-sec"><legend>Imóvel do projeto</legend>
      <div class="row g-3">
        <div class="col-12"><label class="form-label" for="c-paddr">Endereço do imóvel</label><input class="form-control" id="c-paddr" name="property_address" value="<?= $v('property_address') ?>" maxlength="255" placeholder="Deixe vazio se for o mesmo do endereço acima"></div>
        <div class="col-md-4"><label class="form-label" for="c-ptype">Tipo</label><select class="form-select" id="c-ptype" name="property_type"><option value="">—</option><?php foreach (Clients::PROPERTY_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($c['property_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="c-pstatus">Situação</label><select class="form-select" id="c-pstatus" name="property_status"><option value="">—</option><?php foreach (Clients::PROPERTY_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= ($c['property_status'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="c-condo">Condomínio / edifício</label><input class="form-control" id="c-condo" name="property_condo" value="<?= $v('property_condo') ?>" maxlength="120"></div>
      </div>
    </fieldset>

    <fieldset class="col-12 form-group-sec"><legend>Pessoal e preferências</legend>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="c-birth">Nascimento</label><input class="form-control<?= $inv('birth_date') ?>" type="date" id="c-birth" name="birth_date" value="<?= $v('birth_date') ?>" max="<?= date('Y-m-d') ?>"><?= $err('birth_date') ?></div>
        <div class="col-md-8"><label class="form-label" for="c-prof">Profissão</label><input class="form-control" id="c-prof" name="profession" value="<?= $v('profession') ?>" maxlength="120"></div>
        <div class="col-md-6"><label class="form-label" for="c-partner">Cônjuge / outro decisor</label><input class="form-control" id="c-partner" name="partner_name" value="<?= $v('partner_name') ?>" maxlength="160"></div>
        <div class="col-md-6"><label class="form-label" for="c-pwa">WhatsApp do decisor</label><input class="form-control<?= $inv('partner_whatsapp') ?>" id="c-pwa" name="partner_whatsapp" value="<?= $v('partner_whatsapp') ?>" inputmode="tel" maxlength="25"><?= $err('partner_whatsapp') ?></div>
        <div class="col-md-4"><label class="form-label" for="c-pref">Prefere contato por</label><select class="form-select" id="c-pref" name="contact_pref"><option value="">—</option><?php foreach (Clients::CONTACT_PREF as $k => $l): ?><option value="<?= $k ?>" <?= ($c['contact_pref'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="c-time">Melhor horário</label><input class="form-control" id="c-time" name="contact_time" value="<?= $v('contact_time') ?>" maxlength="60" placeholder="Ex.: depois das 18h"></div>
        <div class="col-md-4"><label class="form-label" for="c-ref">Como nos conheceu</label><input class="form-control" id="c-ref" name="referral" value="<?= $v('referral') ?>" maxlength="160" placeholder="Ex.: indicação da Ana"></div>
      </div>
    </fieldset>
  </div>
  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-dark">Salvar</button>
    <a class="btn btn-outline-secondary" href="<?= $editing ? '/clientes/' . (int) $c['id'] : '/clientes' ?>">Cancelar</a>
  </div>
</form>
