<?php
$smtp = Mailer::settings(); $as = Asaas::settings(); $wa = WhatsApp::settings();
$sel = fn($v, $c) => $v === $c ? ' selected' : '';
?>
<div class="page-head"><h1>Sistema</h1></div>
<div class="grid g2" style="align-items:start">
<div>
<form method="post" action="<?= e(url('/admin/sistema')) ?>" class="card"><?= csrf_field() ?>
  <h2>Nome e pagamento manual</h2>
  <?= fld('brand', 'Nome do sistema', old('brand', brand()), ['required' => 1, 'maxlength' => 40, 'hint' => 'Aparece no site, no painel, nos termos e na página de agendamento.']) ?>
  <?= fld('support', 'Como o cliente paga (Pix manual, aparece na tela Assinatura)', old('support', sys_setting('support_contact')), ['type' => 'textarea', 'rows' => 3, 'maxlength' => 600, 'hint' => 'Ex.: Pix para a chave 00.000.000/0001-00. Depois de pagar, envie o comprovante no WhatsApp (11) 90000-0000.']) ?>
  <?= fld('entity', 'Razão social e CNPJ (aparece nos Termos e na Privacidade)', old('entity', sys_setting('legal_entity')), ['maxlength' => 160, 'placeholder' => 'Minha Empresa Ltda — CNPJ 00.000.000/0001-00']) ?>
  <?= fld('privacy_email', 'E-mail para pedidos de privacidade (LGPD)', old('privacy_email', sys_setting('privacy_email')), ['type' => 'email', 'hint' => 'Para onde titulares e estabelecimentos escrevem sobre dados pessoais.']) ?>
  <button class="btn btn-primary">Salvar</button>
</form>

<form method="post" action="<?= e(url('/admin/sistema/email')) ?>" class="card" autocomplete="off"><?= csrf_field() ?>
  <h2>E-mail (SMTP) <?= Mailer::configured() ? badge('ligado', 'green') : badge('desligado', 'neutral') ?></h2>
  <p class="muted small">Usado em recuperação de senha e convites. Use os dados do seu provedor (Gmail com senha de app, Brevo, Mailgun, a própria hospedagem...). Deixe o servidor vazio e salve para desligar.</p>
  <div class="grid g2"><?= fld('host', 'Servidor', old('host', $smtp['host'] ?? ''), ['placeholder' => 'smtp.exemplo.com']) ?><?= fld('port', 'Porta', old('port', $smtp['port'] ?? 587), ['inputmode' => 'numeric']) ?></div>
  <div class="field"><label class="form-label" for="f_security">Criptografia</label><select class="form-select" id="f_security" name="security">
    <option value="tls"<?= $sel($smtp['security'] ?? 'tls', 'tls') ?>>STARTTLS (porta 587)</option><option value="ssl"<?= $sel($smtp['security'] ?? '', 'ssl') ?>>SSL/TLS direto (porta 465)</option><option value="none"<?= $sel($smtp['security'] ?? '', 'none') ?>>Nenhuma (só para testes)</option></select></div>
  <div class="grid g2"><?= fld('user', 'Usuário', old('user', $smtp['user'] ?? ''), ['autocomplete' => 'off']) ?><?= fld('pass', 'Senha', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => !empty($smtp['pass']) ? 'Salva (' . Secrets::mask((string)$smtp['pass']) . '). Deixe em branco para manter.' : '']) ?></div>
  <div class="grid g2"><?= fld('from_email', 'E-mail de envio', old('from_email', $smtp['from_email'] ?? '')) ?><?= fld('from_name', 'Nome de envio', old('from_name', $smtp['from_name'] ?? brand()), ['maxlength' => 60]) ?></div>
  <div class="actions"><button class="btn btn-primary">Salvar e-mail</button>
  <?php if (Mailer::configured()): ?><button class="btn btn-outline-dark" formaction="<?= e(url('/admin/sistema/email-teste')) ?>" formnovalidate>Enviar e-mail de teste</button><?php endif; ?></div>
</form>
</div>

