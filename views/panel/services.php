<?php $form = function (?array $s) {
    $p = $s ?? [];
    return fld('name', 'Nome', $p['name'] ?? '', ['required' => 1, 'maxlength' => 80, 'id' => $s ? (string)$s['id'] : ''])
    . '<div class="grid g2">' . fld('price', 'Preço (R$)', isset($p['price_cents']) ? number_format($p['price_cents'] / 100, 2, ',', '') : '', ['required' => 1, 'inputmode' => 'decimal', 'id' => $s ? (string)$s['id'] : ''])
    . fld('duration', 'Duração (min)', $p['duration_min'] ?? 30, ['type' => 'number', 'min' => 5, 'max' => 600, 'required' => 1, 'id' => $s ? (string)$s['id'] : '']) . '</div>'
    . '<div class="grid g2">' . fld('buffer_before', 'Folga antes (min)', $p['buffer_before_min'] ?? 0, ['type' => 'number', 'min' => 0, 'max' => 240, 'id' => $s ? (string)$s['id'] : ''])
    . fld('buffer_after', 'Folga depois (min)', $p['buffer_after_min'] ?? 0, ['type' => 'number', 'min' => 0, 'max' => 240, 'hint' => 'Tempo de limpeza/preparo', 'id' => $s ? (string)$s['id'] : '']) . '</div>'
    . fld('description', 'Descrição', $p['description'] ?? '', ['maxlength' => 300, 'id' => $s ? (string)$s['id'] : '']);
}; ?>
<div class="page-head"><h1>Serviços</h1></div>
<?php if (Ctx::writable($ctx)): ?>
<details class="card" <?= $rows ? '' : 'open' ?>><summary>Novo serviço</summary>
  <form method="post" action="<?= e(url('/painel/servicos')) ?>" style="margin-top:12px"><?= csrf_field() ?><?= $form(null) ?>
    <?= fld('bookable_online', 'Disponível na página pública', 1, ['type' => 'checkbox']) ?><button class="btn btn-primary">Criar serviço</button></form></details>
<?php endif; ?>
<?php if (!$rows): ?><div class="card empty">Cadastre seu primeiro serviço (ex.: Corte, Barba).</div><?php endif; ?>
<?php foreach ($rows as $s): ?>
<details class="card"><summary><?= e($s['name']) ?> · <?= e(money((int)$s['price_cents'])) ?> · <?= (int)$s['duration_min'] ?> min <?= (int)$s['active'] ? '' : badge('inativo') ?></summary>
  <form method="post" action="<?= e(url('/painel/servicos/' . $s['id'])) ?>" style="margin-top:12px"><?= csrf_field() ?><?= $form($s) ?>
    <?= fld('bookable_online', 'Disponível na página pública', (int)$s['bookable_online'], ['type' => 'checkbox', 'id' => 'b' . $s['id']]) ?>
    <?= fld('active', 'Ativo', (int)$s['active'], ['type' => 'checkbox', 'id' => 'a' . $s['id'], 'hint' => 'Serviços inativos somem da agenda, mas o histórico é mantido.']) ?>
    <?php if (Ctx::writable($ctx)): ?><button class="btn btn-primary">Salvar</button><?php endif; ?></form></details>
<?php endforeach; ?>
