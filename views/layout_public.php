<!doctype html>
<html lang="pt-BR"><head><?php require BF_ROOT . '/views/_theme_head.php'; ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Agendamento') ?></title><meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="<?= e(brand_favicon()) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('/assets/style.css')) ?>"></head>
<body><div class="rail"></div>
<header class="top"><div class="container-x"><a class="brand" href="<?= e(url('/')) ?>"><?= brand_mark() ?><?= e(brand()) ?></a><span class="grow"></span><?php require BF_ROOT . '/views/_theme_btn.php'; ?><span class="small muted">Agendamento online</span></div></header>
<main><div class="container-x mid"><?php require BF_ROOT . '/views/_flash.php'; ?><?= $content ?></div></main>
<footer class="foot"><div class="container-x mid">Agendamento por <a href="<?= e(url('/')) ?>"><?= e(brand()) ?></a> · <a href="<?= e(url('/privacidade')) ?>">Privacidade</a></div></footer>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(asset('/assets/app.js')) ?>"></script>
</body></html>
