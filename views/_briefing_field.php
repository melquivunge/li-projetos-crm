<?php
// One briefing question. Expects $q, $answers, $readonly.
$v = (string) ($answers[$q['key']] ?? ''); $fid = 'q-' . $q['key']; $fname = 'a[' . $q['key'] . ']';
?>
<?php if ($q['type'] === 'titulo'): ?><h3 class="brief-title"><?= e($q['label']) ?></h3><?php return; endif; ?>
<?php if ($q['type'] === 'roteiro'): ?><p class="brief-script"><?= e($q['label']) ?></p><?php return; endif; ?>
<div class="mb-3">
  <label class="form-label" for="<?= e($fid) ?>"><?= e($q['label']) ?></label>
  <?php if ($q['type'] === 'textarea'): ?><textarea class="form-control" id="<?= e($fid) ?>" name="<?= e($fname) ?>" rows="3" maxlength="4000" <?= $readonly ? 'disabled' : '' ?>><?= e($v) ?></textarea>
  <?php elseif ($q['type'] === 'select'): ?><select class="form-select" id="<?= e($fid) ?>" name="<?= e($fname) ?>" <?= $readonly ? 'disabled' : '' ?>><option value="">Escolha…</option><?php foreach ($q['options'] as $opt): ?><option <?= $v === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?></select>
  <?php else: ?><input class="form-control" id="<?= e($fid) ?>" name="<?= e($fname) ?>" value="<?= e($v) ?>" maxlength="300" <?= $q['type'] === 'number' ? 'inputmode="decimal"' : '' ?> <?= $readonly ? 'disabled' : '' ?>><?php endif; ?>
</div>
