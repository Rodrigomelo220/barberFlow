<?php $s = $ctx['shop']; ?>
<div class="page-head"><h1>Configurações</h1></div>
<?php foreach (['logo' => ['Logo', 'Quadrada funciona melhor. JPG, PNG ou WebP, até 2 MB.'], 'cover' => ['Capa', 'Imagem larga (ex.: 1600×600). JPG, PNG ou WebP, até 2 MB.']] as $k => [$lbl, $hint]):
  $cur = Shops::imageUrl($s[$k . '_path'] ?? null); ?>
<div class="card img-card">
  <h2><?= e($lbl) ?></h2>
  <div class="img-row">
    <div class="img-prev <?= $k ?>"><?php if ($cur): ?><img src="<?= e($cur) ?>" alt="<?= e($lbl) ?> atual"><?php else: ?><i class="bi bi-image"></i><span>Sem <?= strtolower(e($lbl)) ?></span><?php endif; ?></div>
    <?php if (Ctx::writable($ctx)): ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('/painel/configuracoes/imagem')) ?>" class="img-form"><?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= $k ?>">
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control" required>
      <p class="small muted"><?= e($hint) ?></p>
      <button class="btn btn-primary">Enviar <?= strtolower(e($lbl)) ?></button>
    </form>
    <?php if ($cur): ?><form method="post" action="<?= e(url('/painel/configuracoes/imagem')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= $k ?>"><input type="hidden" name="remove" value="1"><button class="btn btn-outline-secondary btn-sm" data-confirm="Remover a imagem?">Remover</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<form method="post" action="<?= e(url('/painel/configuracoes')) ?>" class="card"><?= csrf_field() ?>
  <h2>Barbearia</h2>
  <?= fld('name', 'Nome', old('name', $s['name']), ['required' => 1, 'maxlength' => 80]) ?>
  <?= fld('description', 'Descrição (página pública)', old('description', $s['description']), ['type' => 'textarea', 'rows' => 2, 'maxlength' => 500]) ?>
  <div class="grid g2"><?= fld('phone', 'Telefone / WhatsApp', old('phone', $s['phone'] ? Scheduling::formatPhone($s['phone']) : ''), ['type' => 'tel']) ?><?= fld('address', 'Endereço', old('address', $s['address']), ['maxlength' => 200]) ?></div>
  <p class="small muted">Endereço da página pública: <b><?= e(abs_url('/b/' . $s['slug'])) ?></b> (não pode ser alterado para não quebrar links já divulgados).</p>
  <hr class="sep"><h2>Regras de agendamento</h2>
  <div class="grid g2">
    <?= fld('slot_grid_minutes', 'Intervalo entre horários (min)', old('slot_grid_minutes', $s['slot_grid_minutes']), ['type' => 'number', 'min' => 5, 'max' => 120]) ?>
    <?= fld('min_notice_minutes', 'Antecedência mínima (min)', old('min_notice_minutes', $s['min_notice_minutes']), ['type' => 'number', 'min' => 0]) ?>
    <?= fld('max_days_ahead', 'Agendar com até (dias)', old('max_days_ahead', $s['max_days_ahead']), ['type' => 'number', 'min' => 1, 'max' => 365]) ?>
    <?= fld('cancel_min_notice_minutes', 'Cliente pode cancelar até (min antes)', old('cancel_min_notice_minutes', $s['cancel_min_notice_minutes']), ['type' => 'number', 'min' => 0]) ?>
  </div>
  <?= fld('allow_any_professional', 'Permitir “qualquer profissional” na página pública', (int)$s['allow_any_professional'], ['type' => 'checkbox']) ?>
  <?= fld('allow_online_cancel', 'Permitir que o cliente cancele/remarque pelo link', (int)$s['allow_online_cancel'], ['type' => 'checkbox']) ?>
  <hr class="sep"><h2>Lembretes (simulados)</h2>
  <?= fld('reminders_enabled', 'Programar lembretes', (int)$s['reminders_enabled'], ['type' => 'checkbox']) ?>
  <?= fld('reminder_hours', 'Antecedência dos lembretes (horas, separadas por vírgula)', old('reminder_hours', $s['reminder_hours']), ['hint' => 'Ex.: 24,2 — até 4 lembretes. Exige plano com lembretes.']) ?>
  <?php if (Ctx::writable($ctx)): ?><button class="btn btn-primary">Salvar</button><?php endif; ?>
</form>
<?php if (cfg('app.mode', 'demo') === 'demo' && Ctx::writable($ctx)): ?>
<div class="card"><h2>Dados de exemplo</h2>
  <p class="muted">Preenche esta barbearia com serviços, equipe, clientes fictícios e cerca de 5 semanas de atendimentos, pagamentos e despesas para você testar os gráficos, a agenda e o financeiro. Só funciona se ainda não houver agendamentos.</p>
  <?= post_form('/painel/configuracoes/exemplo', '<button class="btn btn-outline-dark">Preencher com dados de exemplo</button>', '', 'Criar dados fictícios nesta barbearia?') ?>
</div>
<?php endif; ?>
