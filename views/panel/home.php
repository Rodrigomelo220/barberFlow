<?php
$t = $d['today']; $pub = abs_url('/b/' . $ctx['shop']['slug']); $tzO = new DateTimeZone($ctx['tz']);
$nowL = now_utc()->setTimezone($tzO);
$h = (int)$nowL->format('G'); $greet = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
$first = trim(explode(' ', (string)($ctx['user']['name'] ?? ''))[0]);
$nxt = $d['next'][0] ?? null; $rest = array_slice($d['next'], 1);
$maxA = max(1, ...array_column($d['week_ahead'], 'count')); $maxR = max(1, ...array_column($d['week_received'], 'cents'));
$setup = $d['setup']; $done = count(array_filter($setup));
$steps = ['service' => ['Cadastre um serviço', '/painel/servicos'], 'professional' => ['Vincule um profissional aos serviços', '/painel/equipe'], 'hours' => ['Defina os horários de atendimento', '/painel/equipe'], 'booking' => ['Receba o primeiro agendamento', null]];
?>
<div class="page-head"><div><h1><?= e($greet) ?><?= $first ? ', ' . e($first) : '' ?></h1><p class="muted mb-0"><?= e(weekday_pt($nowL)) ?>, <?= e($nowL->format('d/m/Y')) ?></p></div><a class="btn btn-primary" href="<?= e(url('/painel/agenda/novo')) ?>"><?= icon("plus-lg") ?> Novo agendamento</a></div>

<?php if ($done < 4): ?>
<div class="card setup"><div class="between"><h2 class="mb-0">Deixe sua página pronta</h2><span class="small muted"><?= $done ?> de 4</span></div>
  <div class="setup-bar"><i style="width:<?= $done * 25 ?>%"></i></div>
  <ul class="setup-list"><?php foreach ($steps as $k => [$lbl, $href]): ?>
    <li class="<?= $setup[$k] ? 'ok' : '' ?>"><i class="bi <?= $setup[$k] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><?= !$setup[$k] && $href ? '<a href="' . e(url($href)) . '">' . e($lbl) . '</a>' : e($lbl) ?></li>
  <?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="home-grid">
  <div class="card next-card">
    <p class="kicker">Próximo atendimento</p>
    <?php if ($nxt): ?>
      <a class="next-main" href="<?= e(url('/painel/agendamentos/' . $nxt['id'])) ?>">
        <span class="next-time"><?= e(dt_local($nxt['starts_at'], $ctx['tz'], 'H:i')) ?></span>
        <span><b><?= e($nxt['customer_name']) ?></b><br><span class="small"><?= e($nxt['services']) ?> · <?= e($nxt['professional_name']) ?></span><br><span class="small opacity-75"><?= e(dt_local($nxt['starts_at'], $ctx['tz'], 'd/m/Y')) ?></span></span>
      </a>
    <?php else: ?>
      <div class="empty-state p-0 text-start"><i class="bi bi-calendar-heart"></i><p class="mb-0">Nenhum agendamento futuro. Divulgue seu link e os horários aparecem aqui.</p></div>
    <?php endif; ?>
  </div>
  <div class="stats-col">
    <div class="stat"><b><?= (int)$t['total'] ?></b><span>hoje (<?= (int)$t['confirmed'] ?> a atender)</span></div>
    <div class="stat"><b><?= e(money((int)$t['expected'])) ?></b><span>previsto hoje</span></div>
    <div class="stat"><b><?= e(money($d['received'])) ?></b><span>recebido no mês</span></div>
    <div class="stat"><b><?= $d['no_show_rate'] === null ? '—' : e(number_format($d['no_show_rate'], 1, ',', '')) . '%' ?></b><span>faltas no mês</span></div>
  </div>
</div>

<div class="grid g2">
  <div class="card"><h2>Próximos 7 dias</h2><p class="small muted">Agendamentos confirmados por dia.</p>
    <div class="bars" role="img" aria-label="Agendamentos confirmados nos próximos 7 dias">
      <?php foreach ($d['week_ahead'] as $w): ?><div class="bar"><span class="v"><?= (int)$w['count'] ?></span><i style="height:<?= $w['count'] ? max(6, round(100 * $w['count'] / $maxA)) : 2 ?>%"></i><span class="l"><?= e($w['label']) ?><br><small><?= e($w['day']) ?></small></span></div><?php endforeach; ?>
    </div></div>
  <div class="card"><h2>Recebido nos últimos 7 dias</h2><p class="small muted">Somente pagamentos que você registrou como recebidos.</p>
    <div class="bars gold" role="img" aria-label="Valores recebidos nos últimos 7 dias">
      <?php foreach ($d['week_received'] as $w): ?><div class="bar"><span class="v"><?= $w['cents'] ? e(number_format($w['cents'] / 100, 0, ',', '.')) : '' ?></span><i style="height:<?= $w['cents'] > 0 ? max(6, round(100 * $w['cents'] / $maxR)) : 2 ?>%"></i><span class="l"><?= e($w['label']) ?><br><small><?= e($w['day']) ?></small></span></div><?php endforeach; ?>
    </div></div>
</div>

<div class="grid g2">
  <div class="card"><h2>Depois deste</h2>
    <?php if (!$rest): ?><p class="empty">Sem outros agendamentos à vista.</p><?php endif; ?>
    <?php foreach ($rest as $n): ?>
      <a class="agenda-row" href="<?= e(url('/painel/agendamentos/' . $n['id'])) ?>"><span class="time"><?= e(dt_local($n['starts_at'], $ctx['tz'], 'd/m H:i')) ?></span>
        <span><b><?= e($n['customer_name']) ?></b><br><span class="muted small"><?= e($n['services']) ?> · <?= e($n['professional_name']) ?></span></span></a>
    <?php endforeach; ?>
  </div>
  <div class="card"><h2>Seu link de agendamento</h2>
    <p>Coloque no Instagram, no WhatsApp e no Google Meu Negócio.</p>
    <div class="field"><input class="form-control" readonly value="<?= e($pub) ?>" aria-label="Link público" onclick="this.select()"></div>
    <a class="btn btn-outline-dark" href="<?= e(url('/b/' . $ctx['shop']['slug'])) ?>" target="_blank" rel="noopener">Abrir a página como cliente</a>
    <hr class="sep">
    <p class="small muted mb-0"><?= (int)$d['new_customers'] ?> cliente(s) novo(s) neste mês · previsto no mês: <?= e(money($d['expected'])) ?></p>
  </div>
</div>
