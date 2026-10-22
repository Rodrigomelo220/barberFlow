// Seletor de horários: lê campos marcados com data-slot-src e consulta o JSON do servidor.
(function () {
  document.querySelectorAll('[data-slotpicker]').forEach(function (box) {
    var form = box.closest('form') || document;
    var url = box.getAttribute('data-url');
    var statics = {};
    try { statics = JSON.parse(box.getAttribute('data-params') || '{}'); } catch (e) {}
    var out = box.querySelector('.slots');
    var msg = box.querySelector('.slot-msg');
    var input = box.querySelector('input[name=ts]');
    var seq = 0;
    function collect() {
      var p = Object.assign({}, statics), groups = {};
      form.querySelectorAll('[data-slot-src]').forEach(function (el) {
        var n = el.getAttribute('data-slot-src');
        if (el.type === 'checkbox') { groups[n] = groups[n] || []; if (el.checked) groups[n].push(el.value); }
        else p[n] = el.value;
      });
      Object.keys(groups).forEach(function (n) { p[n] = groups[n].join(','); });
      return p;
    }
    function load() {
      var p = collect(), mine = ++seq;
      input.value = ''; out.innerHTML = '';
      if (p.services === '' ) { msg.textContent = 'Escolha ao menos um serviço.'; return; }
      if (!p.date) { msg.textContent = 'Escolha a data.'; return; }
      msg.textContent = 'Buscando horários…';
      var qs = Object.keys(p).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]); }).join('&');
      fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (mine !== seq) return;
        if (!j.slots || !j.slots.length) { msg.textContent = j.message || 'Sem horários livres neste dia. Tente outra data.'; return; }
        msg.textContent = '';
        j.slots.forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'slot'; b.textContent = s.label; b.setAttribute('aria-pressed', 'false');
          b.addEventListener('click', function () {
            out.querySelectorAll('.slot').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
            b.setAttribute('aria-pressed', 'true'); input.value = s.ts;
            box.dispatchEvent(new CustomEvent('slotpicked', { bubbles: true }));
          });
          out.appendChild(b);
        });
      }).catch(function () { if (mine === seq) msg.textContent = 'Não foi possível buscar os horários. Tente de novo.'; });
    }
    form.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-slot-src]')) load(); });
    load();
  });
})();
