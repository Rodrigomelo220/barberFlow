<?php $c = Diag::summary($groups); $ic = ['ok' => ['bi-check-circle-fill', ''], 'warn' => ['bi-exclamation-triangle-fill', ''], 'fail' => ['bi-x-octagon-fill', '']]; ?>
<div class="page-head"><h1>Diagnóstico</h1></div>
<div class="card">
  <p><strong><?= $c['fail'] ? $c['fail'] . ' problema(s) a corrigir' : 'Nenhum problema crítico' ?></strong><?= $c['warn'] ? ' · ' . $c['warn'] . ' aviso(s)' : '' ?> · <?= $c['ok'] ?> item(ns) ok.</p>
  <p class="muted small">Confira antes de vender. Esta página só mostra o estado do servidor; nenhuma senha ou chave aparece aqui.</p>
  <form method="post" action="<?= e(url('/admin/diagnostico')) ?>"><?= csrf_field() ?><button class="btn btn-outline-dark btn-sm">Testar de novo (inclui verificação de arquivos expostos)</button></form>
</div>
<?php foreach ($groups as $g => $items): ?>
<section class="card"><h2><?= e($g) ?></h2>
  <ul class="diag-list">
  <?php foreach ($items as $i): [$icon, $tone] = $ic[$i['lvl']]; ?>
    <li class="diag-<?= e($i['lvl']) ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i><div><strong><?= e($i['title']) ?></strong><span class="visually-hidden"> — <?= ['ok' => 'ok', 'warn' => 'atenção', 'fail' => 'problema'][$i['lvl']] ?></span><?php if ($i['hint'] && $i['lvl'] !== 'ok'): ?><div class="muted small"><?= e($i['hint']) ?></div><?php endif; ?></div></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
