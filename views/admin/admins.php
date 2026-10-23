<div class="page-head"><h1>Administradores</h1></div>
<div class="alert alert-info">Administradores veem todas as barbearias e cobranças da plataforma. Para adicionar alguém, a pessoa precisa <b>já ter criado uma conta</b> (em /cadastro); depois informe o e-mail dela aqui.</div>
<form method="post" action="<?= e(url('/admin/administradores')) ?>" class="card"><?= csrf_field() ?><h2>Adicionar administrador</h2>
  <?= fld('email', 'E-mail da conta', old('email'), ['type' => 'email', 'required' => 1]) ?>
  <button class="btn btn-primary">Adicionar</button></form>
<div class="card"><h2>Administradores atuais</h2>
<?php foreach ($rows as $a): ?>
  <div class="between" style="padding:8px 0;border-bottom:1px solid var(--bf-line)"><span><b><?= e($a['name']) ?></b><br><span class="small muted"><?= e($a['email']) ?><?= (int)$a['id'] === (int)$u['id'] ? ' · você' : '' ?></span></span>
  <?php if ((int)$a['id'] !== (int)$u['id']): ?><?= post_form('/admin/administradores/' . (int)$a['id'] . '/remover', '<button class="btn btn-sm btn-outline-danger">Remover acesso</button>', '', 'Remover o acesso de administrador de ' . $a['email'] . '?') ?><?php endif; ?></div>
<?php endforeach; ?></div>
