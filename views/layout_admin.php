<?php
$cur = '/' . trim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(base_path())), '/');
$an = fn($h, $l) => '<a class="nav-link" href="' . e(url($h)) . '"' . (($h === '/admin' ? $cur === '/admin' : str_starts_with($cur, $h)) ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
?><!doctype html>
<html lang="pt-BR"><head><?php require BF_ROOT . '/views/_theme_head.php'; ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e(($title ?? 'Admin') . ' — Administração') ?></title><link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='11' fill='%23202522'/%3E%3Cpath d='M10 10h20v5H15v5h12v5H15v5h15v5H10z' fill='%23d7b77e'/%3E%3C/svg%3E"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('/assets/style.css')) ?>"></head>
<body><div class="rail"></div>
<header class="top"><div class="container-x"><a class="brand" href="<?= e(url('/admin')) ?>"><i><?= e(brand_initial()) ?></i><?= e(brand()) ?> · Admin</a><span class="grow"></span><?php require BF_ROOT . '/views/_theme_btn.php'; ?>
  <span class="muted small"><?= e($u['email']) ?></span><?= post_form('/sair', '<button class="linklike">Sair</button>', 'inline') ?></div></header>
<nav class="tabs"><div class="container-x"><?= $an('/admin', 'Visão geral') ?><?= $an('/admin/barbearias', 'Negócios') ?><?= $an('/admin/planos', 'Planos') ?><?= $an('/admin/eventos', 'Cobrança') ?><?= $an('/admin/mensagens', 'Mensagens') ?><?= $an('/admin/administradores', 'Administradores') ?><?= $an('/admin/sistema', 'Sistema') ?></div></nav>
<main><div class="container-x"><?php require BF_ROOT . '/views/_flash.php'; ?><?= $content ?></div></main>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(asset('/assets/app.js')) ?>"></script></body></html>
