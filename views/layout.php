<?php
$panel = isset($ctx);
$cur = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$cur = '/' . trim(substr($cur, strlen(base_path())), '/');
$nav = function (string $href, string $label, string $ic = '') use ($cur) {
    $on = $href === '/painel' ? $cur === '/painel' : str_starts_with($cur, $href);
    return '<a class="nav-link" href="' . e(url($href)) . '"' . ($on ? ' aria-current="page"' : '') . '>' . ($ic ? icon($ic) . ' ' : '') . e($label) . '</a>';
};
?><!doctype html>
<html lang="pt-BR"><head><?php require BF_ROOT . '/views/_theme_head.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(($title ?? 'BarberFlow') . ($panel ? ' — ' . $ctx['shop']['name'] : ' — BarberFlow')) ?></title>
<meta name="theme-color" content="#202522">
<?php if ($panel || ($noindex ?? false)): ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='11' fill='%23202522'/%3E%3Cpath d='M10 10h20v5H15v5h12v5H15v5h15v5H10z' fill='%23d7b77e'/%3E%3C/svg%3E"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head><body>
<a class="skip" href="#conteudo">Ir para o conteúdo</a>
<div class="rail"></div>
<header class="top"><div class="container-x">
  <?php if ($panel): ?><button type="button" class="burger" id="burgerBtn" aria-label="Abrir menu" aria-controls="moreSheet" aria-expanded="false"><i class="bi bi-list"></i></button><?php endif; ?>
  <a class="brand" href="<?= e(url($panel ? '/painel' : '/')) ?>"><i></i>BarberFlow</a>
  <span class="grow"></span>
  <?php require BF_ROOT . '/views/_theme_btn.php'; ?>
  <?php if ($panel): ?>
    <?php if (count($ctx['memberships']) > 1): ?>
      <?= post_form('/painel/trocar', '<select class="form-select" name="shop_id" aria-label="Negócio" onchange="this.form.submit()" style="min-height:36px;width:auto">' . implode('', array_map(fn($m) => '<option value="' . (int)$m['id'] . '"' . ((int)$m['id'] === $ctx['shop_id'] ? ' selected' : '') . '>' . e($m['name']) . '</option>', $ctx['memberships'])) . '</select><noscript><button class="btn btn-sm btn-outline-dark">Trocar</button></noscript>', 'inline') ?>
    <?php else: ?><span class="muted small"><?= e($ctx['shop']['name']) ?></span><?php endif; ?>
    <a class="nav-link" href="<?= e(url('/b/' . $ctx['shop']['slug'])) ?>" target="_blank" rel="noopener"><?= icon('box-arrow-up-right') ?> Página pública</a>
    <?= post_form('/sair', '<button class="linklike">Sair</button>', 'inline') ?>
  <?php elseif (!empty($user)): ?>
    <a class="nav-link" href="<?= e(url('/painel')) ?>">Painel</a>
    <?= post_form('/sair', '<button class="linklike">Sair</button>', 'inline') ?>
  <?php else: ?>
    <a class="nav-link" href="<?= e(url('/entrar')) ?>">Entrar</a>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/cadastro')) ?>"><?= icon("person-plus") ?> Criar conta</a>
  <?php endif; ?>
</div></header>
<?php if ($panel): $lvl = $ctx['billing']['access']['level']; ?>
<?php
$items = [['/painel', 'Início', 'house', true], ['/painel/agenda', 'Agenda', 'calendar3', true], ['/painel/clientes', 'Clientes', 'people', Ctx::can($ctx, 'customers.view')],
  ['/painel/servicos', 'Serviços', 'scissors', Ctx::can($ctx, 'services.manage')], ['/painel/equipe', 'Equipe', 'person-badge', Ctx::can($ctx, 'professionals.manage')],
  ['/painel/bloqueios', 'Bloqueios', 'slash-circle', Ctx::can($ctx, 'agenda.manage')], ['/painel/financeiro', 'Financeiro', 'cash-coin', Ctx::can($ctx, 'finance.view')],
  ['/painel/mensagens', 'Mensagens', 'chat-dots', Ctx::can($ctx, 'messages.view')], ['/painel/configuracoes', 'Configurações', 'gear', Ctx::can($ctx, 'settings.manage')],
  ['/painel/assinatura', 'Assinatura', 'credit-card', Ctx::can($ctx, 'billing.manage')]];
