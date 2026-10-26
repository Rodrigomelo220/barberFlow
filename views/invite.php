<div class="narrow" style="margin:0 auto">
  <h1>Você foi convidado(a)</h1>
  <div class="card"><p class="mb-2">Acesso à agenda de <b><?= e($inv['shop_name']) ?></b> como <b><?= e($inv['role'] === 'manager' ? 'gerente' : 'atendente') ?></b>.</p>
  <?php if ($user): ?>
    <?php if (mb_strtolower($user['email']) === mb_strtolower($inv['email'])): ?>
      <form method="post" action="<?= e(url('/convite/' . $token)) ?>"><?= csrf_field() ?><button class="btn btn-primary w-100">Aceitar convite</button></form>
    <?php else: ?>
      <p class="mb-2">Você está logado como <b><?= e($user['email']) ?></b>, mas o convite é para <b><?= e($inv['email']) ?></b>.</p>
      <?= post_form('/sair', '<button class="btn btn-outline-dark w-100">Sair e usar o e-mail do convite</button>') ?>
    <?php endif; ?>
  <?php else: ?>
    <form method="post" action="<?= e(url('/convite/' . $token)) ?>"><?= csrf_field() ?>
      <p class="muted small">Crie seu acesso com o e-mail <b><?= e($inv['email']) ?></b>. Se já tem conta, <a href="<?= e(url('/entrar?next=' . rawurlencode('/convite/' . $token))) ?>">entre aqui</a>.</p>
      <?= fld('name', 'Seu nome', old('name'), ['required' => 1, 'maxlength' => 120, 'autocomplete' => 'name']) ?>
      <?= fld('password', 'Senha', '', ['type' => 'password', 'required' => 1, 'minlength' => 10, 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres, com letras e números.']) ?>
      <button class="btn btn-primary w-100">Criar acesso</button>
    </form>
  <?php endif; ?></div>
</div>
