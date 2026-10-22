<div class="narrow" style="margin:0 auto">
  <h1>Nova senha</h1>
  <form method="post" action="<?= e(url('/redefinir-senha/' . $token)) ?>" class="card"><?= csrf_field() ?>
    <?= fld('password', 'Nova senha', '', ['type' => 'password', 'required' => 1, 'minlength' => 10, 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres, com letras e números.']) ?>
    <button class="btn btn-primary w-100">Salvar senha</button>
  </form>
</div>
