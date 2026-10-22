<?php $cfgJson = json_encode(['shop' => $shop['slug'], 'tz' => $shop['timezone'], 'services' => $services, 'pros' => $pros, 'map' => $map, 'any' => (bool)(int)$shop['allow_any_professional'],
    'maxDays' => (int)$shop['max_days_ahead'], 'today' => now_utc()->setTimezone(new DateTimeZone($shop['timezone']))->format('Y-m-d'), 'csrf' => csrf_token(),
    'slotsUrl' => url('/api/slots'), 'bookUrl' => url('/api/book')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
<div class="shop-head">
  <h1><?= e($shop['name']) ?></h1>
  <?php if ($shop['description']): ?><p class="muted"><?= e($shop['description']) ?></p><?php endif; ?>
  <?php if ($shop['address']): ?><p class="small muted"><?= e($shop['address']) ?></p><?php endif; ?>
</div>
<?php if (!$services): ?>
  <div class="card empty">Esta barbearia ainda não publicou serviços para agendamento online.</div>
<?php else: ?>
<ol class="steps" aria-hidden="true"><li class="on"></li><li></li><li></li><li></li></ol>
<div id="app" aria-live="polite">
  <section id="s1" class="card">
    <h2>1. Escolha o serviço</h2>
    <div id="svc-list"></div>
    <p class="muted small" id="svc-sum"></p>
    <button type="button" class="btn btn-primary w-100" id="to2" disabled>Continuar</button>
  </section>
  <section id="s2" class="card hide">
    <h2>2. Profissional, dia e horário</h2>
    <div class="field"><label for="pro">Profissional</label><select class="form-select" id="pro"></select></div>
    <div class="field"><label for="date">Dia</label><input class="form-control" type="date" id="date"></div>
    <div id="slotbox"><p class="muted" id="slotmsg"></p><div class="slots" id="slots"></div></div>
    <div class="actions"><button type="button" class="btn btn-outline-dark" id="back1">Voltar</button><button type="button" class="btn btn-primary" id="to3" disabled>Continuar</button></div>
  </section>
  <section id="s3" class="card hide">
    <h2>3. Seus dados</h2>
    <div class="field"><label for="name">Nome</label><input class="form-control" id="name" autocomplete="name" maxlength="120"></div>
    <div class="field"><label for="phone">WhatsApp / telefone</label><input class="form-control" id="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(11) 91234-5678"></div>
    <div class="field"><label for="notes">Observação (opcional)</label><textarea class="form-control" id="notes" rows="2" maxlength="500"></textarea></div>
    <div class="field check"><label><input type="checkbox" id="consent_terms"> Autorizo a barbearia a usar meu nome e telefone para este agendamento. <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener">Privacidade</a></label></div>
    <div class="field check"><label><input type="checkbox" id="consent_wa"> Quero receber confirmação e lembretes por WhatsApp (opcional)</label></div>
    <div id="err" class="alert alert-danger hide" role="alert"></div>
    <div class="actions"><button type="button" class="btn btn-outline-dark" id="back2">Voltar</button><button type="button" class="btn btn-danger" id="send">Confirmar agendamento</button></div>
  </section>
  <section id="s4" class="card hide">
    <h2>Agendamento confirmado</h2>
    <p id="done-when" style="font-size:1.1rem;font-weight:700"></p>
    <p class="muted" id="done-detail"></p>
    <p>Guarde este link para cancelar ou remarcar:</p>
    <p><a id="done-link" href="#"></a></p>
  </section>
</div>
<script type="application/json" id="cfg"><?= $cfgJson ?></script>
<script src="<?= e(url('/assets/book.js')) ?>"></script>
<?php endif; ?>
