<?php $s = $ctx['shop']; ?>
<div class="page-head"><h1>Configurações</h1></div>
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
