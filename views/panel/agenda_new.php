<div class="page-head"><h1>Novo agendamento</h1></div>
<form method="post" action="<?= e(url('/painel/agenda/novo')) ?>" class="card"><?= csrf_field() ?>
  <h2>Serviços</h2>
  <?php if (!$services): ?><p class="empty">Cadastre serviços primeiro.</p><?php endif; ?>
  <?php foreach ($services as $s): ?>
    <label class="choice"><input type="checkbox" name="services[]" value="<?= (int)$s['id'] ?>" data-slot-src="services"><span class="t"><b><?= e($s['name']) ?></b><br><span class="muted small"><?= (int)$s['duration_min'] ?> min</span></span><span class="p"><?= e(money((int)$s['price_cents'])) ?></span></label>
  <?php endforeach; ?>
  <div class="grid g2">
    <div class="field"><label for="pro">Profissional</label><select class="form-select" id="pro" name="pro" data-slot-src="pro">
      <?php if (Ctx::ownProfessionalIds($ctx) === null): ?><option value="any">Qualquer profissional</option><?php endif; ?>
      <?php foreach ($pros as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="date">Dia</label><input class="form-control" type="date" id="date" name="date" data-slot-src="date" value="<?= e(now_utc()->setTimezone(new DateTimeZone($ctx['tz']))->format('Y-m-d')) ?>"></div>
  </div>
  <div data-slotpicker data-url="<?= e(url('/painel/api/slots')) ?>"><input type="hidden" name="ts" value=""><label>Horário livre</label><p class="slot-msg muted"></p><div class="slots"></div></div>
  <details style="margin:8px 0 12px"><summary>Encaixe fora do expediente</summary>
    <div class="field check"><label><input type="checkbox" name="outside" value="1"> Marcar fora da grade (escolha um profissional e informe a hora)</label></div>
    <div class="field"><label for="time">Hora</label><input class="form-control" type="time" id="time" name="time"></div>
    <p class="hint">Mesmo no encaixe, o sistema não permite sobrepor outro atendimento ou bloqueio.</p></details>
  <hr class="sep">
  <h2>Cliente</h2>
  <div class="grid g2">
    <?= fld('name', 'Nome', old('name', $cust['name'] ?? ''), ['required' => 1, 'maxlength' => 120]) ?>
    <?= fld('phone', 'Telefone / WhatsApp', old('phone', $cust ? Scheduling::formatPhone($cust['phone']) : ''), ['type' => 'tel', 'required' => 1, 'inputmode' => 'tel']) ?>
  </div>
  <?= fld('notes', 'Observação', old('notes'), ['type' => 'textarea', 'rows' => 2]) ?>
  <?= fld('whatsapp_consent', 'O cliente autorizou receber mensagens por WhatsApp', old('whatsapp_consent'), ['type' => 'checkbox']) ?>
  <button class="btn btn-primary">Agendar</button>
</form>
<script src="<?= e(asset('/assets/slots.js')) ?>"></script>
