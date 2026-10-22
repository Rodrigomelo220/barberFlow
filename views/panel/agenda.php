<?php
$prev = $day->modify('-1 day')->format('Y-m-d'); $next = $day->modify('+1 day')->format('Y-m-d'); $q = $prof ? '&prof=' . $prof : '';
?>
<div class="page-head"><h1>Agenda</h1><a class="btn btn-primary" href="<?= e(url('/painel/agenda/novo')) ?>"><?= icon("plus-lg") ?> Novo agendamento</a></div>
<div class="card">
  <div class="between">
    <div class="actions"><a class="btn btn-sm btn-outline-dark" href="?data=<?= e($prev . $q) ?>" aria-label="Dia anterior">←</a>
      <strong><?= e(weekday_pt($day)) ?>, <?= e($day->format('d/m/Y')) ?></strong>
      <a class="btn btn-sm btn-outline-dark" href="?data=<?= e($next . $q) ?>" aria-label="Próximo dia">→</a>
      <a class="btn btn-sm btn-outline-dark" href="?data=<?= e(now_utc()->setTimezone($day->getTimezone())->format('Y-m-d') . $q) ?>">Hoje</a></div>
    <form method="get" class="actions"><input type="hidden" name="data" value="<?= e($day->format('Y-m-d')) ?>">
      <select class="form-select" name="prof" aria-label="Profissional" style="width:auto;min-height:36px" onchange="this.form.submit()"><option value="">Todos os profissionais</option>
      <?php foreach ($pros as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $prof === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <noscript><button class="btn btn-sm btn-outline-dark">Filtrar</button></noscript></form>
  </div>
</div>
<div class="card">
  <?php foreach ($blocks as $b): ?><div class="block-row">🔒 <?= e(dt_local($b['starts_at'], $ctx['tz'], 'H:i')) ?>–<?= e(dt_local($b['ends_at'], $ctx['tz'], 'H:i')) ?> · Bloqueio<?= $b['professional'] ? ' de ' . e($b['professional']) : ' (toda a barbearia)' ?><?= $b['reason'] ? ' · ' . e($b['reason']) : '' ?></div><?php endforeach; ?>
  <?php if (!$rows): ?><p class="empty">Nenhum agendamento neste dia.</p><?php endif; ?>
  <?php foreach ($rows as $a): ?>
    <a class="agenda-row" href="<?= e(url('/painel/agendamentos/' . $a['id'])) ?>">
      <span class="time"><?= e(dt_local($a['starts_at'], $ctx['tz'], 'H:i')) ?></span>
      <span><b><?= e($a['customer_name']) ?></b> <?= appt_badge($a['status']) ?><br>
        <span class="muted small"><span class="dot" style="background:<?= e($a['professional_color']) ?>"></span><?= e($a['professional_name']) ?> · <?= e($a['services']) ?> · <?= e(dt_local($a['starts_at'], $ctx['tz'], 'H:i')) ?>–<?= e(dt_local($a['ends_at'], $ctx['tz'], 'H:i')) ?></span></span>
      <span class="muted nowrap"><?= e(money((int)$a['total_price_cents'])) ?></span>
    </a>
  <?php endforeach; ?>
</div>
