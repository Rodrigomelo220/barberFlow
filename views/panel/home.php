<?php $t = $d['today']; $pub = abs_url('/b/' . $ctx['shop']['slug']); ?>
<div class="page-head"><h1>Início</h1><a class="btn btn-primary" href="<?= e(url('/painel/agenda/novo')) ?>"><?= icon("plus-lg") ?> Novo agendamento</a></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="stat"><b><?= (int)$t['total'] ?></b><span>agendamentos hoje (<?= (int)$t['confirmed'] ?> a atender)</span></div>
  <div class="stat"><b><?= e(money((int)$t['expected'])) ?></b><span>previsto para hoje</span></div>
  <div class="stat"><b><?= e(money($d['received'])) ?></b><span>recebido no mês</span></div>
  <div class="stat"><b><?= $d['no_show_rate'] === null ? '—' : e(number_format($d['no_show_rate'], 1, ',', '')) . '%' ?></b><span>faltas no mês</span></div>
</div>
<div class="grid g2">
  <div class="card"><h2>Próximos atendimentos</h2>
    <?php if (!$d['next']): ?><p class="empty">Nenhum agendamento futuro. Divulgue seu link abaixo.</p><?php endif; ?>
    <?php foreach ($d['next'] as $n): ?>
      <a class="agenda-row" href="<?= e(url('/painel/agendamentos/' . $n['id'])) ?>"><span class="time"><?= e(dt_local($n['starts_at'], $ctx['tz'], 'd/m H:i')) ?></span>
        <span><b><?= e($n['customer_name']) ?></b><br><span class="muted small"><?= e($n['services']) ?> · <?= e($n['professional_name']) ?></span></span></a>
    <?php endforeach; ?>
  </div>
  <div class="card"><h2>Seu link de agendamento</h2>
    <p>Coloque no Instagram, no WhatsApp e no Google Meu Negócio.</p>
    <div class="field"><input class="form-control" readonly value="<?= e($pub) ?>" aria-label="Link público" onclick="this.select()"></div>
    <a class="btn btn-outline-dark" href="<?= e(url('/b/' . $ctx['shop']['slug'])) ?>" target="_blank" rel="noopener">Abrir a página como cliente</a>
    <hr class="sep">
    <p class="small muted"><?= (int)$d['new_customers'] ?> cliente(s) novo(s) neste mês · previsto no mês: <?= e(money($d['expected'])) ?></p>
  </div>
</div>
