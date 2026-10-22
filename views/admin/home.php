<?php $s = $m['subs']; $r = $m['rec']; $g = $m['growth']; $h = $m['health']; ?>
<div class="page-head"><h1>Visão geral</h1></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><b><?= (int)$g['shops'] ?></b><span>barbearias (<?= (int)$g['new_shops'] ?> nos últimos 30 dias)</span></div>
  <div class="stat"><b><?= (int)$s['active'] ?></b><span>assinaturas ativas</span></div>
  <div class="stat"><b><?= (int)$s['trialing'] ?></b><span>em teste</span></div>
  <div class="stat"><b><?= (int)$s['past_due'] + (int)$s['suspended'] ?></b><span>em atraso ou suspensas</span></div>
</div>
<div class="grid g3" style="margin-bottom:16px">
  <div class="stat"><b><?= e(money((int)$s['mrr'])) ?></b><span>MRR contratado (ativas + em atraso). É o valor dos planos, não dinheiro recebido.</span></div>
  <div class="stat"><b><?= e(money((int)$r['received_month'])) ?></b><span>recebido no mês (cobranças marcadas como recebidas)</span></div>
  <div class="stat"><b><?= e(money((int)$r['overdue'])) ?></b><span>em cobranças vencidas</span></div>
</div>
<div class="card"><h2>Saúde da operação</h2>
  <p><?= (int)$h['failed_events'] ? '<b class="badge badge-red">' . (int)$h['failed_events'] . '</b> evento(s) de cobrança com falha — <a href="' . e(url('/admin/eventos')) . '">ver e reprocessar</a>' : 'Nenhum evento de cobrança com falha.' ?></p>
  <p><?= (int)$h['late_messages'] ? '<b class="badge badge-brass">' . (int)$h['late_messages'] . '</b> mensagem(ns) na fila há mais de 15 minutos (o envio simulado roda a cada visita ou pelo cron).' : 'Fila de mensagens em dia.' ?></p>
  <p class="small muted">Agendamentos criados nos últimos 30 dias: <?= (int)$g['appts'] ?>.</p></div>
