<?php $cfgJson = json_encode(['shop' => $shop['slug'], 'tz' => $shop['timezone'], 'services' => $services, 'pros' => $pros, 'map' => $map, 'any' => (bool)(int)$shop['allow_any_professional'],
    'maxDays' => (int)$shop['max_days_ahead'], 'today' => now_utc()->setTimezone(new DateTimeZone($shop['timezone']))->format('Y-m-d'), 'csrf' => csrf_token(),
    'slotsUrl' => url('/api/slots'), 'bookUrl' => url('/api/book')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
<?php require BF_ROOT . '/views/_shop_hero.php'; ?>
<?php if (!$services): ?>
  <div class="card empty">Esta barbearia ainda não publicou serviços para agendamento online.</div>
<?php else: ?>
<ol class="steps" aria-label="Etapas do agendamento"><li class="on"><b>1</b><span>Serviço</span></li><li><b>2</b><span>Horário</span></li><li><b>3</b><span>Seus dados</span></li><li><b>4</b><span>Pronto</span></li></ol>
<div id="app" aria-live="polite">
  <section id="s1" class="card">
    <h2>Escolha o serviço</h2>
    <div id="svc-list"></div>
    <div class="sumbar" id="svc-sumbar"><p class="muted small mb-0" id="svc-sum">Selecione um ou mais serviços.</p></div>
    <button type="button" class="btn btn-primary w-100" id="to2" disabled>Continuar</button>
  </section>
  <section id="s2" class="card hide">
    <h2>Profissional, dia e horário</h2>
    <div class="field"><label for="pro">Profissional</label><select class="form-select" id="pro"></select></div>
    <div class="field"><label>Dia</label><div class="daychips" id="chips" role="group" aria-label="Escolha o dia"></div><label for="date" class="small muted mt-2">Outra data</label><input class="form-control" type="date" id="date"></div>
    <div id="slotbox"><p class="muted" id="slotmsg"></p><div class="slots" id="slots"></div></div>
    <div class="actions"><button type="button" class="btn btn-outline-dark" id="back1">Voltar</button><button type="button" class="btn btn-primary" id="to3" disabled>Continuar</button></div>
  </section>
  <section id="s3" class="card hide">
    <h2>Seus dados</h2>
    <div class="field"><label for="name">Nome</label><input class="form-control" id="name" autocomplete="name" maxlength="120"></div>
    <div class="field"><label for="phone">WhatsApp / telefone</label><input class="form-control" id="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(11) 91234-5678"></div>
    <div class="field"><label for="notes">Observação (opcional)</label><textarea class="form-control" id="notes" rows="2" maxlength="500"></textarea></div>
    <div class="field check"><label><input type="checkbox" id="consent_terms"> Autorizo a barbearia a usar meu nome e telefone para este agendamento. <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener">Privacidade</a></label></div>
    <div class="field check"><label><input type="checkbox" id="consent_wa"> Quero receber confirmação e lembretes por WhatsApp (opcional)</label></div>
    <div id="err" class="alert alert-danger hide" role="alert"></div>
    <div class="actions"><button type="button" class="btn btn-outline-dark" id="back2">Voltar</button><button type="button" class="btn btn-danger" id="send">Confirmar agendamento</button></div>
  </section>
  <section id="s4" class="card hide">
    <div class="done-ico"><i class="bi bi-check-lg"></i></div>
    <h2 class="text-center">Agendamento confirmado</h2>
    <p id="done-when" class="done-when"></p>
    <p class="muted text-center" id="done-detail"></p>
    <div class="actions justify-content-center"><button type="button" class="btn btn-outline-dark" id="ics"><i class="bi bi-calendar-plus"></i> Adicionar ao calendário</button></div>
    <p class="small muted text-center mt-3">Guarde este link para cancelar ou remarcar:</p>
    <p class="text-center small" style="word-break:break-all"><a id="done-link" href="#"></a></p>
  </section>
</div>
<script type="application/json" id="cfg"><?= $cfgJson ?></script>
<script src="<?= e(url('/assets/book.js')) ?>"></script>
<?php endif; ?>