$items = array_values(array_filter($items, fn($i) => $i[3]));
$primaryHrefs = ['/painel', '/painel/agenda', '/painel/clientes', '/painel/financeiro'];
$primary = array_values(array_filter($items, fn($i) => in_array($i[0], $primaryHrefs, true)));
$more = array_values(array_filter($items, fn($i) => !in_array($i[0], $primaryHrefs, true)));
$isOn = fn($h) => $h === '/painel' ? $cur === '/painel' : str_starts_with($cur, $h);
$moreOn = (bool)array_filter($more, fn($i) => $isOn($i[0]));
?>
<nav class="tabs" aria-label="Seções do painel"><div class="container-x">
  <?php foreach ($items as $i): ?><?= $nav($i[0], $i[1], $i[2]) ?><?php endforeach; ?>
</div></nav>
<nav class="bnav" aria-label="Menu principal">
  <?php foreach ($primary as $i): ?><a href="<?= e(url($i[0])) ?>"<?= $isOn($i[0]) ? ' aria-current="page"' : '' ?>><?= icon($i[2]) ?><span><?= e($i[1]) ?></span></a><?php endforeach; ?>
  <button type="button" id="moreBtn" aria-expanded="false" aria-controls="moreSheet"<?= $moreOn ? ' class="on"' : '' ?>><?= icon('grid') ?><span>Mais</span></button>
</nav>
<div class="sheet-bg" id="moreBg" hidden></div>
<div class="sheet" id="moreSheet" role="dialog" aria-label="Mais opções" hidden>
  <div class="sheet-grip"></div>
  <p class="sheet-shop"><?= e($ctx['shop']['name']) ?></p>
  <div class="sheet-grid">
    <?php foreach ($items as $i): ?><a href="<?= e(url($i[0])) ?>"<?= $isOn($i[0]) ? ' aria-current="page"' : '' ?>><?= icon($i[2]) ?><span><?= e($i[1]) ?></span></a><?php endforeach; ?>
    <a href="<?= e(url('/b/' . $ctx['shop']['slug'])) ?>" target="_blank" rel="noopener"><?= icon('box-arrow-up-right') ?><span>Página pública</span></a>
    <?= post_form('/sair', '<button><i class="bi bi-box-arrow-right"></i><span>Sair</span></button>', 'sheet-form') ?>
  </div>
</div>
<?php endif; ?>
<main id="conteudo"><div class="container-x">
  <?php if ($panel && $ctx['billing']['access']['message'] && !in_array($ctx['billing']['access']['reason'], ['ok'], true)): $lv = $ctx['billing']['access']['level']; ?>
    <div class="alert <?= $lv === 'full' ? 'alert-warning' : 'alert-danger' ?>" role="status"><?= e($ctx['billing']['access']['message']) ?>
      <?php if (Ctx::can($ctx, 'billing.manage') && $cur !== '/painel/assinatura'): ?> <a href="<?= e(url('/painel/assinatura')) ?>">Ver assinatura</a><?php endif; ?></div>
  <?php endif; ?>
  <?php require BF_ROOT . '/views/_flash.php'; ?>
  <?= $content ?>
</div></main>
<footer class="foot"><div class="container-x">
  <?php if (cfg('app.mode', 'demo') === 'demo'): ?><p><b>Versão de demonstração.</b> Cobrança e mensagens de WhatsApp são simuladas: nada é cobrado nem enviado de verdade.</p><?php endif; ?>
  <a href="<?= e(url('/termos')) ?>">Termos</a> · <a href="<?= e(url('/privacidade')) ?>">Privacidade e LGPD</a>
</div></footer>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script><script src="<?= e(url('/assets/app.js')) ?>"></script>
</body></html>
