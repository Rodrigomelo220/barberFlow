<?php
$types = [['scissors', 'Barbearias'], ['stars', 'Salões de beleza'], ['eye', 'Design de sobrancelhas'], ['palette', 'Nail designers'], ['droplet-half', 'Estética']];
$features = [
  ['calendar-check', 'Agenda sem conflito', 'Os horários livres são calculados por profissional, duração do serviço, expediente e bloqueios. Dois clientes nunca ficam com o mesmo horário.'],
  ['link-45deg', 'Página própria de agendamento', 'Com a sua logo e a sua capa. Você divulga o link no Instagram, no WhatsApp e no Google, e o cliente marca sozinho.'],
  ['cash-coin', 'Financeiro sem confusão', 'Recebido, pendente e despesas separados. O que está previsto nunca se mistura com o dinheiro que entrou.'],
  ['people', 'Clientes organizados', 'Histórico de atendimentos, faltas e contato de cada cliente em um só lugar.'],
  ['arrow-repeat', 'Remarcação pelo cliente', 'O cliente remarca ou cancela pelo link dele, dentro da regra de antecedência que você definir.'],
  ['moon-stars', 'Claro ou escuro', 'No computador ou no celular, do jeito que for mais confortável para os seus olhos.'],
];
$steps = [
  ['Crie sua conta', 'Cadastre seus serviços, preços e horários de atendimento. Leva poucos minutos.'],
  ['Divulgue seu link', 'Coloque o endereço da sua página no Instagram, no WhatsApp e no Google Meu Negócio.'],
  ['Atenda com a agenda em dia', 'Os agendamentos aparecem no painel, no celular ou no computador, já sem conflito de horário.'],
];
$faq = [
  ['Preciso instalar alguma coisa?', 'Não. O sistema funciona no navegador, no celular ou no computador.'],
  ['Posso testar antes de pagar?', 'Sim. Os planos têm período de teste, sem precisar de cartão para começar.'],
  ['Atende mais de um profissional?', 'O plano Solo é para quem atende sozinho. O plano Profissional comporta uma equipe, cada um com sua agenda e seus horários.'],
  ['Se eu atrasar o pagamento, perco meus dados?', 'Não. Primeiro há uma carência, depois o painel passa para modo leitura. Seus clientes e seu histórico ficam guardados.'],
  ['Meus clientes precisam criar conta?', 'Não. Eles escolhem o serviço e o horário na sua página e informam só nome e telefone.'],
];
?>
<section class="lp-hero">
  <div class="lp-hero-grid">
    <div class="lp-hero-copy">
      <h1>Agenda online para quem vive de hora marcada.</h1>
      <p class="lead">Seus clientes escolhem o horário pelo seu link. Você acompanha agenda, clientes e financeiro em um painel simples, feito para barbearias, salões, sobrancelhas, unhas e estética.</p>
      <div class="hero-actions">
        <a class="btn btn-danger btn-lg" href="<?= e(url('/cadastro')) ?>">Testar grátis <?= icon('arrow-up-right') ?></a>
        <a class="btn lp-ghost btn-lg" href="#planos">Ver planos</a>
      </div>
      <div class="lp-note"><span class="bi bi-shield-check" aria-hidden="true"></span> Sem instalar nada. Sem cartão para começar o teste.</div>
    </div>
    <div class="lp-visual" aria-label="Prévia ilustrativa da página de agendamento do <?= e(brand()) ?>" role="img">
      <div class="mock-window">
        <div class="mock-top">
          <div class="mock-brand"><?= brand_mark() ?><span>Sua página de agendamento</span></div>
          <span class="mock-pill"><span class="bi bi-circle-fill" style="font-size:.45rem" aria-hidden="true"></span> Online</span>
        </div>
        <div class="mock-heading">Escolha seu horário</div>
        <div class="mock-sub">Corte tradicional · 30 minutos</div>
        <div class="mock-slots">
          <span>09:00</span><span class="busy">09:30</span><span>10:00</span>
          <span>10:30</span><span class="selected">11:00</span><span>11:30</span>
          <span class="busy">14:00</span><span>14:30</span><span>15:00</span>
        </div>
        <div class="mock-footer"><span><strong>11:00</strong> · Hoje</span><strong>Horário selecionado ✓</strong></div>
      </div>
    </div>
  </div>
</section>

<section class="lp-sec lp-who" aria-labelledby="para-quem">
  <h2 id="para-quem" class="lp-h2">Feito para quem atende com hora marcada.</h2>
  <div class="lp-chips">
    <?php foreach ($types as [$ic, $t]): ?><span class="lp-chip"><span class="bi bi-<?= e($ic) ?>" aria-hidden="true"></span><?= e($t) ?></span><?php endforeach; ?>
  </div>
</section>

<section class="lp-sec" aria-labelledby="recursos-titulo">
  <h2 id="recursos-titulo" class="lp-h2">Tudo o que a agenda do seu negócio precisa.</h2>
  <p class="lp-sub">Menos mensagem perdida, menos caderno, menos planilha. Mais tempo para atender.</p>
  <div class="lp-features">
    <?php foreach ($features as [$ic, $name, $desc]): ?>
      <article class="lp-feature">
        <span class="lp-ic"><span class="bi bi-<?= e($ic) ?>" aria-hidden="true"></span></span>
        <h3><?= e($name) ?></h3>
        <p><?= e($desc) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="lp-sec lp-steps" aria-labelledby="como-titulo">
  <h2 id="como-titulo" class="lp-h2">Como funciona.</h2>
  <ol class="lp-steplist">
    <?php foreach ($steps as $i => [$t, $d]): ?>
      <li><span class="lp-num"><?= $i + 1 ?></span><div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></li>
    <?php endforeach; ?>
  </ol>
</section>

<section id="planos" class="lp-sec" aria-labelledby="planos-titulo">
  <h2 id="planos-titulo" class="lp-h2 center">Dois planos, sem pegadinha.</h2>
  <p class="lp-sub center">Comece com o teste e escolha o plano que cabe no seu negócio.</p>
  <div class="lp-plans">
  <?php foreach ($plans as $i => $p): $hl = decode_list($p['highlights']); $featured = count($plans) >= 2 && $i === count($plans) - 1; ?>
    <article class="lp-plan<?= $featured ? ' featured' : '' ?>">
      <div class="lp-plan-name"><?= e($p['name']) ?></div>
      <p class="lp-plan-desc"><?= e($p['description']) ?></p>
      <div class="lp-price"><?= e(money((int)$p['price_cents'])) ?><span> / mês</span></div>
      <?php if ((int)$p['trial_days'] > 0): ?><p class="small muted mb-0"><?= (int)$p['trial_days'] ?> dias de teste</p><?php endif; ?>
      <ul><?php foreach ($hl as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
      <a class="btn <?= $featured ? 'btn-danger' : 'btn-primary' ?> w-100" href="<?= e(url('/cadastro?plano=' . rawurlencode($p['code']))) ?>">Começar o teste</a>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (!$plans): ?><p class="empty">Nenhum plano disponível no momento.</p><?php endif; ?>
</section>

<section class="lp-sec lp-faq" aria-labelledby="faq-titulo">
  <h2 id="faq-titulo" class="lp-h2">Perguntas frequentes.</h2>
  <?php foreach ($faq as [$q, $a]): ?>
    <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
  <?php endforeach; ?>
</section>

<section class="lp-cta">
  <h2>Sua próxima agenda cheia começa aqui.</h2>
  <a class="btn btn-danger btn-lg" href="<?= e(url('/cadastro')) ?>">Criar minha conta <?= icon('arrow-up-right') ?></a>
</section>
