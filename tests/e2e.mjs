// E2E no navegador (Chromium + Playwright). Uso: BASE=http://127.0.0.1:8081 node tests/e2e.mjs
import { createRequire } from 'node:module';
const require = createRequire(process.env.PW_REQUIRE || '/home/claude/barbersaas/package.json');
const { chromium } = require('playwright-core');
import fs from 'node:fs';
const BASE = process.env.BASE || 'http://127.0.0.1:8081';
const OUT = process.env.OUT || '/tmp/e2e-shots'; fs.mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
const problems = []; let step = 0;
async function newCtx(name) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error') problems.push(`[${name}] console: ${m.text()}`); });
  page.on('pageerror', e => problems.push(`[${name}] pageerror: ${e.message}`));
  page.on('response', r => { if (r.status() >= 500) problems.push(`[${name}] HTTP ${r.status()} ${r.url()}`); });
  return page;
}
const shot = async (p, n) => p.screenshot({ path: `${OUT}/${String(++step).padStart(2, '0')}-${n}.png`, fullPage: true });
const ok = (c, m) => { if (!c) { problems.push('FALHOU: ' + m); console.log('  FALHOU', m); } else console.log('  ok', m); };
const slug = 'e2e-' + Date.now().toString(36);

const owner = await newCtx('dono');
await owner.goto(BASE + '/'); await shot(owner, 'landing');
ok(await owner.locator('text=Profissional').first().isVisible(), 'landing mostra planos');
await owner.click('text=Criar conta e testar');
await owner.fill('#f_name', 'Zé da Navalha'); await owner.fill('#f_email', slug + '@teste.com'); await owner.fill('#f_password', 'SenhaBoa12345');
await owner.click('button:has-text("Criar conta")');
await owner.waitForURL('**/nova-barbearia**');
await owner.fill('#f_name', 'Barbearia do Zé'); await owner.fill('#f_slug', slug); await owner.fill('#f_phone', '(11) 98888-7777');
await owner.check('input[value="profissional"]');
await shot(owner, 'nova-barbearia');
await owner.click('button:has-text("Criar barbearia")');
await owner.waitForURL('**/painel/assinatura');
ok(await owner.locator('text=Aguardando pagamento').first().isVisible(), 'assinatura começa aguardando pagamento');
await shot(owner, 'assinatura');
await owner.click('button:has-text("Testar 7 dias")');
await owner.waitForURL('**/painel');
await shot(owner, 'painel-inicio');
ok(await owner.locator('h1:has-text("Início")').isVisible(), 'painel liberado após iniciar teste');

await owner.goto(BASE + '/painel/servicos');
await owner.fill('form[action$="/painel/servicos"] input[name=name]', 'Corte degradê');
await owner.fill('form[action$="/painel/servicos"] input[name=price]', '45,00');
await owner.fill('form[action$="/painel/servicos"] input[name=duration]', '30');
await owner.click('form[action$="/painel/servicos"] button:has-text("Criar serviço")');
ok(await owner.locator('text=Corte degradê').first().isVisible(), 'serviço criado');
await shot(owner, 'servicos');
await owner.goto(BASE + '/painel/equipe');
await owner.click('a:has-text("Zé da Navalha")');
await shot(owner, 'profissional');
await owner.locator('form[action$="/servicos"] button').click();