<div>
<form method="post" action="<?= e(url('/admin/sistema/asaas')) ?>" class="card" autocomplete="off"><?= csrf_field() ?>
  <h2>Cobrança automática (Asaas) <?= Asaas::configured() ? badge(Asaas::env() === 'production' ? 'produção' : 'teste', Asaas::env() === 'production' ? 'green' : 'brass') : badge('desligada', 'neutral') ?></h2>
  <p class="muted small">Com a chave salva, o botão “Assinar” cria a cobrança mensal no Asaas (Pix, boleto ou cartão). A assinatura só é ativada quando o webhook confirmar o pagamento. Comece pelo ambiente de teste.</p>
  <?= fld('api_key', 'Chave da API', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => !empty($as['api_key']) ? 'Salva (' . Secrets::mask((string)$as['api_key']) . '). Deixe em branco para manter.' : 'Começa com $aact_hmlg_ (teste) ou $aact_prod_ (produção).']) ?>
  <div class="field"><label class="form-label" for="f_env">Ambiente</label><select class="form-select" id="f_env" name="env"><option value="sandbox"<?= $sel($as['env'] ?? 'sandbox', 'sandbox') ?>>Teste (sandbox)</option><option value="production"<?= $sel($as['env'] ?? '', 'production') ?>>Produção</option></select></div>
  <div class="field"><label class="form-label">Configure no Asaas (Integrações → Webhooks)</label>
    <input class="form-control mb-2" readonly value="<?= e(abs_url('/webhook/pagamento')) ?>" aria-label="URL do webhook">
    <input class="form-control" readonly value="<?= e(Billing::webhookToken()) ?>" aria-label="Token de autenticação do webhook">
    <p class="form-text">Cole a URL e o token acima. Marque os eventos de <b>cobranças</b> (criada, confirmada, recebida, vencida, estornada, contestada) e de <b>assinaturas</b> (inativada, removida). Fila sequencial.</p></div>
  <div class="actions"><button class="btn btn-primary">Salvar chave</button>
  <?php if (Asaas::configured()): ?><button class="btn btn-outline-dark" formaction="<?= e(url('/admin/sistema/asaas-teste')) ?>" formnovalidate>Testar conexão</button>
  <button class="btn btn-outline-danger" name="clear" value="1" formnovalidate data-confirm="Desligar a cobrança automática? Assinaturas já criadas no Asaas continuam lá.">Desligar</button><?php endif; ?></div>
</form>

<form method="post" action="<?= e(url('/admin/sistema/whatsapp')) ?>" class="card" autocomplete="off"><?= csrf_field() ?>
  <h2>WhatsApp automático <?= WhatsApp::configured() ? badge('ligado', 'green') : badge('desligado', 'neutral') ?></h2>
  <p class="muted small">Opcional. Sem isto, os negócios enviam com um toque pelo botão “Abrir no WhatsApp” (já funciona). Com a API oficial (Meta Cloud API), as mensagens saem sozinhas, mas cada tipo precisa de um <b>modelo aprovado</b> (categoria Utilidade, idioma português do Brasil) com este texto:</p>
  <ul class="small muted" style="padding-left:18px">
    <?php foreach (WhatsApp::TEMPLATE_TEXT as $k => $t): ?><li><b><?= e($k) ?></b>: <code><?= e($t) ?></code></li><?php endforeach; ?>
  </ul>
  <p class="small muted">As variáveis são: {{1}} primeiro nome, {{2}} nome do negócio, {{3}} data e hora, {{4}} serviços.</p>
  <?= fld('token', 'Token de acesso permanente', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => !empty($wa['token']) ? 'Salvo (' . Secrets::mask((string)$wa['token']) . '). Deixe em branco para manter.' : '']) ?>
  <div class="grid g2"><?= fld('phone_number_id', 'ID do número de telefone', old('phone_number_id', $wa['phone_number_id'] ?? ''), ['inputmode' => 'numeric']) ?><?= fld('api_version', 'Versão da API', old('api_version', $wa['api_version'] ?? 'v21.0')) ?></div>
  <div class="grid g2"><?php foreach (WhatsApp::KINDS as $k): ?><?= fld('tpl_' . $k, 'Modelo: ' . $k, old('tpl_' . $k, $wa['templates'][$k] ?? 'agenda_' . $k), ['maxlength' => 100]) ?><?php endforeach; ?></div>
  <?= fld('lang', 'Idioma dos modelos', old('lang', $wa['lang'] ?? 'pt_BR'), ['maxlength' => 5]) ?>
  <div class="actions"><button class="btn btn-primary">Salvar WhatsApp</button>
  <?php if (WhatsApp::configured()): ?><span class="inline"><input class="form-control" name="to" placeholder="DDD + número para o teste" style="width:210px;display:inline-block" form="waTest"><button class="btn btn-outline-dark" form="waTest">Enviar teste</button></span>
  <button class="btn btn-outline-danger" name="clear" value="1" formnovalidate data-confirm="Desligar o envio automático pelo WhatsApp?">Desligar</button><?php endif; ?></div>
</form>
<?php if (WhatsApp::configured()): ?><form id="waTest" method="post" action="<?= e(url('/admin/sistema/whatsapp-teste')) ?>"><?= csrf_field() ?></form><?php endif; ?>
</div>
</div>
<div class="card" style="max-width:640px"><h2>Como ativar um cliente que pagou por fora</h2>
  <p class="muted mb-0">Em <a href="<?= e(url('/admin/barbearias')) ?>">Negócios</a>, abra o cliente e use <b>Registrar pagamento</b>. A assinatura fica ativa por mais 1 mês e o registro fica na auditoria.</p></div>
