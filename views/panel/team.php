<?php $plan = $ctx['billing']['plan']; $act = count(array_filter($rows, fn($p) => (int)$p['active'])); ?>
<?php $solo = solo_plan($ctx); ?>
<div class="page-head"><h1><?= $solo ? 'Meu perfil' : 'Equipe' ?></h1></div>
<?php if (!$solo): ?><p class="muted">Profissionais ativos: <?= $act ?><?= $plan && $plan['max_professionals'] !== null ? ' de ' . (int)$plan['max_professionals'] . ' no plano ' . e($plan['name']) : '' ?>.</p><?php endif; ?>
<?php $full = $plan && $plan['max_professionals'] !== null && $act >= (int)$plan['max_professionals']; ?>
<?php if ($solo): ?>
<?php elseif (Ctx::writable($ctx) && $full): ?>
<div class="card"><h2>Mais profissionais na equipe</h2><p class="muted mb-2">O plano <?= e($plan['name']) ?> inclui <?= (int)$plan['max_professionals'] ?> profissional(is) ativo(s). Para cadastrar mais, mude de plano.</p><a class="btn btn-outline-dark" href="<?= e(url('/painel/assinatura')) ?>">Ver planos</a></div>
<?php elseif (Ctx::writable($ctx)): ?>
<form method="post" action="<?= e(url('/painel/equipe')) ?>" class="card"><?= csrf_field() ?><h2>Novo profissional</h2>
  <div class="grid g2"><?= fld('name', 'Nome', old('name'), ['required' => 1]) ?><?= fld('color', 'Cor na agenda', old('color', '#c8963e'), ['type' => 'color']) ?></div>
  <?= fld('bio', 'Apresentação (aparece na página pública)', old('bio'), ['maxlength' => 300]) ?>
  <button class="btn btn-primary">Adicionar</button></form>
<?php endif; ?>
<div class="card"><?php foreach ($rows as $p): ?>
  <div class="between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span><span class="dot" style="background:<?= e($p['color']) ?>"></span><a href="<?= e(url('/painel/equipe/' . $p['id'])) ?>"><b><?= e($p['name']) ?></b></a> <?= (int)$p['active'] ? '' : badge('inativo') ?></span>
  <a class="btn btn-sm btn-outline-dark" href="<?= e(url('/painel/equipe/' . $p['id'])) ?>">Editar</a></div>
<?php endforeach; ?></div>
<?php if (!$solo && Ctx::can($ctx, 'professionals.manage')): $mem = Invites::members($ctx['shop_id']); $pend = Invites::pending($ctx['shop_id']); ?>
<div class="card"><h2>Quem tem acesso ao sistema</h2>
  <?php foreach ($mem as $m): ?><div class="between" style="padding:8px 0;border-bottom:1px solid var(--bf-line)"><span><b><?= e($m['name']) ?></b> <span class="muted small"><?= e($m['email']) ?> · <?= e(['owner' => 'dono', 'manager' => 'gerente', 'barber' => 'atendente'][$m['role']] ?? $m['role']) ?><?= $m['professional'] ? ' · agenda: ' . e($m['professional']) : '' ?></span></span>
    <?php if ($m['role'] !== 'owner' && (int)$m['id'] !== (int)$ctx['user']['id'] && Ctx::writable($ctx)): ?><?= post_form('/painel/acessos/' . (int)$m['id'] . '/remover', '<button class="btn btn-sm btn-outline-danger">Remover</button>', 'inline', 'Remover o acesso desta pessoa?') ?><?php endif; ?></div><?php endforeach; ?>
  <?php foreach ($pend as $i): ?><div class="between" style="padding:8px 0;border-bottom:1px solid var(--bf-line)"><span><?= e($i['email']) ?> <?= badge('convite pendente', 'brass') ?> <span class="muted small">até <?= e(dt_local($i['expires_at'], $ctx['tz'], 'd/m')) ?></span></span>
    <?php if (Ctx::writable($ctx)): ?><?= post_form('/painel/convites/' . (int)$i['id'] . '/cancelar', '<button class="btn btn-sm btn-outline-dark">Cancelar</button>', 'inline') ?><?php endif; ?></div><?php endforeach; ?>
  <p class="muted small mt-2 mb-0">Para convidar alguém, abra o profissional abaixo e use “Acesso ao sistema”.</p></div>
<?php endif; ?>
