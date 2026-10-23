<div class="page-head"><h1>Sistema</h1></div>
<form method="post" action="<?= e(url('/admin/sistema')) ?>" class="card" style="max-width:640px"><?= csrf_field() ?>
  <h2>Nome e pagamento</h2>
  <?= fld('brand', 'Nome do sistema', old('brand', brand()), ['required' => 1, 'maxlength' => 40, 'hint' => 'Aparece no site, no painel, nos termos e na página de agendamento dos clientes.']) ?>
  <?= fld('support', 'Como o cliente paga (aparece na tela Assinatura)', old('support', sys_setting('support_contact')), ['type' => 'textarea', 'rows' => 4, 'maxlength' => 600, 'hint' => 'Ex.: Pix para a chave 00.000.000/0001-00 (Nome da empresa). Depois de pagar, envie o comprovante no WhatsApp (11) 90000-0000. A ativação leva até 1 dia útil.']) ?>
  <button class="btn btn-primary">Salvar</button>
</form>
<div class="card" style="max-width:640px"><h2>Como ativar um cliente que pagou</h2>
  <p class="muted mb-0">Em <a href="<?= e(url('/admin/barbearias')) ?>">Negócios</a>, abra o cliente e use <b>Registrar pagamento</b>. A assinatura fica ativa por mais 1 mês e o registro fica na auditoria. Se o cliente pagar de novo no mês seguinte, repita; o período soma a partir do vencimento.</p></div>
