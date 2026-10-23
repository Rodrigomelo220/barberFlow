(function () {
  var C = JSON.parse(document.getElementById('cfg').textContent);
  var $ = function (id) { return document.getElementById(id); };
  var money = function (c) { return 'R$ ' + (c / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
  var steps = document.querySelectorAll('.steps li'); var lastBook = null;
  var state = { services: [], pro: 'any', date: '', ts: '' };
  function show(n) {
    ['s1', 's2', 's3', 's4'].forEach(function (id, i) { $(id).classList.toggle('hide', i !== n - 1); });
    steps.forEach(function (li, i) { li.classList.toggle('on', i < n); li.classList.toggle('cur', i === n - 1); });
    window.scrollTo(0, 0);
  }
  function el(tag, props, kids) { var e = document.createElement(tag); Object.assign(e, props || {}); (kids || []).forEach(function (k) { e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); }); return e; }

  // 1. serviços
  var list = $('svc-list');
  C.services.forEach(function (s) {
    var cb = el('input', { type: 'checkbox', value: s.id });
    cb.addEventListener('change', function () {
      state.services = Array.prototype.map.call(list.querySelectorAll('input:checked'), function (x) { return +x.value; });
      var sel = C.services.filter(function (x) { return state.services.indexOf(+x.id) >= 0; });
      var dur = sel.reduce(function (a, x) { return a + (+x.duration_min); }, 0), price = sel.reduce(function (a, x) { return a + (+x.price_cents); }, 0);
      $('svc-sum').textContent = sel.length ? sel.length + (sel.length > 1 ? ' serviços' : ' serviço') + ' · ' + dur + ' min · ' + money(price) : 'Selecione um ou mais serviços.';
      $('svc-sumbar').classList.toggle('on', !!sel.length);
      $('to2').disabled = !sel.length;
    });
    var t = el('span', { className: 't' }, [el('b', { textContent: s.name }), el('br'), el('span', { className: 'muted small', textContent: s.duration_min + ' min' + (s.description ? ' · ' + s.description : '') })]);
    list.appendChild(el('label', { className: 'choice' }, [cb, t, el('span', { className: 'p', textContent: money(+s.price_cents) })]));
  });

  // 2. profissional + dia + horário
  function eligiblePros() {
    return C.pros.filter(function (p) { var m = C.map[p.id] || []; return state.services.every(function (s) { return m.indexOf(s) >= 0; }); });
  }
  function fillPros() {
    var sel = $('pro'), pros = eligiblePros(); sel.innerHTML = '';
    if (C.any && pros.length > 1) sel.appendChild(el('option', { value: 'any', textContent: 'Qualquer profissional' }));
    pros.forEach(function (p) { sel.appendChild(el('option', { value: p.id, textContent: p.name })); });
    if (!pros.length) sel.appendChild(el('option', { value: '', textContent: 'Nenhum profissional faz todos esses serviços' }));
    state.pro = sel.value; sel.parentNode.style.display = pros.length === 1 ? 'none' : '';
  }
  var dateEl = $('date'); dateEl.min = C.today;
  (function () { var d = new Date(C.today + 'T12:00:00'); d.setDate(d.getDate() + C.maxDays); dateEl.max = d.toISOString().slice(0, 10); })();
  var WD = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  var chips = $('chips');
  (function () {
    var n = Math.min(C.maxDays + 1, 14);
    for (var i = 0; i < n; i++) {
      var d = new Date(C.today + 'T12:00:00'); d.setDate(d.getDate() + i);
      var iso = d.toISOString().slice(0, 10);
      var b = el('button', { type: 'button', className: 'chip' }, [el('small', { textContent: i === 0 ? 'hoje' : i === 1 ? 'amanhã' : WD[d.getDay()] }), el('b', { textContent: String(d.getDate()) })]);
      b.dataset.date = iso; b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', function () { dateEl.value = this.dataset.date; markChip(); loadSlots(); });
      chips.appendChild(b);
    }
  })();
  function markChip() { chips.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.date === dateEl.value ? 'true' : 'false'); }); }
  var seq = 0;
  function loadSlots() {
    var mine = ++seq;
    state.ts = ''; $('to3').disabled = true; $('slots').innerHTML = '';
    if (!state.pro || !dateEl.value) { $('slotmsg').textContent = dateEl.value ? '' : 'Escolha o dia.'; return; }
    $('slotmsg').textContent = 'Buscando horários…';
    var qs = 'shop=' + encodeURIComponent(C.shop) + '&services=' + state.services.join(',') + '&pro=' + encodeURIComponent(state.pro) + '&date=' + dateEl.value;
    fetch(C.slotsUrl + '?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (mine !== seq) return;
      if (!j.slots || !j.slots.length) { $('slotmsg').textContent = j.message || 'Sem horários livres neste dia. Tente outra data.'; return; }
      $('slotmsg').textContent = '';
      j.slots.forEach(function (s) {
        var b = el('button', { type: 'button', className: 'slot', textContent: s.label }); b.setAttribute('aria-pressed', 'false');
        b.addEventListener('click', function () {
          $('slots').querySelectorAll('.slot').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
          b.setAttribute('aria-pressed', 'true'); state.ts = s.ts; $('to3').disabled = false;
        });
        $('slots').appendChild(b);
      });
    }).catch(function () { if (mine === seq) $('slotmsg').textContent = 'Não foi possível buscar os horários. Tente de novo.'; });
  }
  $('to2').addEventListener('click', function () { fillPros(); if (!dateEl.value) dateEl.value = C.today; markChip(); show(2); loadSlots(); });
  $('pro').addEventListener('change', function () { state.pro = this.value; loadSlots(); });
  dateEl.addEventListener('change', function () { markChip(); loadSlots(); });
  $('back1').addEventListener('click', function () { show(1); });
  $('to3').addEventListener('click', function () { show(3); });
  $('back2').addEventListener('click', function () { show(2); loadSlots(); });

  // 3. confirmar
  $('send').addEventListener('click', function () {
    var btn = this, err = $('err'); err.classList.add('hide');
    btn.disabled = true; btn.textContent = 'Confirmando…';
    fetch(C.bookUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf },
      body: JSON.stringify({ shop: C.shop, services: state.services, pro: state.pro, ts: +state.ts, name: $('name').value, phone: $('phone').value, notes: $('notes').value,
        consent_terms: $('consent_terms').checked, whatsapp_consent: $('consent_wa').checked }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        btn.disabled = false; btn.textContent = 'Confirmar agendamento';
        if (!x.ok) {
          err.textContent = x.j.message || 'Não foi possível agendar.'; err.classList.remove('hide');
          if (x.j.error === 'slot_unavailable') { show(2); loadSlots(); }
          return;
        }
        $('done-when').textContent = x.j.when; $('done-detail').textContent = 'Total: ' + x.j.total;
        lastBook = x.j; var a = $('done-link'); a.href = x.j.manage_path; a.textContent = x.j.manage_url; show(4);
      })
      .catch(function () { btn.disabled = false; btn.textContent = 'Confirmar agendamento'; err.textContent = 'Sem conexão. Tente de novo.'; err.classList.remove('hide'); });
  });

  // .ics (gerado no navegador, sem enviar nada a ninguém)
  $('ics').addEventListener('click', function () {
    if (!lastBook) return;
    var f = function (t) { return new Date(t * 1000).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''); };
    var esc = function (v) { return String(v || '').replace(/([,;\\])/g, '\\$1').replace(/\n/g, '\\n'); };
    var ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//BarberFlow//PT', 'BEGIN:VEVENT', 'UID:' + lastBook.starts_ts + '@barberflow', 'DTSTAMP:' + f(Math.floor(Date.now() / 1000)),
      'DTSTART:' + f(lastBook.starts_ts), 'DTEND:' + f(lastBook.ends_ts), 'SUMMARY:' + esc('Horário na ' + lastBook.shop_name), 'LOCATION:' + esc(lastBook.address), 'DESCRIPTION:' + esc('Gerenciar: ' + lastBook.manage_url), 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' })); a.download = 'agendamento.ics'; document.body.appendChild(a); a.click(); a.remove();
  });
})();
