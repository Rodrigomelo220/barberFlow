<?php $plan = $ctx['billing']['plan']; $byDay = []; foreach ($hours as $h) $byDay[(int)$h['weekday']][] = $h; ?>
<div class="page-head"><div><a class="small" href="<?= e(url('/painel/equipe')) ?>">← <?= solo_plan($ctx) ? 'Meu perfil' : 'Equipe' ?></a><h1 style="margin-top:4px"><?= e($p['name']) ?></h1></div></div>
<form method="post" action="<?= e(url('/painel/equipe/' . $p['id'])) ?>" class="card"><?= csrf_field() ?><h2>Dados</h2>
  <div class="grid g2"><?= fld('name', 'Nome', old('name', $p['name']), ['required' => 1]) ?><?= fld('color', 'Cor na agenda', old('color', $p['color']), ['type' => 'color']) ?></div>
  <?= fld('bio', 'Apresentação', old('bio', $p['bio']), ['maxlength' => 300]) ?>
  <?php if ($plan && (int)$plan['feat_commissions']): ?><?= fld('commission', 'Comissão (%)', old('commission', rtrim(rtrim(number_format((float)$p['commission_percent'], 2, '.', ''), '0'), '.')), ['inputmode' => 'decimal', 'hint' => 'Vale para os próximos recebimentos; os anteriores mantêm o percentual da época.']) ?>
  <?php else: ?><input type="hidden" name="commission" value="<?= e($p['commission_percent']) ?>"><?php endif; ?>
  <?= fld('active', 'Ativo na agenda', (int)$p['active'], ['type' => 'checkbox']) ?>
  <?php if (Ctx::writable($ctx)): ?><button class="btn btn-primary">Salvar</button><?php endif; ?></form>

<?php if (!solo_plan($ctx) && Ctx::can($ctx, 'professionals.manage')): $linked = $p['user_id'] ? DB::one('SELECT name, email FROM users WHERE id = ?', [$p['user_id']]) : null; ?>
<div class="card"><h2>Acesso ao sistema</h2>
  <?php if ($linked): ?><p class="mb-2">Acesso de <b><?= e($linked['name']) ?></b> (<?= e($linked['email']) ?>). Essa pessoa vê e gerencia só a agenda de <?= e($p['name']) ?>.</p>
    <?php if (Ctx::writable($ctx)): ?><?= post_form('/painel/acessos/' . (int)$p['user_id'] . '/remover', '<button class="btn btn-outline-danger btn-sm">Remover acesso</button>', '', 'Remover o acesso desta pessoa? Os atendimentos continuam no histórico.') ?><?php endif; ?>
  <?php elseif (Ctx::writable($ctx)): ?>
    <p class="muted small">Convide por e-mail para esta pessoa ter login próprio e ver só a própria agenda.</p>
    <form method="post" action="<?= e(url('/painel/equipe/' . (int)$p['id'] . '/convite')) ?>"><?= csrf_field() ?>
      <?= fld('email', 'E-mail da pessoa', old('email'), ['type' => 'email', 'required' => 1]) ?>
      <div class="field"><label class="form-label" for="f_role">Papel</label><select class="form-select" id="f_role" name="role"><?php foreach (Invites::ROLES as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary">Criar convite</button></form>
  <?php endif; ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/painel/equipe/' . $p['id'] . '/horarios')) ?>" class="card"><?= csrf_field() ?><h2>Horário de trabalho</h2>
  <p class="muted small">Deixe em branco os dias de folga. Use a segunda faixa para intervalos (ex.: 9–12 e 14–18).</p>
  <?php foreach (WEEKDAYS as $wd => $name): $r = $byDay[$wd] ?? []; ?>
    <div class="between" style="padding:6px 0;border-bottom:1px solid var(--line)"><b style="min-width:80px"><?= e($name) ?></b>
      <span class="actions">
      <?php for ($i = 0; $i < 2; $i++): ?><span class="actions"><input class="form-control" type="time" name="s_<?= $wd ?>_<?= $i ?>" value="<?= e($r[$i]['s'] ?? '') ?>" aria-label="<?= e($name) ?> início <?= $i + 1 ?>" style="width:120px"> às <input class="form-control" type="time" name="e_<?= $wd ?>_<?= $i ?>" value="<?= e($r[$i]['e'] ?? '') ?>" aria-label="<?= e($name) ?> fim <?= $i + 1 ?>" style="width:120px"></span><?php endfor; ?>
      </span></div>
  <?php endforeach; ?>
  <?php if (Ctx::writable($ctx)): ?><button class="btn btn-primary" style="margin-top:12px">Salvar horários</button><?php endif; ?>
</form>

<form method="post" action="<?= e(url('/painel/equipe/' . $p['id'] . '/servicos')) ?>" class="card"><?= csrf_field() ?><h2>Serviços que realiza</h2>
  <?php if (!$svc): ?><p class="empty">Cadastre serviços primeiro.</p><?php endif; ?>
  <?php foreach ($svc as $s): ?><div class="field check"><label><input type="checkbox" name="services[]" value="<?= (int)$s['id'] ?>" <?= $s['enabled'] ? 'checked' : '' ?>> <?= e($s['name']) ?> <?= (int)$s['active'] ? '' : badge('inativo') ?></label></div><?php endforeach; ?>
  <?php if (Ctx::writable($ctx) && $svc): ?><button class="btn btn-primary">Salvar serviços</button><?php endif; ?>
</form>
