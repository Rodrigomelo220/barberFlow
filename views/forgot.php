<div class="narrow" style="margin:0 auto">
  <h1>Recuperar senha</h1>
  <form method="post" action="<?= e(url('/recuperar-senha')) ?>" class="card"><?= csrf_field() ?>
    <?= fld('email', 'E-mail da conta', '', ['type' => 'email', 'required' => 1, 'autofocus' => 1]) ?>
    <button class="btn btn-primary w-100">Enviar link</button>
    <p class="hint">Nesta versão de demonstração o e-mail não é enviado: o link fica registrado em <code>storage/mail.log</code> no servidor.</p>
  </form>
</div>
