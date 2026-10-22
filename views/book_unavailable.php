<?php require BF_ROOT . '/views/_shop_hero.php'; ?>
<div class="card empty-state"><i class="bi bi-calendar-x"></i><h2>Agendamento online indisponível</h2><p class="muted">Esta barbearia não está recebendo agendamentos pela internet no momento.</p>
<?php if ($shop['phone']): ?><p>Fale diretamente: <b><?= e(Scheduling::formatPhone($shop['phone'])) ?></b></p><?php endif; ?></div>
