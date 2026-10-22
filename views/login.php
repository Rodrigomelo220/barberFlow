<div class="auth-wrap"><?php require BF_ROOT . '/views/_auth_aside.php'; ?><div class="auth-card">
  <h1>Entrar</h1>
  <form method="post" action="<?= e(url('/entrar')) ?>" class="card">
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <?= fld('email', 'E-mail', old('email'), ['type' => 'email', 'required' => 1, 'autocomplete' => 'email', 'autofocus' => 1]) ?>
    <?= fld('password', 'Senha', '', ['type' => 'password', 'required' => 1, 'autocomplete' => 'current-password']) ?>
    <button class="btn btn-primary w-100">Entrar</button>
    <p class="small" style="margin-top:12px"><a href="<?= e(url('/recuperar-senha')) ?>">Esqueci minha senha</a></p>
  </form>
  <p class="center">Ainda não tem conta? <a href="<?= e(url('/cadastro')) ?>">Criar conta</a></p>
</div></div>
