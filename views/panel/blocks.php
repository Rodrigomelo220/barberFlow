<div class="page-head"><h1>Bloqueios</h1></div>
<p class="muted">Almoço, folga, férias: nesses períodos ninguém consegue agendar. Agendamentos que já existem não são alterados.</p>
<form method="post" action="<?= e(url('/painel/bloqueios')) ?>" class="card"><?= csrf_field() ?><h2>Novo bloqueio</h2>
  <div class="grid g2">
    <?= fld('prof', 'Quem', '', ['type' => 'select', 'options' => ['' => 'Toda a barbearia'] + array_column($pros, 'name', 'id')]) ?>
    <?= fld('kind', 'Tipo', 'other', ['type' => 'select', 'options' => ['lunch' => 'Almoço', 'time_off' => 'Folga', 'vacation' => 'Férias', 'other' => 'Outro']]) ?>
    <?= fld('from_date', 'Data inicial', '', ['type' => 'date', 'required' => 1]) ?>
    <?= fld('from_time', 'Hora inicial', '', ['type' => 'time', 'hint' => 'Vazio = começo do dia']) ?>
    <?= fld('to_date', 'Data final', '', ['type' => 'date', 'hint' => 'Vazio = mesmo dia']) ?>
    <?= fld('to_time', 'Hora final', '', ['type' => 'time', 'hint' => 'Vazio = fim do dia']) ?>
  </div>
  <?= fld('reason', 'Motivo (opcional)', '', ['maxlength' => 200]) ?>
  <button class="btn btn-primary">Criar bloqueio</button>
</form>
<div class="card"><h2>Próximos bloqueios</h2>
  <?php if (!$rows): ?><p class="empty">Nenhum bloqueio futuro.</p><?php endif; ?>
  <?php foreach ($rows as $b): ?>
    <div class="between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span><b><?= e(dt_local($b['starts_at'], $ctx['tz'], 'd/m H:i')) ?> → <?= e(dt_local($b['ends_at'], $ctx['tz'], 'd/m H:i')) ?></b><br>
      <span class="muted small"><?= $b['professional'] ? e($b['professional']) : 'Toda a barbearia' ?><?= $b['reason'] ? ' · ' . e($b['reason']) : '' ?></span></span>
      <?= post_form('/painel/bloqueios/' . $b['id'] . '/excluir', '<button class="btn btn-sm btn-outline-danger">Remover</button>', '', 'Remover este bloqueio?') ?></div>
  <?php endforeach; ?>
</div>
