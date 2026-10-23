<div class="mid" style="margin:0 auto">
  <h1>Cadastre seu negócio</h1>
  <form method="post" action="<?= e(url('/nova-barbearia')) ?>" class="card"><?= csrf_field() ?>
    <?= fld('name', 'Nome do negócio', old('name'), ['required' => 1, 'maxlength' => 80, 'autofocus' => 1]) ?>
    <?= fld('business_type', 'Tipo de negócio', old('business_type', 'barbearia'), ['type' => 'select', 'options' => Biz::options()]) ?>
    <?= fld('slug', 'Endereço da página de agendamento', old('slug'), ['placeholder' => 'ex.: studio-da-ana', 'hint' => 'Fica assim: ' . abs_url('/b/') . 'seu-endereço . Deixe vazio para gerar a partir do nome.']) ?>
    <?= fld('phone', 'Telefone / WhatsApp (opcional)', old('phone'), ['type' => 'tel', 'inputmode' => 'tel', 'placeholder' => '(11) 91234-5678']) ?>
    <fieldset style="border:0;padding:0;margin:0 0 8px"><legend style="font-weight:600;margin-bottom:6px">Plano</legend>
      <?php foreach ($plans as $i => $p): $sel = old('plan', $plan) === $p['code'] || (old('plan', $plan) === '' && $i === 0); ?>
        <label class="choice"><input type="radio" name="plan" value="<?= e($p['code']) ?>" <?= $sel ? 'checked' : '' ?>><span class="t"><b><?= e($p['name']) ?></b><br><span class="muted small"><?= e($p['description']) ?></span></span><span class="p"><?= e(money((int)$p['price_cents'])) ?>/mês</span></label>
      <?php endforeach; ?>
    </fieldset>
    <button class="btn btn-primary w-100">Criar negócio</button>
  </form>
</div>