// cliente na página pública
const cust = await newCtx('cliente');
await cust.goto(`${BASE}/b/${slug}`); await shot(cust, 'publica-1');
await cust.locator('.choice:has-text("Corte degradê")').click();
await cust.click('#to2');
const d = new Date(Date.now() + 3 * 864e5); while ([0].includes(d.getDay())) d.setDate(d.getDate() + 1);
await cust.fill('#date', d.toISOString().slice(0, 10)); await cust.dispatchEvent('#date', 'change');
await cust.waitForSelector('#slots .slot');
await shot(cust, 'publica-2');
const nSlots = await cust.locator('#slots .slot').count(); ok(nSlots === 35, `horários exibidos sem duplicar (${nSlots})`);
await cust.locator('#slots .slot').nth(4).click();
await cust.click('#to3');
await cust.fill('#name', 'Carlos Cliente'); await cust.fill('#phone', '(11) 97777-1234');
await cust.click('#send');
await cust.waitForSelector('#err:not(.hide)');
ok((await cust.textContent('#err')).includes('aceite'), 'exige consentimento para agendar');
await cust.check('#consent_terms'); await cust.check('#consent_wa');
await cust.click('#send');
await cust.waitForSelector('#s4:not(.hide)');
await shot(cust, 'publica-confirmado');
const manage = await cust.getAttribute('#done-link', 'href');
ok(!!manage, 'link de gerenciamento exibido');

// dono vê na agenda e cobra
await owner.goto(`${BASE}/painel/agenda?data=${d.toISOString().slice(0, 10)}`);
ok(await owner.locator('text=Carlos Cliente').first().isVisible(), 'agendamento aparece na agenda do dono');
await shot(owner, 'agenda');
await owner.click('a.agenda-row:has-text("Carlos Cliente")');
await owner.click('button:has-text("Concluir atendimento")');
ok(await owner.locator('text=O pagamento ainda não foi lançado').isVisible(), 'concluir avisa que não lançou pagamento');
await owner.selectOption('select[name=method]', 'pix');
await owner.click('button:has-text("Lançar pagamento")');
ok(await owner.locator('text=Totalmente pago').isVisible(), 'pagamento quitado');
await shot(owner, 'atendimento');
await owner.goto(BASE + '/painel/financeiro'); await shot(owner, 'financeiro');
ok(await owner.locator('.stat:has-text("recebido")').first().isVisible(), 'financeiro abre');

// novo agendamento manual pelo painel (slot picker)
await owner.goto(BASE + '/painel/agenda/novo');
await owner.check('input[name="services[]"]');
await owner.fill('#date', d.toISOString().slice(0, 10)); await owner.dispatchEvent('#date', 'change');
await owner.waitForSelector('.slots .slot');
await owner.locator('.slots .slot').nth(10).click();
await owner.fill('#f_name', 'Maria Manual'); await owner.fill('#f_phone', '11966665555');
await shot(owner, 'agenda-novo');
await owner.click('button:has-text("Agendar")');
ok(await owner.locator('h1:has-text("Maria Manual")').isVisible(), 'agendamento manual criado');

// cliente cancela pelo link
await cust.goto(BASE + new URL(manage, BASE).pathname);
await cust.locator('button:has-text("Cancelar agendamento")').click().catch(() => {});
await cust.screenshot({ path: `${OUT}/${String(++step).padStart(2, '0')}-gerenciar.png`, fullPage: true });

// telas restantes (smoke visual)
for (const p of ['clientes', 'servicos', 'equipe', 'bloqueios', 'mensagens', 'configuracoes', 'assinatura']) {
  const r = await owner.goto(`${BASE}/painel/${p}`); ok(r.status() === 200, `/painel/${p} abre`); await shot(owner, p);
}
const overflow = await owner.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
ok(!overflow, 'sem rolagem horizontal no celular (assinatura)');

// admin
const adm = await newCtx('admin');
await adm.goto(BASE + '/entrar');
await adm.fill('#f_email', 'admin@example.com'); await adm.fill('#f_password', 'SenhaForte12345');
await adm.click('button:has-text("Entrar")');
await adm.waitForURL('**/admin');
await shot(adm, 'admin');
await adm.goto(BASE + '/admin/barbearias'); await shot(adm, 'admin-barbearias');
await adm.goto(BASE + '/admin/planos'); await shot(adm, 'admin-planos');
await browser.close();
console.log(problems.length ? '\nPROBLEMAS:\n' + problems.join('\n') : '\nE2E sem problemas');
process.exit(problems.length ? 1 : 0);
