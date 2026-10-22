<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Agendamento') ?></title><meta name="robots" content="noindex">
<link rel="icon" href="data:,"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>"></head>
<body><div class="rail"></div>
<main><div class="container-x mid"><?php require BF_ROOT . '/views/_flash.php'; ?><?= $content ?></div></main>
<footer class="foot"><div class="container-x mid">Agendamento por <a href="<?= e(url('/')) ?>">BarberFlow</a> · <a href="<?= e(url('/privacidade')) ?>">Privacidade</a></div></footer>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(url('/assets/app.js')) ?>"></script>
</body></html>
