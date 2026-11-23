<?php
use App\{Auth, Brand, Clients, Db, Notify};
use App\Controllers\TaskController;
use App\Controllers\ProfileController;
$me = Auth::user();
$f = flash();
$theme = $me['theme'] ?? 'brand';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$is = fn(string $p) => $p === '/' ? $path === '/' : str_starts_with($path, $p);
$cur = fn(string $p) => $is($p) ? ' aria-current="page"' : '';
$unread = $me ? Notify::unreadCount((int) $me['id']) : 0;
$latest = $me ? Notify::latest((int) $me['id'], 6) : [];
[$sc, $sp] = Clients::scope();
$newLeads = $me && Auth::can('crm') ? (int) Db::q("SELECT COUNT(*) FROM opportunities o JOIN clients c ON c.id = o.client_id WHERE $sc AND o.stage = 'novo'", $sp)->fetchColumn() : 0;
$tc = $me ? TaskController::counts((int) $me['id']) : ['late' => 0, 'today' => 0];
$ago = function (string $ts): string {
    $s = time() - strtotime($ts);
    return $s < 60 ? 'agora' : ($s < 3600 ? intdiv($s, 60) . ' min' : ($s < 86400 ? intdiv($s, 3600) . ' h' : date('d/m', strtotime($ts))));
};
$hour = (int) date('G');
$greet = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($theme) ?>" data-bs-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $theme === 'dark' ? '#0D0D0C' : ($theme === 'light' ? '#F4F5F7' : '#F3EEE5') ?>">
<title>CRM <?= e(Brand::name()) ?></title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/assets/app/icon-180.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(App\Brand::name()) ?>">
<link rel="icon" href="/assets/logo-icon-li.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/app.css">
<?php if (Brand::css() !== ''): ?><link rel="stylesheet" href="/marca.css?v=<?= substr(md5(Brand::css()), 0, 8) ?>"><?php endif; ?>
</head>
<body>
<?php if (env('APP_ENV') === 'staging'): ?><div class="staging-bar" role="note">Ambiente de testes · dados fictícios · e-mails não são enviados</div><?php endif; ?>
<a class="visually-hidden-focusable btn btn-dark position-absolute m-2" href="#conteudo">Pular para o conteúdo</a>
<div class="app">
  <aside class="rail offcanvas-lg offcanvas-start" tabindex="-1" id="rail" aria-label="Menu principal">
    <div class="offcanvas-body">
      <div class="d-flex align-items-center justify-content-between">
        <a class="rail-logo" href="/"><img src="<?= e(Brand::logoUrl($theme !== 'light')) ?>" alt="<?= e(Brand::name()) ?>" height="34" class="rail-logo-img"></a>
        <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#rail" aria-label="Fechar menu"></button>
      </div>
      <nav>
        <p class="rail-group">Operação</p>
        <ul class="rail-nav">
          <li><a class="rail-link" href="/"<?= $cur('/') ?>><?= icon('home') ?>Painel</a></li>
          <?php if (Auth::can('tasks')): ?><li><a class="rail-link" href="/tarefas"<?= $cur('/tarefas') ?>><?= icon('check') ?>Tarefas<span class="count<?= $tc['late'] ? ' count-late' : '' ?>" data-live="tasks"<?= $tc['late'] + $tc['today'] ? '' : ' hidden' ?>><?= $tc['late'] + $tc['today'] ?></span></a></li><?php endif; ?>
          <?php if (Auth::can('crm')): ?>
          <li><a class="rail-link" href="/clientes"<?= $is('/clientes') ? ' aria-current="page"' : '' ?>><?= icon('users') ?>Clientes</a></li>
          <li><a class="rail-link" href="/funil"<?= $cur('/funil') ?>><?= icon('columns') ?>Funil<?php if ($newLeads): ?><span class="count" title="Oportunidades novas"><?= $newLeads ?></span><?php endif; ?></a></li>
          <?php if (Auth::can('crm')): $wa = \App\Controllers\WhatsAppController::unread(); ?><li><a class="rail-link" href="/whatsapp"<?= $is('/whatsapp') ? ' aria-current="page"' : '' ?>><?= icon('whatsapp') ?>WhatsApp<span class="count" data-live="wa" title="Conversas não lidas"<?= $wa ? '' : ' hidden' ?>><?= $wa ?></span></a></li><?php endif; ?>
          <?php endif; ?>
          <?php if (Auth::can('finance')): $od = (int) Db::q("SELECT COUNT(*) FROM installments WHERE status = 'aberta' AND due_date < CURDATE()")->fetchColumn(); ?><li><a class="rail-link" href="/financeiro"<?= $cur('/financeiro') ?>><?= icon('coins') ?>Financeiro<?php if ($od): ?><span class="count count-late" title="Parcelas vencidas"><?= $od ?></span><?php endif; ?></a></li><?php endif; ?>
          <?php if (Auth::can('projects')): ?><li><a class="rail-link" href="/projetos"<?= $is('/projetos') ? ' aria-current="page"' : '' ?>><?= icon('folder') ?>Projetos</a></li><?php endif; ?>
          <?php if (Auth::can('proposals')): ?><li><a class="rail-link" href="/propostas"<?= $is('/propostas') ? ' aria-current="page"' : '' ?>><?= icon('file') ?>Propostas</a></li><?php endif; ?>
          <li><a class="rail-link" href="/notificacoes"<?= $cur('/notificacoes') ?>><?= icon('bell') ?>Notificações<span class="count" data-live="unread"<?= $unread ? '' : ' hidden' ?>><?= $unread ?></span></a></li>
        </ul>
      </nav>
      <?php if (Auth::isAdmin()): ?>
      <nav>
        <p class="rail-group">Gestão</p>
        <ul class="rail-nav">
          <li><a class="rail-link" href="/precos"<?= $cur('/precos') ?>><?= icon('tag') ?>Tabela de preços</a></li>
          <li><a class="rail-link" href="/empresa"<?= $cur('/empresa') ?>><?= icon('palette') ?>Empresa</a></li>
          <li><a class="rail-link" href="/usuarios"<?= $cur('/usuarios') ?>><?= icon('shield') ?>Usuários</a></li>
        </ul>
      </nav>
      <?php endif; ?>
      <?php if ($me): ?>
      <button class="rail-link rail-install" type="button" data-install hidden><?= icon('plus') ?>Instalar o app</button>
      <a class="rail-user" href="/perfil"><?= avatar($me, 40) ?><span class="text-truncate"><strong class="d-block text-truncate"><?= e($me['name']) ?></strong><small class="text-truncate"><?= e($me['job_title'] ?: implode(' · ', array_map(fn($r) => explode(' ', Auth::ROLES[$r])[0], Auth::roles()))) ?></small></span></a>
      <?php endif; ?>
    </div>
  </aside>

  <div class="d-flex flex-column min-w-0">
    <header class="topbar">
      <button class="icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#rail" aria-controls="rail" aria-label="Abrir menu"><?= icon('menu') ?></button>
      <form class="search" role="search" method="get" action="/clientes">
        <?= icon('search') ?>
        <label class="visually-hidden" for="globalSearch">Buscar clientes</label>
        <input class="form-control" id="globalSearch" name="q" type="search" placeholder="Buscar cliente, WhatsApp, e-mail…" value="<?= e($path === '/clientes' ? ($_GET['q'] ?? '') : '') ?>" maxlength="100" autocomplete="off">
        <kbd class="d-none d-md-inline" aria-hidden="true">/</kbd>
      </form>
      <div class="d-flex align-items-center gap-2 ms-auto">
        <?php if (Auth::can('crm') && !in_array($path, ['/clientes', '/funil'], true)): ?><a class="btn btn-dark d-none d-xl-inline-flex" href="/clientes/novo"><?= icon('plus', 'icon icon-sm') ?>Novo cliente</a><?php endif; ?>
        <div class="dropdown">
          <button class="icon-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificações<?= $unread ? ", $unread não lidas" : '' ?>"><?= icon('bell') ?><span class="dot" data-live="unread-dot"<?= $unread ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span></button>
          <div class="dropdown-menu dropdown-menu-end notif-menu">
            <div class="d-flex align-items-center justify-content-between px-2 py-1">
              <strong>Notificações</strong>
              <?php if ($unread): ?><form method="post" action="/notificacoes/lidas"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-decoration-none">Marcar como lidas</button></form><?php endif; ?>
            </div>
            <div class="dropdown-divider"></div>
            <?php if (!$latest): ?><p class="small text-body-secondary px-2 py-3 mb-0">Nada por aqui. Leads novos e respostas de propostas aparecem neste sino.</p><?php endif; ?>
            <?php foreach ($latest as $n): ?>
              <a class="dropdown-item notif-item" href="/notificacoes/<?= (int) $n['id'] ?>"><span class="<?= $n['read_at'] ? 'read' : 'unread' ?>"></span><span><?= e($n['body']) ?><time datetime="<?= e($n['created_at']) ?>"><?= e($ago($n['created_at'])) ?></time></span></a>
            <?php endforeach; ?>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item justify-content-center small fw-semibold" href="/notificacoes">Ver todas</a>
          </div>
        </div>
        <?php if ($me): ?>
        <div class="dropdown">
          <button class="me-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu da conta"><?= avatar($me, 36) ?><span class="d-none d-md-block text-start lh-sm"><span class="d-block fw-semibold small"><?= e(explode(' ', $me['name'])[0]) ?></span></span><?= icon('chevron', 'icon icon-sm d-none d-md-block') ?></button>
          <div class="dropdown-menu dropdown-menu-end">
            <div class="px-2 py-1 small"><strong class="d-block"><?= e($me['name']) ?></strong><span class="text-body-secondary"><?= e($me['email']) ?></span></div>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="/perfil"><?= icon('user', 'icon icon-sm') ?>Meu perfil</a>
            <div class="dropdown-divider"></div>
            <p class="px-2 mb-1 small text-body-secondary">Tema</p>
            <?php foreach (['brand' => 'palette', 'light' => 'sun', 'dark' => 'moon'] as $t => $ic): ?>
            <form method="post" action="/perfil/tema"><?= csrf_field() ?><input type="hidden" name="theme" value="<?= $t ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
              <button class="dropdown-item<?= $theme === $t ? ' active' : '' ?>" aria-pressed="<?= $theme === $t ? 'true' : 'false' ?>"><?= icon($ic, 'icon icon-sm') ?><?= e(ProfileController::THEMES[$t]) ?><?= $theme === $t ? icon('check', 'icon icon-sm ms-auto') : '' ?></button></form>
            <?php endforeach; ?>
            <div class="dropdown-divider"></div>
            <form method="post" action="/logout"><?= csrf_field() ?><button class="dropdown-item"><?= icon('logout', 'icon icon-sm') ?>Sair</button></form>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </header>
    <main class="main" id="conteudo">
      <?php if ($f): ?><div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
<dialog id="iosInstall" class="lost-dialog" aria-labelledby="iosInstallTitle">
  <form method="dialog" class="p-4">
    <h2 class="h5" id="iosInstallTitle">Instalar no iPhone</h2>
    <ol class="mb-3 ps-3"><li>Abra este site no <b>Safari</b>.</li><li>Toque em <b>Compartilhar</b> (o quadrado com a seta para cima).</li><li>Escolha <b>Adicionar à Tela de Início</b> e confirme.</li></ol>
    <div class="text-end"><button class="btn btn-dark">Entendi</button></div>
  </form>
</dialog>
</body>
</html>
