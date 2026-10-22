<div class="page-head"><h1>Eventos de cobrança com falha</h1></div>
<p class="muted">Eventos recebidos do provedor que não puderam ser aplicados (por exemplo, assinatura não encontrada). Reprocesse depois de corrigir a causa.</p>
<div class="card"><?php if (!$rows): ?><p class="empty">Nenhum evento com falha.</p><?php endif; ?>
<?php foreach ($rows as $ev): ?><div class="between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span><b><?= e($ev['event_type']) ?></b> <span class="muted small"><?= e($ev['received_at']) ?> UTC</span><br><span class="small"><?= e($ev['error']) ?></span></span>
  <?= post_form('/admin/eventos/' . $ev['id'] . '/reprocessar', '<button class="btn btn-sm btn-outline-dark">Reprocessar</button>') ?></div><?php endforeach; ?></div>
