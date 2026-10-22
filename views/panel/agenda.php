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
<?php
// ---- linha do tempo: colunas por profissional, 1 minuto = 1 unidade de altura ----
$tzO = new DateTimeZone($ctx['tz']);
$cols = array_values(array_filter($pros, fn($p) => ($prof ? (int)$p['id'] === $prof : true) && ($own === null || in_array((int)$p['id'], $own, true))));
$mins = function (string $db) use ($tzO, $day) { $t = dt_from_db($db)->setTimezone($tzO); return (int)round(($t->getTimestamp() - $day->getTimestamp()) / 60); };
$lo = 8 * 60; $hi = 20 * 60; $any = false;
foreach ($wh as $w) { [$h, $m] = explode(':', $w['start_time']); $a = $h * 60 + $m; [$h, $m] = explode(':', $w['end_time']); $b = $h * 60 + $m; $lo = $any ? min($lo, $a) : $a; $hi = $any ? max($hi, $b) : $b; $any = true; }
foreach ($rows as $a) { $lo = min($lo, $mins($a['starts_at'])); $hi = max($hi, $mins($a['ends_at'])); }
foreach ($blocks as $b) { $lo = max(0, min($lo, $mins($b['starts_at']))); $hi = min(1440, max($hi, $mins($b['ends_at']))); }
$lo = max(0, intdiv($lo, 60) * 60); $hi = min(1440, (int)ceil($hi / 60) * 60); if ($hi - $lo < 120) $hi = $lo + 120;
$PX = 1.6; $H = ($hi - $lo) * $PX;
$nowMin = null; $nowLocal = now_utc()->setTimezone($tzO); if ($nowLocal->format('Y-m-d') === $day->format('Y-m-d')) { $nowMin = (int)$nowLocal->format('G') * 60 + (int)$nowLocal->format('i'); }
$whBy = []; foreach ($wh as $w) { [$h, $m] = explode(':', $w['start_time']); $a = $h * 60 + $m; [$h, $m] = explode(':', $w['end_time']); $whBy[(int)$w['professional_id']][] = [$a, $h * 60 + $m]; }
$clamp = fn($v) => max($lo, min($hi, $v));
?>
<?php if (!$cols): ?>
  <div class="card empty-state"><i class="bi bi-person-plus"></i><h2>Nenhum profissional ativo</h2><p class="muted">Cadastre quem atende para ver a agenda.</p><a class="btn btn-primary" href="<?= e(url('/painel/equipe')) ?>">Ir para a equipe</a></div>
<?php else: ?>
<div class="card tl-card">
  <div class="tl-scroll">
  <div class="tl" style="--cols:<?= count($cols) ?>">
    <div class="tl-head"><span></span><?php foreach ($cols as $p): ?><span class="tl-ph"><i class="dot" style="background:<?= e($p['color']) ?>"></i><?= e($p['name']) ?></span><?php endforeach; ?></div>
    <div class="tl-body" style="height:<?= (int)$H ?>px">
      <div class="tl-hours"><?php for ($t = $lo; $t < $hi; $t += 60): ?><span style="top:<?= ($t - $lo) * $PX ?>px"><?= sprintf('%02d:00', intdiv($t, 60)) ?></span><?php endfor; ?></div>
      <?php foreach ($cols as $p): $pid = (int)$p['id']; ?>
      <div class="tl-col" style="background-image:repeating-linear-gradient(to bottom,transparent 0,transparent <?= 60 * $PX - 1 ?>px,#ece8df <?= 60 * $PX - 1 ?>px,#ece8df <?= 60 * $PX ?>px)">
        <?php // fora do expediente (hachurado)
        $open = $whBy[$pid] ?? []; usort($open, fn($a, $b) => $a[0] <=> $b[0]); $cur = $lo;
        foreach ($open as [$a, $b]) { if ($a > $cur) echo '<div class="tl-off" style="top:' . (($cur - $lo) * $PX) . 'px;height:' . (($clamp($a) - $cur) * $PX) . 'px"></div>'; $cur = max($cur, $clamp($b)); }
        if ($cur < $hi) echo '<div class="tl-off" style="top:' . (($cur - $lo) * $PX) . 'px;height:' . (($hi - $cur) * $PX) . 'px"></div>'; ?>
        <?php foreach ($blocks as $b): if ($b['professional_id'] !== null && (int)$b['professional_id'] !== $pid) continue; $a = $clamp($mins($b['starts_at'])); $z = $clamp($mins($b['ends_at'])); ?>
          <div class="tl-block" style="top:<?= ($a - $lo) * $PX ?>px;height:<?= max(18, ($z - $a) * $PX) ?>px" title="<?= e($b['reason'] ?: 'Bloqueio') ?>"><i class="bi bi-lock-fill"></i> <?= e($b['reason'] ?: 'Bloqueio') ?></div>
        <?php endforeach; ?>
        <?php foreach ($rows as $a): if ((int)$a['professional_id'] !== $pid) continue; $s0 = $mins($a['starts_at']); $s1 = $mins($a['ends_at']); $dead = in_array($a['status'], ['canceled', 'no_show'], true); ?>
          <a class="tl-appt st-<?= e($a['status']) ?><?= $dead ? ' dead' : '' ?>" href="<?= e(url('/painel/agendamentos/' . $a['id'])) ?>" style="top:<?= ($s0 - $lo) * $PX ?>px;height:<?= max(26, ($s1 - $s0) * $PX - 2) ?>px;--pc:<?= e($p['color']) ?>">
            <b><?= e($a['customer_name']) ?></b><span><?= e(dt_local($a['starts_at'], $ctx['tz'], 'H:i')) ?>–<?= e(dt_local($a['ends_at'], $ctx['tz'], 'H:i')) ?> · <?= e($a['services']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php if ($nowMin !== null && $nowMin >= $lo && $nowMin <= $hi): ?><div class="tl-now" style="top:<?= ($nowMin - $lo) * $PX ?>px"><i></i></div><?php endif; ?>
    </div>
  </div>
  </div>
  <?php if (!$rows): ?><p class="muted small text-center mb-0 mt-3"><i class="bi bi-calendar2-check"></i> Nenhum agendamento neste dia. As áreas hachuradas são fora do expediente.</p><?php endif; ?>
</div>
<?php endif; ?>
