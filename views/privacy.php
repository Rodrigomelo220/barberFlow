<?php
$ent = sys_setting('legal_entity'); $pe = sys_setting('privacy_email'); $live = cfg('app.mode', 'demo') === 'live';
$subs = ['Hospedagem e banco de dados: o servidor onde este sistema está instalado'];
if (Mailer::configured()) $subs[] = 'Envio de e-mail (recuperação de senha e convites): o provedor SMTP configurado';
if (Asaas::configured()) $subs[] = 'Cobrança das assinaturas: Asaas (recebe nome, e-mail e CPF/CNPJ do contratante)';
if (WhatsApp::configured()) $subs[] = 'Lembretes por WhatsApp: Meta (WhatsApp Business Cloud API) — recebe o telefone e o texto da mensagem';
?>
<div class="mid legal" style="margin:0 auto"><h1>Privacidade e LGPD</h1>
<p class="muted small">Última atualização: <?= e(date('d/m/Y', filemtime(__FILE__) ?: time())) ?></p>
<div class="alert alert-warning"><b>Modelo para revisão jurídica.</b> Descreve o que o sistema faz hoje; revise com um profissional de proteção de dados antes de uso comercial.</div>
<div class="card stack">
<h2>1. Quem é quem</h2>
<p><b>Dados dos clientes finais</b> (quem agenda): o <b>Estabelecimento</b> é o controlador; o <?= e(brand()) ?><?= $ent !== '' ? ' (' . e($ent) . ')' : '' ?> atua como <b>operador</b>, tratando os dados só para prestar o serviço.<br>
<b>Dados de quem contrata o sistema</b> (nome, e-mail, negócio): o <?= e(brand()) ?> é o controlador.</p>
<h2>2. Que dados tratamos e para quê</h2>
<ul>
<li><b>Conta do Estabelecimento e da equipe:</b> nome, e-mail, senha (guardada apenas como hash). Finalidade: acesso e segurança. Base: execução do contrato.</li>
<li><b>Clientes finais:</b> nome, telefone, anotações e preferências, histórico de agendamentos e pagamentos. Finalidade: agendar e atender. Base: execução de contrato/procedimentos preliminares, a cargo do Estabelecimento.</li>
<li><b>Autorização de WhatsApp:</b> registrada por cliente. Sem a autorização marcada, nenhuma mensagem é programada. Base: consentimento, que pode ser retirado.</li>
<li><b>Dados de cobrança:</b> plano, valores e status de pagamento<?= Asaas::configured() ? '; CPF/CNPJ informado na contratação' : '' ?>. Base: execução do contrato e obrigação legal.</li>
<li><b>Registros técnicos:</b> logs de auditoria de ações no painel e endereço IP para limitar tentativas de acesso. Base: legítimo interesse (segurança).</li>
</ul>
<p>Não coletamos dados de saúde, documentos de identidade de clientes finais nem usamos os dados para publicidade ou venda.</p>
<h2>3. Com quem compartilhamos</h2>
<p>Só com os prestadores necessários ao serviço:</p>
<ul><?php foreach ($subs as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
<p>Não vendemos dados. Podemos divulgar informações quando uma lei ou ordem judicial exigir.</p>
<h2>4. Por quanto tempo guardamos</h2>
<p>Enquanto a conta existir. Inadimplência <b>não</b> apaga dados. O Estabelecimento pode <b>anonimizar</b> um cliente a qualquer momento (remove nome, telefone e anotações; mantém só valores financeiros sem identificação). Para excluir uma conta inteira, peça pelo e-mail abaixo; registros que a lei obrigue a manter (fiscais, de cobrança) ficam pelo prazo legal.</p>
<h2>5. Direitos do titular</h2>
<p>Você pode pedir confirmação do tratamento, acesso, correção, anonimização, portabilidade, eliminação, informação sobre compartilhamento e a retirada de consentimento (LGPD, art. 18). <b>Clientes finais:</b> peçam ao Estabelecimento — o painel tem exportação (arquivo com cadastro, histórico e mensagens) e anonimização. <b>Estabelecimentos e equipe:</b> escrevam para o contato abaixo; respondemos em até 15 dias.</p>
<h2>6. Segurança</h2>
<p>Senhas com hash; sessão por cookie protegido; proteção contra requisições forjadas (CSRF); limite de tentativas de acesso; cada negócio só acessa os próprios dados; chaves de integração (e-mail, cobrança, WhatsApp) guardadas criptografadas; registro de auditoria das ações administrativas.<?= $live ? '' : ' Esta é uma instalação de demonstração: não use dados reais de clientes.' ?> Nenhum sistema é totalmente imune; em caso de incidente com risco relevante, comunicaremos os afetados e a ANPD conforme a lei.</p>
<h2>7. Cookies</h2>
<p>Usamos apenas um cookie de sessão (necessário para você ficar conectado) e a preferência de tema claro/escuro guardada no seu navegador. Sem rastreamento publicitário nem análise de terceiros.</p>
<h2>8. Crianças e adolescentes</h2>
<p>O serviço é voltado a empresas e não deve ser contratado por menores de 18 anos. Se um Estabelecimento cadastrar clientes menores, é responsável por obter o consentimento de um responsável legal.</p>
<h2>9. Contato do encarregado (DPO)</h2>
<p><?= $pe !== '' ? '<a href="mailto:' . e($pe) . '">' . e($pe) . '</a>' : '<i>E-mail de privacidade ainda não informado pelo administrador do sistema.</i>' ?><?= $ent !== '' ? '<br>' . e($ent) : '' ?></p>
</div></div>
