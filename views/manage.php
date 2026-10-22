<?php $tz = $shop['timezone']; $startsLocal = dt_from_db($a['starts_at'])->setTimezone(new DateTimeZone($tz)); ?>
<div class="shop-head"><h1><?= e($shop['name']) ?></h1></div>
<div class="card">
  <div class="between"><h2 style="margin:0">Seu agendamento</h2><?= appt_badge($a['status']) ?></div>
  <p style="font-size:1.15rem;font-weight:700;margin-top:10px"><?= e(weekday_pt($startsLocal)) ?>, <?= e($startsLocal->format('d/m/Y')) ?> às <?= e($startsLocal->format('H:i')) ?></p>
  <p class="muted">Com <?= e($a['professional_name']) ?> · <?= e(implode(' + ', array_column($a['services'], 'name'))) ?> · <?= e(money((int)$a['total_price_cents'])) ?></p>
  <?php if ($shop['address']): ?><p class="small muted"><?= e($shop['address']) ?></p><?php endif; ?>
  <?php if ($shop['phone']): ?><p class="small muted">Contato: <?= e(Scheduling::formatPhone($shop['phone'])) ?></p><?php endif; ?>
</div>
<?php if ($a['status'] === 'confirmed' && $startsLocal > now_utc()): ?>
  <?php if ((int)$shop['allow_online_cancel']): ?>
  <div class="card">
    <h2>Remarcar</h2>
    <form method="post" action="<?= e(url('/agendamento/' . $token . '/remarcar')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="date">Novo dia</label><input class="form-control" id="date" type="date" data-slot-src="date" min="<?= e(now_utc()->setTimezone(new DateTimeZone($tz))->format('Y-m-d')) ?>"></div>
      <div data-slotpicker data-url="<?= e(url('/api/slots-token')) ?>" data-params="<?= e(json_encode(['token' => $token, 'services' => 'x'])) ?>"><input type="hidden" name="ts" value=""><p class="slot-msg muted"></p><div class="slots"></div></div>
      <button class="btn btn-primary">Confirmar novo horário</button>
    </form>
    <hr class="sep">
    <?= post_form('/agendamento/' . $token . '/cancelar', '<button class="btn btn-outline-danger">Cancelar agendamento</button>', '', 'Cancelar este agendamento?') ?>
    <p class="hint">Alterações online são aceitas até <?= (int)$shop['cancel_min_notice_minutes'] ?> minutos antes do horário.</p>
  </div>
  <script src="<?= e(url('/assets/slots.js')) ?>"></script>
  <?php else: ?>
  <div class="alert alert-info">Para cancelar ou remarcar, fale com a barbearia<?= $shop['phone'] ? ' pelo ' . e(Scheduling::formatPhone($shop['phone'])) : '' ?>.</div>
  <?php endif; ?>
<?php endif; ?>
