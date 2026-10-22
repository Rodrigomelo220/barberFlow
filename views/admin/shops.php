<div class="page-head"><h1>Barbearias</h1></div>
<form method="get" class="card actions"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Nome, endereço ou e-mail do dono" aria-label="Buscar" style="flex:1;min-width:180px">
  <select class="form-select" name="status" aria-label="Situação" style="width:auto"><option value="">Todas</option><?php foreach (['active' => 'Ativas', 'trialing' => 'Em teste', 'past_due' => 'Em atraso', 'suspended' => 'Suspensas', 'canceled' => 'Canceladas', 'incomplete' => 'Aguardando pagamento'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-outline-dark">Filtrar</button></form>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Barbearia</th><th>Dono</th><th>Plano</th><th>Assinatura</th><th class="num">Agend. 30d</th><th>Criada</th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr><td><a href="<?= e(url('/admin/barbearias/' . $s['id'])) ?>"><?= e($s['name']) ?></a><br><span class="muted small">/b/<?= e($s['slug']) ?></span> <?= $s['shop_status'] === 'suspended' ? badge('suspensa', 'red') : '' ?></td>
  <td><?= e($s['owner_email']) ?></td><td><?= e($s['plan']) ?></td><td><?= e(SUB_STATUS[$s['sub_status'] ?? ''] ?? '—') ?></td><td class="num"><?= (int)$s['appts30'] ?></td><td><?= e(date('d/m/Y', strtotime($s['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (!$rows): ?><p class="empty">Nenhuma barbearia encontrada.</p><?php endif; ?></div>
