<section class="hero">
  <h1>A agenda da barbearia sem choque de horário.</h1>
  <p class="lead">Seus clientes marcam pelo link, você acompanha tudo no celular e o sistema impede dois agendamentos no mesmo horário com o mesmo profissional.</p>
  <div class="actions"><a class="btn btn-danger" href="<?= e(url('/cadastro')) ?>">Criar conta e testar</a><a class="btn btn-outline-dark" href="#planos">Ver planos</a></div>
  <div class="demo-grid" role="img" aria-label="Exemplo de uma grade de horários: horários ocupados aparecem riscados">
    <span>09:00</span><span class="x">09:30</span><span class="x">10:00</span><span>10:30</span>
    <span>11:00</span><span class="x">11:30</span><span>14:00</span><span>14:30</span>
  </div>
  <p class="small muted">Exemplo ilustrativo de como o cliente vê os horários livres.</p>
</section>

<section class="grid g3" style="margin:24px 0">
  <div class="card"><h3>Agenda protegida</h3><p class="muted">Cada profissional tem seu expediente, pausas e folgas. Dois clientes não conseguem pegar o mesmo horário, mesmo clicando juntos.</p></div>
  <div class="card"><h3>Página pública</h3><p class="muted">Um link seu para colocar no Instagram e no WhatsApp. O cliente escolhe serviço, profissional e horário, e pode cancelar ou remarcar por um link secreto.</p></div>
  <div class="card"><h3>Caixa em ordem</h3><p class="muted">Concluir um atendimento não lança dinheiro sozinho: você registra o que realmente recebeu, com estorno e comissão por profissional.</p></div>
</section>

<section id="planos" style="margin:32px 0">
  <h2>Planos</h2>
  <p class="muted">Todo plano tem período de teste. Você pode trocar de plano depois.</p>
  <div class="grid g3">
  <?php foreach ($plans as $p): $hl = decode_list($p['highlights']); ?>
    <div class="card plan">
      <h3><?= e($p['name']) ?></h3>
      <div class="price"><?= e(money((int)$p['price_cents'])) ?><span class="muted small" style="font-weight:400"> /mês</span></div>
      <p class="muted"><?= e($p['description']) ?></p>
      <ul><?php foreach ($hl as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
      <a class="btn btn-primary w-100" href="<?= e(url('/cadastro?plano=' . rawurlencode($p['code']))) ?>">Começar<?= (int)$p['trial_days'] > 0 ? ' com ' . (int)$p['trial_days'] . ' dias de teste' : '' ?></a>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if (!$plans): ?><p class="empty">Nenhum plano disponível no momento.</p><?php endif; ?>
</section>
