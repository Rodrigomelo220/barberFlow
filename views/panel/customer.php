<?php $c = $h['customer']; $canManage = Ctx::can($ctx, 'customers.manage'); ?>
<div class="page-head"><div><a class="small" href="<?= e(url('/painel/clientes')) ?>">← Clientes</a><h1 style="margin-top:4px"><?= e($c['name']) ?></h1></div>
  <?php if (Ctx::writable($ctx)): ?><a class="btn btn-primary" href="<?= e(url('/painel/agenda/novo?cliente=' . (int)$c['id'])) ?>">Agendar</a><?php endif; ?></div>
<div class="grid g2">
<form method="post" action="<?= e(url('/painel/clientes/' . $c['id'])) ?>" class="card"><?= csrf_field() ?><h2>Cadastro</h2>
  <?= fld('name', 'Nome', old('name', $c['name']), ['required' => 1]) ?>
  <?= fld('phone', 'Telefone / WhatsApp', old('phone', Scheduling::formatPhone($c['phone'])), ['type' => 'tel', 'required' => 1]) ?>
  <?= fld('preferences', 'Preferências', old('preferences', $c['preferences']), ['maxlength' => 500]) ?>
  <?= fld('notes', 'Anotações', old('notes', $c['notes']), ['type' => 'textarea']) ?>
  <p class="small muted">Mensagens por WhatsApp: <?= (int)$c['whatsapp_optin'] ? badge('autorizado', 'green') : badge('sem autorização', 'neutral') ?></p>
  <?php if ($canManage): ?><button class="btn btn-primary">Salvar</button><?php endif; ?>
</form>
<div class="card"><h2>Histórico</h2>
  <?php if (!$h['appointments']): ?><p class="empty">Sem atendimentos ainda.</p><?php endif; ?>
  <?php foreach ($h['appointments'] as $a): ?>
    <a class="agenda-row" href="<?= e(url('/painel/agendamentos/' . $a['id'])) ?>" style="grid-template-columns:96px 1fr auto"><span class="time"><?= e(dt_local($a['starts_at'], $ctx['tz'], 'd/m/Y')) ?></span><span><?= e($a['services']) ?><br><span class="muted small"><?= e($a['professional']) ?></span></span><span><?= appt_badge($a['status']) ?></span></a>
  <?php endforeach; ?>
</div>
</div>
<?php if ($canManage): ?>
<div class="card"><h2>Dados pessoais (LGPD)</h2>
  <p class="muted">A pedido do cliente você pode exportar tudo o que há sobre ele ou anonimizar o cadastro. A anonimização remove nome, telefone e anotações de forma definitiva e mantém só os valores financeiros, sem identificação.</p>
  <div class="actions"><a class="btn btn-outline-dark" href="<?= e(url('/painel/clientes/' . $c['id'] . '/exportar')) ?>">Exportar dados (JSON)</a>
  <?php if (Ctx::can($ctx, 'settings.manage') && Ctx::writable($ctx)): ?><?= post_form('/painel/clientes/' . $c['id'] . '/anonimizar', '<button class="btn btn-outline-danger">Anonimizar cliente</button>', '', 'Anonimizar este cliente? Isso não pode ser desfeito.') ?><?php endif; ?></div>
</div>
<?php endif; ?>
