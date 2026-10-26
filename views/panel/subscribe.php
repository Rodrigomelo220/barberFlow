<div class="page-head"><div><a class="small" href="<?= e(url('/painel/assinatura')) ?>">← Assinatura</a><h1 style="margin-top:4px">Assinar o plano <?= e($plan['name']) ?></h1></div></div>
<form method="post" action="<?= e(url('/painel/assinatura/assinar')) ?>" class="card" style="max-width:560px"><?= csrf_field() ?>
  <input type="hidden" name="plan" value="<?= e($plan['code']) ?>">
  <p><b><?= e(money((int)$plan['price_cents'])) ?></b> por mês, cobrados todo mês. Você escolhe Pix, boleto ou cartão na página de pagamento e pode cancelar quando quiser.</p>
  <?= fld('name', 'Nome completo ou razão social', old('name', $ctx['user']['name']), ['required' => 1, 'maxlength' => 120, 'autocomplete' => 'name']) ?>
  <?= fld('doc', 'CPF ou CNPJ', old('doc'), ['required' => 1, 'inputmode' => 'numeric', 'maxlength' => 18, 'hint' => 'Necessário para emitir a cobrança. Usamos só para isso.']) ?>
  <button class="btn btn-danger">Continuar para o pagamento</button>
</form>
