<div class="page-head"><h1>Planos</h1></div>
<div class="alert alert-info">Mudar preço ou limites vale para <b>novas contratações e trocas de plano</b>. Quem já assina mantém o preço contratado. Não existe plano com mensagens ilimitadas: 0 significa que o plano não inclui mensagens.</div>
<?php $form = function (?array $p) {
    $i = $p ? (string)$p['id'] : 'n'; $v = fn($k, $d = '') => $p ? ($p[$k] ?? $d) : $d;
    return fld('code', 'Código', $v('code'), ['required' => 1, 'readonly' => $p ? 1 : 0, 'id' => $i, 'hint' => $p ? '' : 'minúsculas, ex.: premium'])
    . '<div class="grid g2">' . fld('name', 'Nome', $v('name'), ['required' => 1, 'id' => $i]) . fld('price', 'Preço mensal (R$)', $p ? number_format($p['price_cents'] / 100, 2, ',', '') : '', ['required' => 1, 'inputmode' => 'decimal', 'id' => $i]) . '</div>'
    . fld('description', 'Descrição curta', $v('description'), ['id' => $i])
    . '<div class="grid g3">' . fld('trial_days', 'Dias de teste', $v('trial_days', 0), ['type' => 'number', 'min' => 0, 'max' => 60, 'id' => $i])
    . fld('max_professionals', 'Máx. profissionais', $v('max_professionals'), ['type' => 'number', 'min' => 1, 'hint' => 'vazio = sem limite', 'id' => $i])
    . fld('max_appointments_month', 'Máx. agendamentos/mês', $v('max_appointments_month'), ['type' => 'number', 'min' => 1, 'hint' => 'vazio = sem limite', 'id' => $i]) . '</div>'
    . '<div class="grid g3">' . fld('max_messages_month', 'Mensagens/mês', $v('max_messages_month', 0), ['type' => 'number', 'min' => 0, 'hint' => '0 = não inclui', 'id' => $i]) . fld('sort_order', 'Ordem', $v('sort_order', 0), ['type' => 'number', 'id' => $i]) . '</div>'
    . fld('highlights', 'Destaques (um por linha)', $p ? implode("\n", decode_list($p['highlights'])) : '', ['type' => 'textarea', 'rows' => 4, 'id' => $i])
    . fld('feat_reminders', 'Inclui lembretes', (int)$v('feat_reminders', 0), ['type' => 'checkbox', 'id' => $i]) . fld('feat_commissions', 'Inclui comissões', (int)$v('feat_commissions', 0), ['type' => 'checkbox', 'id' => $i])
    . fld('is_active', 'Ativo (pode ser contratado)', (int)$v('is_active', 1), ['type' => 'checkbox', 'id' => $i]) . fld('is_public', 'Aparece na página inicial', (int)$v('is_public', 1), ['type' => 'checkbox', 'id' => $i]);
}; ?>
<?php foreach ($plans as $p): ?>
<details class="card"><summary><?= e($p['name']) ?> · <?= e(money((int)$p['price_cents'])) ?> <?= (int)$p['is_active'] ? '' : badge('inativo') ?> <?= (int)$p['is_public'] ? '' : badge('oculto') ?></summary>
  <form method="post" action="<?= e(url('/admin/planos')) ?>" style="margin-top:12px"><?= csrf_field() ?><?= $form($p) ?><button class="btn btn-primary">Salvar plano</button></form></details>
<?php endforeach; ?>
<details class="card"><summary>Novo plano</summary><form method="post" action="<?= e(url('/admin/planos')) ?>" style="margin-top:12px"><?= csrf_field() ?><?= $form(null) ?><button class="btn btn-primary">Criar plano</button></form></details>
