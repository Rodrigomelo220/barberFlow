<?php
$b = $ctx['billing']; $sub = $b['sub']; $plan = $b['plan']; $acc = $b['access']; $st = $sub['status'] ?? 'incomplete';
$tone = ['active' => 'green', 'trialing' => 'blue', 'past_due' => 'red', 'suspended' => 'red', 'canceled' => 'neutral', 'incomplete' => 'brass'][$st] ?? 'neutral';
$tz = $ctx['tz']; $canStart = in_array($st, ['incomplete', 'canceled', 'suspended'], true) || ($st === 'trialing' && $acc['level'] === 'blocked');
$trialUsed = $sub && $sub['trial_ends_at'] !== null;
?>
<div class="page-head"><h1>Assinatura</h1></div>
<div class="card">
  <p>Situação: <?= badge(SUB_STATUS[$st] ?? $st, $tone) ?> · Plano: <b><?= $plan ? e($plan['name']) : '—' ?></b><?= $sub ? ' · ' . e(money((int)$sub['price_cents'])) . '/mês' : '' ?></p>
  <?php if ($acc['message']): ?><p class="muted"><?= e($acc['message']) ?></p><?php endif; ?>
  <?php if ($st === 'trialing' && $sub['trial_ends_at']): ?><p>Teste até <b><?= e(dt_local($sub['trial_ends_at'], $tz, 'd/m/Y H:i')) ?></b>.</p><?php endif; ?>
  <?php if ($sub && $sub['current_period_end'] && in_array($st, ['active', 'past_due'], true)): ?><p>Período pago até <b><?= e(dt_local($sub['current_period_end'], $tz, 'd/m/Y')) ?></b><?= (int)$sub['cancel_at_period_end'] ? ' — <b>cancelamento agendado</b>' : '' ?>.</p><?php endif; ?>
  <?php if ($st === 'past_due' && $sub['grace_until']): ?><p>Carência até <b><?= e(dt_local($sub['grace_until'], $tz, 'd/m/Y')) ?></b>; depois, 10 dias em modo leitura. Seus dados não são apagados.</p><?php endif; ?>
  <div class="actions">
  <?php if ($sub && $sub['provider_ref'] && in_array($st, ['incomplete', 'trialing', 'past_due'], true) && $demo): ?><a class="btn btn-danger" href="<?= e(url('/dev/pagamento')) ?>">Ir para o pagamento (simulado)</a><?php endif; ?>
  <?php if ($st === 'active' && !(int)$sub['cancel_at_period_end']): ?><?= post_form('/painel/assinatura/cancelar', '<button class="btn btn-outline-danger">Cancelar assinatura</button>', '', 'Cancelar no fim do período pago?') ?><?php endif; ?>
  <?php if ($st === 'active' && (int)$sub['cancel_at_period_end']): ?><?= post_form('/painel/assinatura/retomar', '<button class="btn btn-outline-dark">Desfazer cancelamento</button>') ?><?php endif; ?>
  <?php if ($st === 'trialing' && $acc['level'] === 'full'): ?><?= post_form('/painel/assinatura/cancelar', '<button class="btn btn-outline-danger">Encerrar teste</button>', '', 'Encerrar o período de teste?') ?><?php endif; ?>
  </div>
</div>

<div class="card"><h2><?= $canStart ? 'Escolha um plano' : 'Planos' ?></h2>
  <?php foreach ($plans as $p): $cur = $plan && $plan['code'] === $p['code']; ?>
    <div class="choice" style="cursor:default"><span class="t"><b><?= e($p['name']) ?></b> <?= $cur ? badge('atual', 'blue') : '' ?><br><span class="muted small"><?= e(implode(' · ', decode_list($p['highlights']))) ?></span></span><span class="p"><?= e(money((int)$p['price_cents'])) ?></span>
    <span>
      <?php if ($canStart): ?>
        <?= post_form('/painel/assinatura/iniciar', '<input type="hidden" name="plan" value="' . e($p['code']) . '"><button class="btn btn-sm btn-primary">Pagar</button>', 'inline') ?>
        <?php if ((int)$p['trial_days'] > 0 && !$trialUsed): ?><?= post_form('/painel/assinatura/iniciar', '<input type="hidden" name="plan" value="' . e($p['code']) . '"><input type="hidden" name="trial" value="1"><button class="btn btn-sm btn-outline-dark">Testar ' . (int)$p['trial_days'] . ' dias</button>', 'inline') ?><?php endif; ?>
      <?php elseif (!$cur && in_array($st, ['active', 'trialing'], true)): ?>
        <?= post_form('/painel/assinatura/plano', '<input type="hidden" name="plan" value="' . e($p['code']) . '"><button class="btn btn-sm btn-outline-dark">Trocar</button>', 'inline', 'Trocar para o plano ' . $p['name'] . '?') ?>
      <?php endif; ?></span></div>
  <?php endforeach; ?>
  <?php if ($st === 'active'): ?><p class="hint">A troca vale a partir de agora; a nova cobrança acompanha o próximo ciclo.</p><?php endif; ?>
</div>

<?php if ($charges): ?><div class="card"><h2>Cobranças</h2><div class="table-responsive"><table class="table"><thead><tr><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th>Pago em</th></tr></thead><tbody>
<?php foreach ($charges as $c): ?><tr><td><?= $c['due_date'] ? e(date('d/m/Y', strtotime($c['due_date']))) : '—' ?></td><td class="num"><?= e(money((int)$c['amount_cents'])) ?></td><td><?= e(['pending' => 'Pendente', 'overdue' => 'Vencida', 'confirmed' => 'Confirmada', 'received' => 'Recebida', 'refunded' => 'Estornada', 'chargeback' => 'Contestada'][$c['status']] ?? $c['status']) ?></td><td><?= $c['received_at'] ? e(dt_local($c['received_at'], $tz, 'd/m/Y')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($demo): ?><div class="alert alert-info">O pagamento aqui é <b>simulado</b>: nenhuma cobrança real é feita. A assinatura só fica ativa quando o webhook de pagamento é recebido e validado.</div><?php endif; ?>
