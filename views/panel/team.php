<?php $plan = $ctx['billing']['plan']; $act = count(array_filter($rows, fn($p) => (int)$p['active'])); ?>
<div class="page-head"><h1>Equipe</h1></div>
<p class="muted">Profissionais ativos: <?= $act ?><?= $plan && $plan['max_professionals'] !== null ? ' de ' . (int)$plan['max_professionals'] . ' no plano ' . e($plan['name']) : '' ?>.</p>
<?php if (Ctx::writable($ctx)): ?>
<form method="post" action="<?= e(url('/painel/equipe')) ?>" class="card"><?= csrf_field() ?><h2>Novo profissional</h2>
  <div class="grid g2"><?= fld('name', 'Nome', old('name'), ['required' => 1]) ?><?= fld('color', 'Cor na agenda', old('color', '#c8963e'), ['type' => 'color']) ?></div>
  <?= fld('bio', 'Apresentação (aparece na página pública)', old('bio'), ['maxlength' => 300]) ?>
  <button class="btn btn-primary">Adicionar</button></form>
<?php endif; ?>
<div class="card"><?php foreach ($rows as $p): ?>
  <div class="between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span><span class="dot" style="background:<?= e($p['color']) ?>"></span><a href="<?= e(url('/painel/equipe/' . $p['id'])) ?>"><b><?= e($p['name']) ?></b></a> <?= (int)$p['active'] ? '' : badge('inativo') ?></span>
  <a class="btn btn-sm btn-outline-dark" href="<?= e(url('/painel/equipe/' . $p['id'])) ?>">Editar</a></div>
<?php endforeach; ?></div>
