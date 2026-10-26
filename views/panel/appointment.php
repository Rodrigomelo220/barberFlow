<?php
$tz = $ctx['tz']; $startsLocal = dt_from_db($a['starts_at'])->setTimezone(new DateTimeZone($tz));
$total = (int)$a['total_price_cents']; $remaining = $total - $paid - $pending; $canPay = Ctx::can($ctx, 'payments.record') && $a['status'] !== 'canceled';
$canEdit = Ctx::can($ctx, 'agenda.manage_own') && Ctx::writable($ctx);
?>
<div class="page-head"><div><a class="small" href="<?= e(url('/painel/agenda?data=' . $startsLocal->format('Y-m-d'))) ?>">← Agenda</a><h1 style="margin-top:4px"><?= e($a['customer_name']) ?> <?= appt_badge($a['status']) ?></h1></div></div>
<div class="grid g2">
<div class="card">
  <h2><?= e(weekday_pt($startsLocal)) ?>, <?= e($startsLocal->format('d/m/Y \à\s H:i')) ?></h2>
  <p class="muted">Com <?= e($a['professional_name']) ?> · <?= e(dt_local($a['starts_at'], $tz, 'H:i')) ?>–<?= e(dt_local($a['ends_at'], $tz, 'H:i')) ?></p>
  <ul style="padding-left:18px"><?php foreach ($svc as $s): ?><li><?= e($s['name']) ?> — <?= e(money((int)$s['price_cents'])) ?> (<?= (int)$s['duration_min'] ?> min)</li><?php endforeach; ?></ul>
  <p><b>Total: <?= e(money($total)) ?></b></p>
  <p class="small muted">Telefone: <?= e(Scheduling::formatPhone($a['customer_phone'])) ?> · origem: <?= $a['source'] === 'link' ? 'link público' : 'lançado no painel' ?></p>
  <?php if ($a['notes']): ?><p class="small">Observação: <?= e($a['notes']) ?></p><?php endif; ?>
  <p><a class="small" href="<?= e(url('/painel/clientes/' . (int)$a['customer_id'])) ?>">Ver cliente</a></p>
  <?php if ($canEdit && $a['status'] === 'confirmed'): ?>
    <hr class="sep">
    <div class="actions">
      <?= post_form('/painel/agendamentos/' . $a['id'] . '/resultado', '<input type="hidden" name="outcome" value="completed"><button class="btn btn-primary">' . icon("check2-circle") . ' Concluir atendimento</button>') ?>
      <?= post_form('/painel/agendamentos/' . $a['id'] . '/resultado', '<input type="hidden" name="outcome" value="no_show"><button class="btn btn-outline-dark">Cliente faltou</button>', '', 'Marcar este cliente como falta?') ?>
    </div>
    <p class="hint">Concluir não registra pagamento. Lance o recebimento ao lado.</p>
  <?php endif; ?>
</div>

<?php if ($canPay): ?>
<div class="card">
  <h2>Pagamento</h2>
  <p>Já recebido: <b><?= e(money($paid)) ?></b><?= $pending ? ' · pendente: <b>' . e(money($pending)) . '</b>' : '' ?> · falta: <b><?= e(money(max(0, $remaining))) ?></b></p>
  <?php if ($remaining > 0 && Ctx::writable($ctx)): ?>
  <form method="post" action="<?= e(url('/painel/agendamentos/' . $a['id'] . '/pagamento')) ?>"><?= csrf_field() ?>
    <div class="grid g2">
      <?= fld('amount', 'Valor (R$)', old('amount', number_format($remaining / 100, 2, ',', '')), ['inputmode' => 'decimal']) ?>
      <?= fld('method', 'Forma', 'pix', ['type' => 'select', 'options' => Finance::METHODS]) ?>
    </div>
    <?= fld('status', 'Situação', 'received', ['type' => 'select', 'options' => ['received' => 'Recebido agora', 'pending' => 'Pendente (a receber)']]) ?>
    <?= fld('notes', 'Observação', '', ['maxlength' => 300]) ?>
    <button class="btn btn-primary">Lançar pagamento</button>
  </form>
  <?php elseif ($remaining <= 0): ?><p class="badge badge-green">Totalmente pago</p><?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php if ($pay): ?>
