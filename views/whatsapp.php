<?php
use App\{Clients, WhatsApp};
$failed = $_SESSION['wa_failed'] ?? null; unset($_SESSION['wa_failed']);
if ($failed && (!$chat || $failed['chat'] !== (int) $chat['id'])) $failed = null;
$label = fn(array $w) => $w['client_name'] ?: ($w['name'] ?: $w['phone']);
$fmtPhone = fn(string $p) => preg_match('/^\+55(\d{2})(\d{4,5})(\d{4})$/', $p, $m) ? "({$m[1]}) {$m[2]}-{$m[3]}" : $p;
$initials = function (string $n): string { $p = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', '', $n))) ?: []; return mb_strtoupper(mb_substr($p[0] ?? '#', 0, 1) . mb_substr(count($p) > 1 ? end($p) : '', 0, 1)) ?: '#'; };
$when = function (?string $ts): string { if (!$ts) return ''; $t = strtotime($ts); return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day')) ? 'Ontem' : date('d/m', $t)); };
$day = function (string $ts): string { $d = date('Y-m-d', strtotime($ts)); return $d === date('Y-m-d') ? 'Hoje' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'Ontem' : date('d/m/Y', strtotime($ts))); };
$mode = WhatsApp::driver();
?>
<div class="page-head">
  <div><h1 class="h3">WhatsApp</h1><p>Conversas do número da empresa. Responda por aqui e cadastre quem ainda não é cliente.</p></div>
  <span class="wa-conn wa-conn-<?= $mode === 'drope' ? 'on' : ($mode === 'teste' ? 'test' : 'off') ?>"><span aria-hidden="true"></span><?= $mode === 'drope' ? 'Conectado' : ($mode === 'teste' ? 'Modo de teste: nada é enviado' : 'Não conectado: só leitura') ?></span>
</div>

<div class="wa card<?= $chat ? ' has-chat' : '' ?>">
  <aside class="wa-list" aria-label="Conversas">
    <form class="wa-search" method="get" action="/whatsapp" role="search">
      <label class="visually-hidden" for="wa-q">Buscar conversa</label>
      <?= icon('search', 'icon icon-sm') ?><input class="form-control" id="wa-q" name="q" value="<?= e($q) ?>" placeholder="Buscar nome ou número" autocomplete="off">
    </form>
    <?php if (!$chats): ?>
      <div class="wa-empty-list"><?= icon('whatsapp') ?><p><?= $q !== '' ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda. Quando alguém mandar mensagem para o WhatsApp da empresa, ela aparece aqui.' ?></p></div>
    <?php else: ?>
    <ul class="wa-chats">
      <?php foreach ($chats as $w): $active = $chat && (int) $chat['id'] === (int) $w['id']; ?>
      <li><a class="wa-chat<?= $active ? ' is-active' : '' ?><?= $w['unread'] && !$active ? ' is-unread' : '' ?>" href="/whatsapp/<?= (int) $w['id'] ?>"<?= $active ? ' aria-current="true"' : '' ?>>
        <span class="wa-avatar<?= $w['client_id'] ? ' is-client' : '' ?>" aria-hidden="true"><?= e($initials($label($w))) ?></span>
        <span class="wa-chat-main">
          <span class="wa-chat-top"><strong class="text-truncate"><?= e($label($w)) ?></strong><time datetime="<?= e((string) $w['last_message_at']) ?>"><?= e($when($w['last_message_at'])) ?></time></span>
          <span class="wa-chat-bottom"><span class="text-truncate"><?= e((string) $w['last_preview']) ?></span>
            <?php if ($w['unread'] && !$active): ?><span class="wa-unread" aria-label="<?= (int) $w['unread'] ?> não lidas"><?= (int) $w['unread'] > 99 ? '99+' : (int) $w['unread'] ?></span>
            <?php elseif (!$w['client_id']): ?><span class="wa-tag">Novo contato</span><?php endif; ?></span>
        </span>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </aside>

  <section class="wa-pane" aria-label="Conversa">
  <?php if (!$chat): ?>
    <div class="wa-placeholder"><span class="wa-placeholder-ico"><?= icon('whatsapp') ?></span><h2 class="h5">Escolha uma conversa</h2><p>As mensagens chegam sozinhas. Quem ainda não é cliente aparece como <strong>Novo contato</strong>, com o botão para cadastrar.</p></div>
  <?php else: ?>
    <header class="wa-head">
      <a class="wa-back btn btn-sm btn-outline-dark d-lg-none" href="/whatsapp" aria-label="Voltar para as conversas"><?= icon('chevron', 'icon icon-sm wa-back-ico') ?></a>
      <span class="wa-avatar<?= $chat['client_id'] ? ' is-client' : '' ?>" aria-hidden="true"><?= e($initials($label($chat))) ?></span>
      <div class="wa-head-who">
        <strong class="text-truncate d-block"><?= e($label($chat)) ?></strong>
        <small><?= e($fmtPhone($chat['phone'])) ?><?php if ($chat['client_id']): ?> · <?= e(Clients::STAGES[$chat['stage']] ?? '') ?><?php elseif ($chat['name']): ?><span class="d-none d-sm-inline"> · nome no WhatsApp</span><?php endif; ?></small>
      </div>
      <?php if ($chat['client_id']): ?>
        <a class="btn btn-sm btn-outline-dark" href="/clientes/<?= (int) $chat['client_id'] ?>"><?= icon('user', 'icon icon-sm') ?><span><span class="d-none d-sm-inline">Abrir </span>ficha</span></a>
      <?php else: ?>
        <a class="btn btn-sm btn-dark" href="/clientes/novo?wa=<?= (int) $chat['id'] ?>"><?= icon('plus', 'icon icon-sm') ?><span>Cadastrar<span class="d-none d-sm-inline"> cliente</span></span></a>
      <?php endif; ?>
    </header>

    <div class="wa-msgs" id="wa-msgs" data-poll="/whatsapp/<?= (int) $chat['id'] ?>/mensagens" data-last="<?= (int) ($messages ? end($messages)['id'] : 0) ?>" aria-live="polite">
      <?php $lastDay = null; foreach ($messages as $m): $d = $day($m['created_at']); ?>
        <?php if ($d !== $lastDay): $lastDay = $d; ?><div class="wa-day"><span><?= e($d) ?></span></div><?php endif; ?>
        <div class="wa-msg<?= $m['from_me'] ? ' is-mine' : '' ?><?= $m['status'] === 'falhou' ? ' is-failed' : '' ?>">
          <?php $src = '/whatsapp/midia/' . (int) $m['id']; $cap = $m['body'] !== WhatsApp::label($m['kind']); ?>
          <?php if ($m['kind'] === 'imagem'): ?><a class="wa-media" href="<?= $src ?>" target="_blank" rel="noopener"><img src="<?= $src ?>" alt="Imagem"></a>
          <?php elseif ($m['kind'] === 'audio'): ?><audio class="wa-audio" controls preload="none" src="<?= $src ?>"></audio>
          <?php elseif ($m['kind'] === 'video'): ?><video class="wa-video" controls preload="none" src="<?= $src ?>"></video>
          <?php elseif ($m['kind'] === 'documento'): ?><a class="wa-doc" href="<?= $src ?>" target="_blank" rel="noopener"><?= icon('file', 'icon icon-sm') ?><span class="text-truncate"><?= e($m['media_name'] ?: 'Documento') ?></span></a><?php endif; ?>
          <?php if ($m['kind'] === 'texto' || $m['kind'] === 'outro' || $cap): ?><p><?= nl2br(e($m['body'])) ?></p><?php endif; ?>
          <span class="wa-meta"><?php if ($m['from_me'] && $m['user_name']): ?><?= e(explode(' ', $m['user_name'])[0]) ?> · <?php endif; ?><?= e(date('H:i', strtotime($m['created_at']))) ?><?php if ($m['status'] === 'falhou'): ?> · não enviada<?php elseif ($m['status'] === 'enviando'): ?> · enviando<?php endif; ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$messages): ?><p class="wa-none">Sem mensagens nesta conversa.</p><?php endif; ?>
      <span id="fim"></span>
    </div>

    <?php if ($failed): ?>
      <div class="wa-failed" role="alert"><span><?= e($failed['error'] ?? 'Não foi possível enviar.') ?></span>
        <a class="btn btn-sm btn-outline-dark" href="https://wa.me/<?= e(ltrim($chat['phone'], '+')) ?>?text=<?= rawurlencode($failed['text']) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'icon icon-sm') ?>Abrir no WhatsApp</a></div>
    <?php endif; ?>

    <?php if ($canSend): ?>
    <form class="wa-compose" method="post" action="/whatsapp/<?= (int) $chat['id'] ?>/enviar" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label class="btn btn-outline-dark wa-attach" title="Anexar foto, áudio, vídeo ou documento (até 15 MB)"><?= icon('plus', 'icon icon-sm') ?><span class="visually-hidden">Anexar arquivo</span>
        <input type="file" name="file" id="wa-file" hidden accept="image/*,audio/*,video/mp4,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.dwg,.zip,.txt"></label>
      <div class="wa-input">
        <div class="wa-chosen" id="wa-chosen" hidden><?= icon('file', 'icon icon-sm') ?><span class="text-truncate" id="wa-chosen-name"></span><button type="button" class="btn-close" id="wa-chosen-clear" aria-label="Remover anexo"></button></div>
        <label class="visually-hidden" for="wa-text">Mensagem</label>
        <textarea class="form-control" id="wa-text" name="text" rows="1" maxlength="4000" placeholder="Escreva uma mensagem"></textarea>
      </div>
      <button class="btn btn-dark" type="submit">Enviar</button>
    </form>
    <p class="wa-hint">Enter envia · Shift+Enter quebra a linha · + anexa foto, áudio, vídeo ou documento (até 15 MB)</p>
    <?php else: ?>
    <div class="wa-compose is-off"><span>O WhatsApp ainda não está conectado ao CRM. Responda pelo celular por enquanto.</span>
      <a class="btn btn-sm btn-outline-dark" href="https://wa.me/<?= e(ltrim($chat['phone'], '+')) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'icon icon-sm') ?>Abrir no WhatsApp</a></div>
    <?php endif; ?>
  <?php endif; ?>
  </section>
</div>
