<?php $s = $d['shop']; $sub = $d['sub']; $susp = $s['status'] === 'suspended'; ?>
<div class="page-head"><div><a class="small" href="<?= e(url('/admin/barbearias')) ?>">← Negócios</a><h1 style="margin-top:4px"><?= e($s['name']) ?> <?= $susp ? badge('suspensa', 'red') : '' ?></h1></div></div>
<div class="grid g2">
<div class="card"><h2>Assinatura</h2>
  <?php if ($sub): ?><p>Plano <b><?= e($sub['plan_name']) ?></b> · <?= e(money((int)$sub['price_cents'])) ?>/mês · <?= e(SUB_STATUS[$sub['status']] ?? $sub['status']) ?></p>
  <p class="small muted">Período até: <?= $sub['current_period_end'] ? e(dt_local($sub['current_period_end'], $s['timezone'])) : '—' ?> · carência até: <?= $sub['grace_until'] ? e(dt_local($sub['grace_until'], $s['timezone'])) : '—' ?></p><?php endif; ?>
  <h3>Cobranças</h3><?php foreach ($d['charges'] as $c): ?><div class="between small"><span><?= $c['due_date'] ? e(date('d/m/Y', strtotime($c['due_date']))) : '—' ?></span><span><?= e(money((int)$c['amount_cents'])) ?> · <?= e($c['status']) ?></span></div><?php endforeach; ?>
  <?php if (!$d['charges']): ?><p class="muted small">Sem cobranças.</p><?php endif; ?>
  <hr class="sep"><h3>Registrar pagamento (Pix ou dinheiro)</h3>
  <form method="post" action="<?= e(url('/admin/barbearias/' . $s['id'] . '/pagamento')) ?>" data-confirm="Confirmar que o pagamento foi recebido? A assinatura será ativada/renovada por 1 mês."><?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e(bin2hex(random_bytes(12))) ?>">
    <div class="grid g2"><?= fld('amount', 'Valor recebido (R$)', $sub ? number_format((int)$sub['price_cents'] / 100, 2, ',', '') : '', ['required' => 1, 'inputmode' => 'decimal']) ?><?= fld('note', 'Observação (opcional)', '', ['maxlength' => 200, 'placeholder' => 'Pix, comprovante enviado em ...']) ?></div>
    <button class="btn btn-primary">Registrar pagamento</button></form>
  <h3 style="margin-top:14px">Uso</h3><?php foreach ($d['usage'] as $u): ?><div class="between small"><span><?= e($u['period']) ?> · <?= e($u['metric']) ?></span><span><?= (int)$u['cnt'] ?></span></div><?php endforeach; ?>
</div>
<div class="card"><h2>Equipe com acesso</h2><?php foreach ($d['members'] as $m): ?><div class="between small"><span><?= e($m['name']) ?> · <?= e($m['email']) ?></span><span><?= e($m['role']) ?></span></div><?php endforeach; ?>
  <hr class="sep"><h2><?= $susp ? 'Reativar' : 'Suspender' ?></h2>
  <form method="post" action="<?= e(url('/admin/barbearias/' . $s['id'] . ($susp ? '/reativar' : '/suspender'))) ?>" data-confirm="<?= $susp ? 'Reativar este negócio?' : 'Suspender este negócio? O acesso será bloqueado, sem apagar dados.' ?>"><?= csrf_field() ?>
    <?= fld('reason', 'Motivo (obrigatório, fica no registro)', '', ['required' => 1, 'minlength' => 5, 'maxlength' => 300]) ?>
    <button class="btn <?= $susp ? 'btn-primary' : 'btn-danger' ?> btn-outline-dark"><?= $susp ? 'Reativar negócio' : 'Suspender negócio' ?></button></form>
</div></div>
<div class="grid g2">
<div class="card"><h2>Notas internas</h2>
  <form method="post" action="<?= e(url('/admin/barbearias/' . $s['id'] . '/nota')) ?>"><?= csrf_field() ?><?= fld('note', 'Nova nota', '', ['type' => 'textarea', 'rows' => 2, 'required' => 1, 'maxlength' => 1000]) ?><button class="btn btn-outline-dark">Salvar nota</button></form>
  <?php foreach ($d['notes'] as $n): ?><p class="small" style="margin-top:10px"><?= e($n['note']) ?><br><span class="muted"><?= e($n['author']) ?> · <?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?> UTC</span></p><?php endforeach; ?></div>
<div class="card"><h2>Registro de auditoria</h2>
  <?php foreach ($d['audits'] as $a): ?><div class="small" style="padding:3px 0;border-bottom:1px solid var(--line)"><b><?= e($a['action']) ?></b> <span class="muted">(<?= e($a['actor_kind']) ?>) <?= e(date('d/m H:i', strtotime($a['created_at']))) ?></span><?= $a['detail'] ? '<br>' . e($a['detail']) : '' ?></div><?php endforeach; ?></div>
</div>
