<?php $tz = $ctx['tz']; $q = '?de=' . e($p['from_date']) . '&ate=' . e($p['to_date']); $canMgr = Ctx::can($ctx, 'finance.manage'); $plan = $ctx['billing']['plan']; ?>
<div class="page-head"><h1>Financeiro</h1><a class="btn btn-outline-dark" href="<?= e(url('/painel/financeiro/exportar' . $q)) ?>"><?= icon("download") ?> Exportar CSV</a></div>
<form method="get" class="card actions"><?= fld('de', 'De', $p['from_date'], ['type' => 'date']) ?><?= fld('ate', 'Até', $p['to_date'], ['type' => 'date']) ?><button class="btn btn-primary" style="margin-top:4px">Filtrar</button></form>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><b><?= e(money($sum['scheduled'])) ?></b><span>previsto (confirmados)</span></div>
  <div class="stat"><b><?= e(money($sum['realized'])) ?></b><span>atendido (concluídos)</span></div>
  <div class="stat"><b><?= e(money($sum['received'])) ?></b><span>recebido, líquido de estornos</span></div>
  <div class="stat"><b><?= e(money($sum['to_receive'])) ?></b><span>a receber (concluídos sem pagamento)</span></div>
</div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><b><?= e(money($sum['pending'])) ?></b><span>pagamentos pendentes</span></div>
  <div class="stat"><b><?= e(money($sum['refunded'])) ?></b><span>estornado no período</span></div>
  <div class="stat"><b><?= e(money($sum['expenses'])) ?></b><span>despesas</span></div>
  <div class="stat"><b><?= e(money($sum['net'])) ?></b><span>resultado (recebido − despesas)</span></div>
</div>
<p class="small muted">Previsto, atendido e recebido são números diferentes: concluir um atendimento não registra pagamento. Faltas: <?= (int)$sum['no_show'] ?> · cancelados: <?= (int)$sum['canceled'] ?>.</p>

<?php if ($sum['by_method']): ?><div class="card"><h2>Recebido por forma de pagamento</h2><div class="table-responsive"><table class="table"><tbody>
  <?php foreach ($sum['by_method'] as $m): ?><tr><td><?= e(Finance::METHODS[$m['method']] ?? $m['method']) ?></td><td class="num"><?= e(money((int)$m['cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

<?php if ($plan && (int)$plan['feat_commissions']): ?>
<div class="card"><h2>Comissões</h2><div class="table-responsive"><table class="table"><thead><tr><th>Profissional</th><th class="num">Atendimentos</th><th class="num">Recebido líquido</th><th class="num">Comissão</th></tr></thead><tbody>
  <?php foreach ($comm as $c): ?><tr><td><?= e($c['name']) ?></td><td class="num"><?= (int)$c['services'] ?></td><td class="num"><?= e(money((int)$c['net_cents'])) ?></td><td class="num"><?= e(money((int)$c['commission_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="hint">Calculada sobre o recebido líquido de estornos, com o percentual vigente no dia de cada recebimento.</p></div>
<?php endif; ?>

<div class="card"><h2>Lançamentos</h2>
  <?php if (!$pays): ?><p class="empty">Nenhum lançamento no período.</p><?php endif; ?>
  <div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Tipo</th><th>Cliente</th><th>Profissional</th><th>Forma</th><th class="num">Valor</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
  <?php foreach ($pays as $x): $rf = $x['kind'] === 'refund'; ?>
    <tr><td class="nowrap"><?= e(dt_local($x['received_at'] ?? $x['created_at'], $tz, 'd/m H:i')) ?></td><td><?= $rf ? 'Estorno' : 'Pagamento' ?></td><td><?= e($x['customer']) ?></td><td><?= e($x['professional']) ?></td><td><?= e(Finance::METHODS[$x['method']] ?? $x['method']) ?></td>
    <td class="num"><?= e(money(($rf ? -1 : 1) * (int)$x['amount_cents'])) ?></td><td><?= $x['status'] === 'received' ? badge('Recebido', 'green') : badge('Pendente', 'brass') ?></td>
    <td class="right nowrap"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('/painel/agendamentos/' . (int)$x['appointment_id'])) ?>">Abrir</a>
      <?php if (!$rf && $x['status'] === 'pending' && Ctx::writable($ctx)): ?><?= post_form('/painel/pagamentos/' . $x['id'] . '/recebido', '<button class="btn btn-sm btn-primary">Recebido</button>', 'inline') ?><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="card"><h2>Despesas</h2>
  <?php if ($canMgr && Ctx::writable($ctx)): ?>
  <form method="post" action="<?= e(url('/painel/despesas')) ?>"><?= csrf_field() ?>
    <div class="grid g4"><?= fld('description', 'Descrição', '', ['required' => 1]) ?><?= fld('category', 'Categoria', '', ['placeholder' => 'aluguel, produtos…']) ?><?= fld('amount', 'Valor (R$)', '', ['required' => 1, 'inputmode' => 'decimal']) ?><?= fld('paid_on', 'Data', now_utc()->setTimezone(new DateTimeZone($tz))->format('Y-m-d'), ['type' => 'date']) ?></div>
    <button class="btn btn-primary">Lançar despesa</button></form><hr class="sep">
  <?php endif; ?>
  <?php if (!$exps): ?><p class="empty">Nenhuma despesa no período.</p><?php endif; ?>
  <?php foreach ($exps as $x): ?><div class="between" style="padding:6px 0;border-bottom:1px solid var(--line)"><span><b><?= e($x['description']) ?></b> <span class="muted small"><?= e($x['category']) ?> · <?= e(date('d/m/Y', strtotime($x['paid_on']))) ?></span></span>
    <span class="actions"><b><?= e(money((int)$x['amount_cents'])) ?></b><?php if ($canMgr && Ctx::writable($ctx)): ?><?= post_form('/painel/despesas/' . $x['id'] . '/excluir', '<button class="btn btn-sm btn-outline-danger">Excluir</button>', 'inline', 'Excluir esta despesa?') ?><?php endif; ?></span></div><?php endforeach; ?>
</div>
