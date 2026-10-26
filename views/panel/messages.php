<?php $plan = $ctx['billing']['plan']; $lim = $plan ? (int)$plan['max_messages_month'] : 0;
$stl = ['queued' => ['Na fila', 'blue'], 'simulated' => ['Simulada', 'brass'], 'manual' => ['Enviar agora', 'red'], 'sending' => ['Enviando', 'blue'], 'sent' => ['Enviada', 'green'], 'failed' => ['Falhou', 'red'], 'skipped' => ['Ignorada', 'neutral'], 'canceled' => ['Cancelada', 'neutral']]; ?>
<div class="page-head"><h1>Mensagens</h1></div>
<?php if ($demo): ?><div class="alert alert-warning"><b>Modo de teste.</b> Nenhuma mensagem é enviada: na hora marcada elas ficam como “simulada”. Você pode abrir qualquer uma no WhatsApp com o botão ao lado.</div>
<?php elseif (!$api): ?><div class="alert alert-info"><b>Envio com um toque.</b> Na hora marcada, a mensagem aparece aqui como “Enviar agora”. Toque em <b>Abrir no WhatsApp</b>, envie e marque como enviada.</div><?php endif; ?>
<div class="card"><p>Uso neste mês: <b><?= (int)$used ?></b> de <b><?= $lim ?></b> mensagens do plano <?= $plan ? e($plan['name']) : '' ?>. <?= $plan && (int)$plan['feat_reminders'] ? '' : '<br><span class="muted">Seu plano não inclui lembretes automáticos.</span>' ?></p>
<p class="small muted mb-0">Só são programadas mensagens para clientes que autorizaram o recebimento.</p></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Quando</th><th>Tipo</th><th>Telefone</th><th>Mensagem</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
<?php foreach ($rows as $m): [$lbl, $tone] = $stl[$m['status']] ?? [$m['status'], 'neutral']; $open = in_array($m['status'], ['manual', 'queued', 'simulated', 'failed'], true); ?>
  <tr><td class="nowrap"><?= e(dt_local($m['send_at'], $ctx['tz'], 'd/m H:i')) ?></td><td><?= e($m['kind']) ?></td><td class="nowrap"><?= e(Scheduling::formatPhone($m['phone'])) ?></td><td><?= e($m['body']) ?></td><td><?= badge($lbl, $tone) ?><?= $m['detail'] ? '<br><span class="small muted">' . e($m['detail']) . '</span>' : '' ?></td>
  <td class="nowrap"><?php if ($open): ?><a class="btn btn-sm btn-primary" target="_blank" rel="noopener noreferrer" href="<?= e(WhatsApp::waLink($m['phone'], $m['body'])) ?>"><i class="bi bi-whatsapp"></i> Abrir no WhatsApp</a>
    <?php if (Ctx::writable($ctx)): ?><?= post_form('/painel/mensagens/' . (int)$m['id'] . '/enviada', '<button class="btn btn-sm btn-outline-dark">Marcar como enviada</button>', 'inline') ?><?php endif; ?><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!$rows): ?><p class="empty">Nenhuma mensagem ainda. Elas aparecem quando um cliente autorizado agenda.</p><?php endif; ?></div>
