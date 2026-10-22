<?php
$cur = '/' . trim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(base_path())), '/');
$an = fn($h, $l) => '<a class="nav-link" href="' . e(url($h)) . '"' . (($h === '/admin' ? $cur === '/admin' : str_starts_with($cur, $h)) ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e(($title ?? 'Admin') . ' — Administração') ?></title><link rel="icon" href="data:,"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>"></head>
<body><div class="rail"></div>
<header class="top"><div class="container-x"><a class="brand" href="<?= e(url('/admin')) ?>"><i></i>BarberFlow · Admin</a><span class="grow"></span>
  <span class="muted small"><?= e($u['email']) ?></span><?= post_form('/sair', '<button class="linklike">Sair</button>', 'inline') ?></div></header>
<nav class="tabs"><div class="container-x"><?= $an('/admin', 'Visão geral') ?><?= $an('/admin/barbearias', 'Barbearias') ?><?= $an('/admin/planos', 'Planos') ?><?= $an('/admin/eventos', 'Cobrança') ?><?= $an('/admin/mensagens', 'Mensagens') ?></div></nav>
<main><div class="container-x"><?php require BF_ROOT . '/views/_flash.php'; ?><?= $content ?></div></main>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(url('/assets/app.js')) ?>"></script></body></html>
