<div class="auth-wrap"><?php require BF_ROOT . '/views/_auth_aside.php'; ?><div class="auth-card">
  <h1>Criar conta</h1>
  <form method="post" action="<?= e(url('/cadastro')) ?>" class="card">
    <?= csrf_field() ?><input type="hidden" name="plano" value="<?= e($plan) ?>">
    <?= fld('name', 'Seu nome', old('name'), ['required' => 1, 'autocomplete' => 'name', 'autofocus' => 1]) ?>
    <?= fld('email', 'E-mail', old('email'), ['type' => 'email', 'required' => 1, 'autocomplete' => 'email']) ?>
    <?= fld('password', 'Senha', '', ['type' => 'password', 'required' => 1, 'minlength' => 10, 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres, com letras e números.']) ?>
    <p class="small muted">Ao criar a conta você concorda com os <a href="<?= e(url('/termos')) ?>">termos</a> e a <a href="<?= e(url('/privacidade')) ?>">política de privacidade</a>.</p>
    <button class="btn btn-primary w-100">Criar conta</button>
  </form>
  <p class="center">Já tem conta? <a href="<?= e(url('/entrar')) ?>">Entrar</a></p>
</div></div>
