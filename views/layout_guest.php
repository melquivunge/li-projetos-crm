<!doctype html>
<html lang="pt-BR" data-theme="brand" data-bs-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F3EEE5">
<title><?= e(App\Brand::name()) ?></title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/assets/app/icon-180.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(App\Brand::name()) ?>">
<link rel="icon" href="/assets/logo-icon-li.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
<?php if (App\Brand::css() !== ''): ?><link rel="stylesheet" href="/marca.css?v=<?= substr(md5(App\Brand::css()), 0, 8) ?>"><?php endif; ?>
</head>
<body>
<?php if (env('APP_ENV') === 'staging'): ?><div class="staging-bar" role="note">Ambiente de testes · dados fictícios · e-mails não são enviados</div><?php endif; ?>
<?= $content ?>
<script src="<?= asset('/assets/app.js') ?>"></script>
</body>
</html>
