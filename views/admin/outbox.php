<div class="page-head"><h1>Mensagens (simuladas)</h1></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Quando (UTC)</th><th>Barbearia</th><th>Tipo</th><th>Situação</th><th>Detalhe</th></tr></thead><tbody>
<?php foreach ($rows as $m): ?><tr><td class="nowrap"><?= e($m['send_at']) ?></td><td><?= e($m['shop']) ?></td><td><?= e($m['kind']) ?></td><td><?= e($m['status']) ?></td><td class="small"><?= e($m['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (!$rows): ?><p class="empty">Sem mensagens.</p><?php endif; ?></div>
