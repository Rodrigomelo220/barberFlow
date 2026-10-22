<?php $sub = $ctx['billing']['sub']; $plan = $ctx['billing']['plan']; ?>
<div class="page-head"><h1>Pagamento (simulado)</h1></div>
<div class="alert alert-warning"><b>Ambiente de demonstração.</b> Não existe cobrança real. Os botões abaixo montam um evento no formato do Asaas e o entregam ao mesmo endpoint de webhook que um provedor real usaria (token + idempotência). A assinatura só muda por esse caminho.</div>
<div class="card">
  <p>Plano <b><?= $plan ? e($plan['name']) : '—' ?></b> · <?= $sub ? e(money((int)$sub['price_cents'])) : '' ?>/mês · situação: <b><?= e(SUB_STATUS[$sub['status'] ?? ''] ?? '—') ?></b></p>
  <?php if ($charge): ?><p class="muted">Cobrança em aberto: <?= e(money((int)$charge['amount_cents'])) ?>, vencimento <?= $charge['due_date'] ? e(date('d/m/Y', strtotime($charge['due_date']))) : '—' ?>.</p><?php else: ?><p class="muted">Nenhuma cobrança em aberto.</p><?php endif; ?>
  <div class="actions">
    <?php if ($charge): ?><?= post_form('/dev/pagamento', '<input type="hidden" name="simular" value="pagar"><button class="btn btn-primary">Simular pagamento aprovado</button>', 'inline') ?><?php endif; ?>
    <?= post_form('/dev/pagamento', '<input type="hidden" name="simular" value="renovar"><button class="btn btn-outline-dark">Simular renovação mensal paga</button>', 'inline') ?>
    <?= post_form('/dev/pagamento', '<input type="hidden" name="simular" value="atraso"><button class="btn btn-outline-danger">Simular cobrança vencida</button>', 'inline', 'Isso coloca a assinatura em atraso (carência de 5 dias). Continuar?') ?>
    <?= post_form('/dev/pagamento', '<input type="hidden" name="simular" value="estorno"><button class="btn btn-outline-danger">Simular contestação</button>', 'inline', 'Isso suspende a assinatura. Continuar?') ?>
  </div>
  <p class="hint">Depois de simular, volte para <a href="<?= e(url('/painel/assinatura')) ?>">Assinatura</a> para ver o efeito.</p>
</div>