<div class="card"><h2>Lançamentos</h2>
  <div class="table-responsive"><table class="table"><thead><tr><th>Quando</th><th>Tipo</th><th>Forma</th><th class="num">Valor</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
  <?php foreach ($pay as $p): $refund = $p['kind'] === 'refund'; ?>
    <tr><td><?= e(dt_local($p['received_at'] ?? $p['created_at'], $tz, 'd/m H:i')) ?></td><td><?= $refund ? 'Estorno' : 'Pagamento' ?></td><td><?= e(Finance::METHODS[$p['method']] ?? $p['method']) ?></td>
      <td class="num"><?= e(money(($refund ? -1 : 1) * (int)$p['amount_cents'])) ?></td><td><?= $p['status'] === 'received' ? badge('Recebido', 'green') : badge('Pendente', 'brass') ?></td>
      <td class="right"><?php if (Ctx::writable($ctx)): ?>
        <?php if (!$refund && $p['status'] === 'pending'): ?>
          <?= post_form('/painel/pagamentos/' . $p['id'] . '/recebido', '<input type="hidden" name="voltar" value="/painel/agendamentos/' . (int)$a['id'] . '"><button class="btn btn-sm btn-primary">Marcar recebido</button>', 'inline') ?>
          <?php if (Ctx::can($ctx, 'finance.manage')): ?><?= post_form('/painel/pagamentos/' . $p['id'] . '/descartar', '<input type="hidden" name="voltar" value="/painel/agendamentos/' . (int)$a['id'] . '"><button class="btn btn-sm btn-outline-danger">Descartar</button>', 'inline', 'Descartar este lançamento pendente?') ?><?php endif; ?>
        <?php elseif (!$refund && $p['status'] === 'received' && Ctx::can($ctx, 'finance.manage')): ?>
          <?= post_form('/painel/pagamentos/' . $p['id'] . '/estorno', '<input type="hidden" name="voltar" value="/painel/agendamentos/' . (int)$a['id'] . '"><button class="btn btn-sm btn-outline-danger">Estornar</button>', 'inline', 'Estornar este pagamento por completo?') ?>
        <?php endif; ?><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if ($canEdit && $a['status'] === 'confirmed'): ?>
<div class="grid g2">
<div class="card"><h2>Remarcar</h2>
  <form method="post" action="<?= e(url('/painel/agendamentos/' . $a['id'] . '/remarcar')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="rdate">Novo dia</label><input class="form-control" type="date" id="rdate" data-slot-src="date" value="<?= e($startsLocal->format('Y-m-d')) ?>"></div>
    <div data-slotpicker data-url="<?= e(url('/painel/api/slots')) ?>" data-params="<?= e(json_encode(['services' => implode(',', array_filter(array_column($svc, 'service_id'))), 'pro' => (string)(int)$a['professional_id'], 'exclude' => (int)$a['id']])) ?>"><input type="hidden" name="ts" value=""><p class="slot-msg muted"></p><div class="slots"></div></div>
    <button class="btn btn-primary">Remarcar</button>
  </form></div>
<div class="card"><h2>Cancelar</h2>
  <form method="post" action="<?= e(url('/painel/agendamentos/' . $a['id'] . '/cancelar')) ?>" data-confirm="Cancelar este agendamento?"><?= csrf_field() ?>
    <?= fld('reason', 'Motivo (opcional)', '', ['maxlength' => 200]) ?><button class="btn btn-outline-danger">Cancelar agendamento</button></form></div>
</div>
<script src="<?= e(asset('/assets/slots.js')) ?>"></script>
<?php endif; ?>
