<?php $more = count($rows) > 30; $rows = array_slice($rows, 0, 30); ?>
<div class="page-head"><h1>Clientes</h1></div>
<form method="get" class="card actions"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome ou telefone" aria-label="Buscar" style="flex:1;min-width:180px"><button class="btn btn-outline-dark">Buscar</button></form>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Nome</th><th>Telefone</th><th class="num">Visitas</th><th>Última</th><th class="num">Faltas</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?>
  <tr><td><a href="<?= e(url('/painel/clientes/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td class="nowrap"><?= e(Scheduling::formatPhone($c['phone'])) ?></td><td class="num"><?= (int)$c['visits'] ?></td>
  <td><?= $c['last_visit'] ? e(dt_local($c['last_visit'], $ctx['tz'], 'd/m/Y')) : '—' ?></td><td class="num"><?= (int)$c['no_shows'] ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!$rows): ?><p class="empty"><?= $q !== '' ? 'Nenhum cliente encontrado.' : 'Os clientes aparecem aqui quando agendam ou quando você os cadastra.' ?></p><?php endif; ?>
<div class="actions"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-dark" href="?q=<?= e(rawurlencode($q)) ?>&p=<?= $page - 1 ?>">← Anteriores</a><?php endif; ?><?php if ($more): ?><a class="btn btn-sm btn-outline-dark" href="?q=<?= e(rawurlencode($q)) ?>&p=<?= $page + 1 ?>">Próximos →</a><?php endif; ?></div></div>
<?php if (Ctx::can($ctx, 'customers.manage')): ?>
<details class="card"><summary>Cadastrar cliente</summary>
  <form method="post" action="<?= e(url('/painel/clientes')) ?>" style="margin-top:12px"><?= csrf_field() ?>
    <div class="grid g2"><?= fld('name', 'Nome', old('name'), ['required' => 1]) ?><?= fld('phone', 'Telefone / WhatsApp', old('phone'), ['type' => 'tel', 'required' => 1, 'inputmode' => 'tel']) ?></div>
    <?= fld('preferences', 'Preferências', old('preferences'), ['maxlength' => 500, 'placeholder' => 'ex.: degradê baixo, máquina 2']) ?>
    <?= fld('notes', 'Anotações', old('notes'), ['type' => 'textarea']) ?>
    <button class="btn btn-primary">Salvar cliente</button></form></details>
<?php endif; ?>
