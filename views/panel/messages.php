<?php $plan = $ctx['billing']['plan']; $lim = $plan ? (int)$plan['max_messages_month'] : 0; $stl = ['queued' => ['Na fila', 'blue'], 'simulated' => ['Simulada', 'brass'], 'skipped' => ['Ignorada', 'neutral'], 'canceled' => ['Cancelada', 'neutral']]; ?>
<div class="page-head"><h1>Mensagens</h1></div>
<div class="alert alert-warning"><b>Simulação.</b> Nesta versão nenhuma mensagem é enviada ao WhatsApp. Elas entram na fila e, na hora marcada, ficam como “simulada”. O envio real depende da API oficial do WhatsApp (conexão de saída e conta aprovada), que a hospedagem gratuita não permite.</div>
<div class="card"><p>Uso neste mês: <b><?= (int)$used ?></b> de <b><?= $lim ?></b> mensagens do plano <?= $plan ? e($plan['name']) : '' ?>. <?= $plan && (int)$plan['feat_reminders'] ? '' : '<br><span class="muted">Seu plano não inclui lembretes automáticos.</span>' ?></p>
<p class="small muted">Só são programadas mensagens para clientes que autorizaram o recebimento.</p></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Quando</th><th>Tipo</th><th>Telefone</th><th>Mensagem</th><th>Situação</th></tr></thead><tbody>
<?php foreach ($rows as $m): [$lbl, $tone] = $stl[$m['status']] ?? [$m['status'], 'neutral']; ?>
  <tr><td class="nowrap"><?= e(dt_local($m['send_at'], $ctx['tz'], 'd/m H:i')) ?></td><td><?= e($m['kind']) ?></td><td class="nowrap"><?= e(Scheduling::formatPhone($m['phone'])) ?></td><td><?= e($m['body']) ?></td><td><?= badge($lbl, $tone) ?><?= $m['detail'] ? '<br><span class="small muted">' . e($m['detail']) . '</span>' : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!$rows): ?><p class="empty">Nenhuma mensagem ainda. Elas aparecem quando um cliente autorizado agenda.</p><?php endif; ?></div>
