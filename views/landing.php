<?php $features = [
  ['calendar-check', 'Agenda sem conflitos', 'Horários disponíveis calculados por profissional, duração do serviço, expediente e bloqueios.'],
  ['link-45deg', 'Agendamento pelo link', 'Compartilhe sua página no Instagram, WhatsApp e Google. O cliente agenda sem precisar ligar.'],
  ['cash-coin', 'Financeiro organizado', 'Acompanhe recebimentos, pendências, despesas e comissões sem misturar valores previstos com dinheiro recebido.'],
]; ?>
<section class="hero">
  <div class="hero-grid">
    <div class="hero-copy">
      <div class="eyebrow">Gestão para barbearias, salões e estúdios</div>
      <h1>Seu negócio organizado. <em>Seu tempo de volta.</em></h1>
      <p class="lead">Agenda, clientes e financeiro em um só lugar. Deixe o cliente escolher o horário online e tenha mais controle da rotina do seu negócio.</p>
      <div class="hero-actions">
        <a class="btn btn-danger" href="<?= e(url('/cadastro')) ?>">Começar agora <?= icon('arrow-up-right') ?></a>
        <a class="btn btn-outline-dark" href="#planos">Conhecer os planos</a>
      </div>
      <div class="hero-note"><span class="bi bi-shield-check" aria-hidden="true"></span> Sem instalar nada. Acesse pelo celular ou computador.</div>
    </div>
    <div class="hero-visual" aria-label="Prévia ilustrativa da agenda online do BarberFlow" role="img">
      <div class="mock-window">
        <div class="mock-top">
          <div class="mock-brand"><span class="mock-mark">B</span><span>Sua agenda online</span></div>
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
  <div class="hero-proof">
    <div class="proof-item"><span class="proof-icon"><span class="bi bi-calendar2-check" aria-hidden="true"></span></span><span>Agenda e horários em um só lugar</span></div>
    <div class="proof-item"><span class="proof-icon"><span class="bi bi-people" aria-hidden="true"></span></span><span>Clientes e equipe organizados</span></div>
    <div class="proof-item"><span class="proof-icon"><span class="bi bi-graph-up-arrow" aria-hidden="true"></span></span><span>Mais clareza sobre o financeiro</span></div>
  </div>
</section>

<section aria-labelledby="recursos-titulo" style="padding:34px 0 42px">
  <div class="section-heading">
    <div class="eyebrow">Tudo conectado</div>
    <h2 id="recursos-titulo">Menos confusão no dia a dia. Mais controle do negócio.</h2>
    <p>Ferramentas práticas para cuidar da operação sem depender de cadernos, mensagens perdidas ou planilhas espalhadas.</p>
  </div>
  <div class="grid g3">
    <?php foreach ($features as [$ic, $name, $desc]): ?>
      <article class="card feature-card">
        <div class="feature-icon"><span class="bi bi-<?= e($ic) ?>" aria-hidden="true"></span></div>
        <h3><?= e($name) ?></h3>
        <p><?= e($desc) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section id="planos" aria-labelledby="planos-titulo">
  <div class="section-heading">
    <div class="eyebrow">Planos simples</div>
    <h2 id="planos-titulo">Escolha o tamanho certo para o seu negócio.</h2>
    <p>Compare os recursos, comece com o plano que faz sentido para você e ajuste quando sua equipe crescer.</p>
  </div>
  <div class="grid g3">
  <?php foreach ($plans as $i => $p): $hl = decode_list($p['highlights']); $featured = count($plans) >= 3 && $i === 1; ?>
    <article class="card plan<?= $featured ? ' featured' : '' ?>">
      <div class="plan-kicker"><?= e($p['name']) ?></div>
      <h3><?= e($p['description']) ?></h3>
      <div class="price"><?= e(money((int)$p['price_cents'])) ?><span class="muted"> / mês</span></div>
      <?php if ((int)$p['trial_days'] > 0): ?><p class="small muted"><?= (int)$p['trial_days'] ?> dias de teste disponíveis</p><?php else: ?><p class="small muted">Plano mensal</p><?php endif; ?>
      <ul><?php foreach ($hl as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
      <a class="btn <?= $featured ? 'btn-danger' : 'btn-primary' ?> w-100" href="<?= e(url('/cadastro?plano=' . rawurlencode($p['code']))) ?>">Escolher plano <span class="bi bi-arrow-right" aria-hidden="true"></span></a>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (!$plans): ?><p class="empty">Nenhum plano disponível no momento.</p><?php endif; ?>
</section>
