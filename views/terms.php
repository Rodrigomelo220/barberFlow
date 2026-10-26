<?php $ent = sys_setting('legal_entity'); $demo = cfg('app.mode', 'demo') === 'demo'; ?>
<div class="mid legal" style="margin:0 auto"><h1>Termos de uso</h1>
<p class="muted small">Última atualização: <?= e(date('d/m/Y', filemtime(__FILE__) ?: time())) ?></p>
<div class="alert alert-warning"><b>Modelo para revisão jurídica.</b> Este texto descreve como o sistema funciona hoje, mas não substitui a análise de um advogado. Revise antes de cobrar clientes.</div>
<?php if ($demo): ?><div class="alert alert-info"><b>Instalação de demonstração.</b> Aqui o pagamento e o envio de mensagens são simulados.</div><?php endif; ?>
<div class="card stack">
<h2>1. Quem somos e o que oferecemos</h2>
<p>O <?= e(brand()) ?><?= $ent !== '' ? ' é operado por ' . e($ent) : '' ?> e oferece agenda online, página pública de agendamento, cadastro de clientes e controle financeiro simples para negócios que atendem com hora marcada. Quem contrata o serviço (o "Estabelecimento") é o responsável pelo atendimento prestado aos próprios clientes.</p>
<h2>2. Cadastro e conta</h2>
<p>Você deve ter 18 anos ou mais, informar dados verdadeiros e manter sua senha em segredo. Você responde pelas ações feitas na sua conta, inclusive por pessoas da equipe que convidar. Avise-nos se suspeitar de acesso indevido.</p>
<h2>3. Planos, teste e pagamento</h2>
<p>Há um período de teste gratuito informado na contratação. Depois dele, o acesso completo depende de assinatura ativa, cobrada mensalmente pelo valor do plano escolhido. Os limites (profissionais, agendamentos e mensagens por mês) constam na descrição de cada plano. A cobrança pode ser feita por meio de um provedor de pagamentos ou por Pix, conforme informado na tela Assinatura. A assinatura só é ativada após a confirmação do pagamento.</p>
<h2>4. Atraso e inadimplência</h2>
<p>Em caso de atraso há uma carência de <?= (int)Billing::GRACE_DAYS ?> dias com acesso normal, seguida de <?= (int)Billing::RESTRICTED_DAYS ?> dias em modo leitura (você consulta e exporta os dados, mas não cria novos registros). <b>Nenhum dado é apagado por falta de pagamento.</b> Regularizado o pagamento, o acesso completo volta.</p>
<h2>5. Cancelamento</h2>
<p>Você pode cancelar a qualquer momento no painel. O acesso continua até o fim do período já pago. Reembolsos seguem a lei: na contratação online por consumidor vale o direito de arrependimento de 7 dias (Código de Defesa do Consumidor); fora isso, não há reembolso proporcional do mês em curso. Após o cancelamento seus dados ficam disponíveis para exportação; para exclusão definitiva, peça pelo e-mail de privacidade.</p>
<h2>6. Seus dados e os dados dos seus clientes</h2>
<p>Os dados dos seus clientes são seus. Você se compromete a coletá-los de forma lícita, a informar seus clientes e a pedir consentimento antes de enviar mensagens por WhatsApp. Nós tratamos esses dados apenas para prestar o serviço, conforme a <a href="<?= e(url('/privacidade')) ?>">Política de Privacidade</a>.</p>
<h2>7. Uso aceitável</h2>
<p>É proibido usar o sistema para enviar spam, publicar conteúdo ilegal ou ofensivo, tentar acessar dados de outros negócios, sobrecarregar ou burlar a segurança do serviço. Podemos suspender contas que violem estes termos, avisando o motivo.</p>
<h2>8. Mensagens automáticas</h2>
<p>Mensagens por WhatsApp e e-mail dependem de serviços de terceiros e podem falhar ou atrasar. Quando o envio automático não está configurado, o painel oferece o envio manual com um toque. Não garantimos a entrega nem a leitura.</p>
<h2>9. Disponibilidade e responsabilidade</h2>
<p>Trabalhamos para manter o serviço disponível e fazemos cópias de segurança, mas não prometemos funcionamento ininterrupto. Na extensão permitida pela lei, nossa responsabilidade se limita ao valor pago nos 12 meses anteriores ao fato, e não inclui lucros cessantes. Isso não afasta direitos do consumidor previstos em lei.</p>
<h2>10. Mudanças</h2>
<p>Podemos atualizar estes termos. Mudanças relevantes serão avisadas no painel ou por e-mail com antecedência razoável. Continuar usando o serviço após o aviso significa concordar com a nova versão.</p>
<h2>11. Lei aplicável e contato</h2>
<p>Estes termos seguem a lei brasileira. Fica eleito o foro do domicílio do consumidor, quando aplicável. Dúvidas: <?php $pe = sys_setting('privacy_email'); echo $pe !== '' ? '<a href="mailto:' . e($pe) . '">' . e($pe) . '</a>' : 'use o contato de suporte informado pelo seu fornecedor'; ?>.</p>
</div></div>
