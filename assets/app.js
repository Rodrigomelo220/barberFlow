// Confirmação em formulários com data-confirm.
document.addEventListener('submit', function (e) {
  var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (m && !window.confirm(m)) e.preventDefault();
});

// Tabelas viram cartões no celular: cada célula recebe o rótulo da coluna (data-label).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('table.table').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    if (!heads.length) return;
    t.classList.add('table-stack');
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) { if (heads[i]) td.setAttribute('data-label', heads[i]); });
    });
  });
  // "Mais" do menu inferior
  var btn = document.getElementById('moreBtn'), sheet = document.getElementById('moreSheet'), bg = document.getElementById('moreBg');
  if (btn && sheet && bg) {
    var set = function (open) { sheet.hidden = !open; bg.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); var bb = document.getElementById('burgerBtn'); if (bb) { bb.setAttribute('aria-expanded', open ? 'true' : 'false'); bb.firstElementChild.className = 'bi ' + (open ? 'bi-x-lg' : 'bi-list'); } document.body.classList.toggle('sheet-open', open); };
    btn.addEventListener('click', function () { set(sheet.hidden); });
    var burger = document.getElementById('burgerBtn'); if (burger) burger.addEventListener('click', function () { set(sheet.hidden); });
    bg.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  }
});
