<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Agendamento') ?></title><meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='11' fill='%23202522'/%3E%3Cpath d='M10 10h20v5H15v5h12v5H15v5h15v5H10z' fill='%23d7b77e'/%3E%3C/svg%3E"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>"></head>
<body><div class="rail"></div>
<header class="top"><div class="container-x"><a class="brand" href="<?= e(url('/')) ?>"><i></i>BarberFlow</a><span class="grow"></span><span class="small muted">Agendamento online</span></div></header>
<main><div class="container-x mid"><?php require BF_ROOT . '/views/_flash.php'; ?><?= $content ?></div></main>
<footer class="foot"><div class="container-x mid">Agendamento por <a href="<?= e(url('/')) ?>">BarberFlow</a> · <a href="<?= e(url('/privacidade')) ?>">Privacidade</a></div></footer>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(url('/assets/app.js')) ?>"></script>
</body></html>
