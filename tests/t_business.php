<?php
require __DIR__ . '/lib.php';
echo "== Financeiro, LGPD, mensagens, autenticação e isolamento\n";
reset_db();
set_now('2026-11-02 10:00:00');
$o = mk_user('dono@x.com'); $sid = mk_shop($o, 'fin'); $pro = pro_of($sid);
$sv = mk_service($sid, 'Corte', 5000, 30);
$tz = SP;

// ------------------------------------------------------------------ financeiro
$ap = book($sid, [$sv], $pro, '2026-11-03 10:00', 'Joana Silva', '11911112222', ['whatsapp_consent' => true]);
$A = $ap['appointment_id'];
t('concluir não lança pagamento; "a receber" aparece', function () use ($sid, $A, $tz) {
    Scheduling::setOutcome($sid, $A, 'completed', 1);
    $p = Finance::period($tz, '2026-11-01', '2026-11-30');
    $s = Finance::summary($sid, $p, $tz);
    eq($s['realized'], 5000); eq($s['received'], 0); eq($s['to_receive'], 5000, 'concluído e não pago = a receber');
});
t('pagamento parcial, pendente conta como comprometido, excesso é recusado', function () use ($sid, $A) {
    $r = Finance::recordPayment($sid, 1, null, $A, 'pix', 'received', 2000);
    eq($r['remaining_cents'], 3000);
    $r2 = Finance::recordPayment($sid, 1, null, $A, 'cash', 'pending', 1000);
    eq($r2['remaining_cents'], 2000);
    throws(fn() => Finance::recordPayment($sid, 1, null, $A, 'pix', 'received', 2500), 'payment_exceeds');
    throws(fn() => Finance::recordPayment($sid, 1, null, $A, 'bitcoin', 'received', 100));
    throws(fn() => Finance::recordPayment($sid, 1, null, $A, 'pix', 'received', 0), 'invalid_amount');
    throws(fn() => Finance::recordPayment($sid, 1, null, $A, 'pix', 'received', -5), 'invalid_amount');
});
t('resumo separa previsto, recebido, pendente e a receber', function () use ($sid, $tz) {
    $s = Finance::summary($sid, Finance::period($tz, '2026-11-01', '2026-11-30'), $tz);
    eq($s['received'], 2000, 'só o recebido'); eq($s['pending'], 1000); eq($s['to_receive'], 2000, '5000 − 2000 recebido − 1000 pendente');
});
t('marcar pendente como recebido; quitar; pagar de novo é recusado', function () use ($sid, $A) {
    $pid = (int)DB::val("SELECT id FROM shop_payments WHERE appointment_id = ? AND status = 'pending'", [$A]);
    Finance::markReceived($sid, 1, null, $pid);
    throws(fn() => Finance::markReceived($sid, 1, null, $pid), 'not_pending');
    Finance::recordPayment($sid, 1, null, $A, 'debit', 'received');           // sem valor = restante (2000)
    eq(Finance::paidSoFar($A), 5000);
    throws(fn() => Finance::recordPayment($sid, 1, null, $A, 'pix', 'received'), 'already_paid');
});
t('estorno parcial e total respeitam o saldo; comissão sai líquida de estorno', function () use ($sid, $A, $pro, $tz, $sv) {
    $first = (int)DB::val("SELECT id FROM shop_payments WHERE appointment_id = ? AND kind='payment' AND method='pix'", [$A]);   // 2000
    Finance::refund($sid, 1, $first, 500);
    throws(fn() => Finance::refund($sid, 1, $first, 1600), 'refund_exceeds');
    Finance::refund($sid, 1, $first);                                             // resto (1500)
    throws(fn() => Finance::refund($sid, 1, $first, 1), 'refund_exceeds');
    eq(Finance::paidSoFar($A), 3000);
    DB::exec('UPDATE professionals SET commission_percent = 40 WHERE id = ?', [$pro]);   // percentual atual NÃO deve afetar o passado
    $c = Finance::commissions($sid, Finance::period($tz, '2026-11-01', '2026-11-30'));
    $row = array_values(array_filter($c, fn($x) => (int)$x['professional_id'] === $pro))[0];
    eq((int)$row['net_cents'], 3000); eq((int)$row['commission_cents'], 0, 'pagamentos foram feitos com 0% — snapshot; mudar % depois não reescreve história');
    $b = book($sid, [$sv], $pro, '2026-11-03 11:00', 'Outro Cliente', '11922223333');
    Finance::recordPayment($sid, 1, null, $b['appointment_id'], 'pix', 'received');
    $c = Finance::commissions($sid, Finance::period($tz, '2026-11-01', '2026-11-30'));
    $row = array_values(array_filter($c, fn($x) => (int)$x['professional_id'] === $pro))[0];
    eq((int)$row['commission_cents'], 2000, '40% de R$ 50,00 novos; antigos seguem em 0%');
});
t('pagamento em atendimento cancelado é recusado; recebimento por outro tenant é "não encontrado"', function () use ($sid, $pro, $o) {
    $b = book($sid, [$sv = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sid])], $pro, '2026-11-03 12:00', 'Cancelado', '11933334444');
    Scheduling::cancel($sid, $b['appointment_id'], 'staff', 1);
    throws(fn() => Finance::recordPayment($sid, 1, null, $b['appointment_id'], 'pix', 'received'), 'canceled');
    $s2 = mk_shop(mk_user('outro@x.com'), 'outra'); 
    throws(fn() => Finance::recordPayment($s2, 1, null, $b['appointment_id'], 'pix', 'received'), 'not_found');
});
t('barbeiro só lança pagamento dos próprios atendimentos', function () use ($sid, $pro) {
    $u = mk_user('barbeiro@x.com'); DB::insert('shop_members', ['shop_id' => $sid, 'user_id' => $u, 'role' => 'barber']);
    $p2 = Shops::createProfessional($sid, $u, ['name' => 'Barbeiro', 'active' => true]); DB::exec('UPDATE professionals SET user_id = ? WHERE id = ?', [$u, $p2]);
    $ctx = Ctx::load(Auth::user() ?: ['id' => $u], $sid) ?? Ctx::load(['id' => $u, 'name' => 'x', 'email' => 'x', 'is_admin' => 0], $sid);
    eq($ctx['role'], 'barber');
    eq(Ctx::ownProfessionalIds($ctx), [$p2]);
    $mine = (int)DB::val("SELECT id FROM appointments WHERE shop_id = ? AND professional_id = ? LIMIT 1", [$sid, $pro]);
    throws(fn() => Finance::recordPayment($sid, $u, Ctx::ownProfessionalIds($ctx), $mine, 'pix', 'received', 100), 'forbidden');
    ok(!Perm::can('barber', 'finance.view') && !Perm::can('barber', 'settings.manage') && !Perm::can('barber', 'billing.manage'));
    ok(Perm::can('manager', 'finance.manage') && !Perm::can('manager', 'billing.manage') && Perm::can('owner', 'billing.manage'));
});
t('descartar só vale para pendente; recebido exige estorno', function () use ($sid, $pro) {
    $b = book($sid, [(int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sid])], $pro, '2026-11-03 14:00', 'Desc', '11944445555');
    $r = Finance::recordPayment($sid, 1, null, $b['appointment_id'], 'pix', 'pending', 1000);
    Finance::discardPending($sid, 1, $r['id']);
    $r2 = Finance::recordPayment($sid, 1, null, $b['appointment_id'], 'pix', 'received', 1000);
    throws(fn() => Finance::discardPending($sid, 1, $r2['id']), 'not_pending');
});
t('despesas: validação e resultado = recebido − despesas', function () use ($sid, $tz) {
    throws(fn() => Finance::addExpense($sid, 1, '', 'aluguel', 100, null, '2026-11-05'));
    throws(fn() => Finance::addExpense($sid, 1, 'Aluguel', 'aluguel', 0, null, '2026-11-05'));
    throws(fn() => Finance::addExpense($sid, 1, 'Aluguel', 'aluguel', 100, null, '2026-02-31'));
    Finance::addExpense($sid, 1, 'Aluguel', 'aluguel', 100000, null, '2026-11-05');
    $s = Finance::summary($sid, Finance::period($tz, '2026-11-01', '2026-11-30'), $tz);
    eq($s['expenses'], 100000); eq($s['net'], $s['received'] - 100000);
});
t('CSV: injeção de fórmula neutralizada, ponto-e-vírgula/aspas escapados, decimal com vírgula', function () {
    eq(Finance::csvCell('=SOMA(A1)'), "'=SOMA(A1)"); eq(Finance::csvCell('+55'), "'+55"); eq(Finance::csvCell('-1+2'), "'-1+2"); eq(Finance::csvCell('@cmd'), "'@cmd");
    eq(Finance::csvCell('a;b'), '"a;b"'); eq(Finance::csvCell('diz "oi"'), '"diz ""oi"""'); eq(Finance::csvCell(12.5), '12,5'); eq(Finance::csvCell(null), '');
    $csv = Finance::toCsv(['Nome'], [['=HYPERLINK("x")']]);
    ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=HYPERLINK"));
});
t('CSV de pagamentos do período traz estorno negativo', function () use ($sid, $tz) {
    $csv = Finance::paymentsCsv($sid, Finance::period($tz, '2026-11-01', '2026-11-30'), $tz);
    ok(str_contains($csv, 'Estorno;-5') || str_contains($csv, 'Estorno;-15'), $csv);
});
t('período inválido cai no mês corrente', function () use ($tz) { $p = Finance::period($tz, 'xx', '2020-01-01'); eq($p['from_date'], '2026-11-01'); });

// ------------------------------------------------------------------ LGPD
t('exportação do cliente traz cadastro, atendimentos e mensagens', function () use ($sid, $o) {
    $cid = (int)DB::val("SELECT id FROM customers WHERE shop_id = ? AND name = 'Joana Silva'", [$sid]);
    $x = Shops::exportCustomer($sid, $o, $cid);
    eq($x['cliente']['name'], 'Joana Silva'); ok(count($x['agendamentos']) >= 1); ok(count($x['mensagens']) >= 1);
});
t('anonimizar: bloqueado com agendamento futuro; depois remove dados pessoais e mensagens, mantém financeiro', function () use ($sid, $o, $pro) {
    $cid = (int)DB::val("SELECT id FROM customers WHERE shop_id = ? AND name = 'Joana Silva'", [$sid]);
    $f = book($sid, [(int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sid])], $pro, '2026-11-20 10:00', 'Joana Silva', '11911112222');
    throws(fn() => Shops::anonymizeCustomer($sid, $o, $cid), 'has_future_appointments');
    Scheduling::cancel($sid, $f['appointment_id'], 'staff', $o);
    $pays = (int)DB::val('SELECT COALESCE(SUM(amount_cents),0) FROM shop_payments WHERE customer_id = ?', [$cid]);
    Shops::anonymizeCustomer($sid, $o, $cid);
    $c = DB::one('SELECT * FROM customers WHERE id = ?', [$cid]);
    eq($c['name'], 'Cliente anonimizado'); ok(!str_contains($c['phone'], '11911112222')); eq($c['notes'], ''); eq((int)$c['whatsapp_optin'], 0); ok($c['anonymized_at'] !== null);
    eq((int)DB::val('SELECT COUNT(*) FROM outbox WHERE customer_id = ?', [$cid]), 0);
    eq((int)DB::val('SELECT COALESCE(SUM(amount_cents),0) FROM shop_payments WHERE customer_id = ?', [$cid]), $pays, 'financeiro preservado');
    eq(Shops::customerHistory($sid, $cid), null);
    ok(!in_array($cid, array_column(Shops::listCustomers($sid), 'id')));
    // o mesmo telefone volta a agendar como cliente novo
    $g = book($sid, [(int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sid])], $pro, '2026-11-21 10:00', 'Joana Silva', '11911112222');
    ok((int)$g['customer_id'] !== $cid);
});

// ------------------------------------------------------------------ mensagens
t('confirmação só para quem autorizou; lembretes exigem recurso do plano', function () use ($o) {
    $u = mk_user('m@x.com'); $s = mk_shop($u, 'msg', 'basico'); $sv = mk_service($s); $p = pro_of($s);
    $a = book($s, [$sv], $p, '2026-11-10 10:00', 'Com Aceite', '11955550001', ['whatsapp_consent' => true]);
    $b = book($s, [$sv], $p, '2026-11-10 11:00', 'Sem Aceite', '11955550002');
    eq((int)DB::val('SELECT COUNT(*) FROM outbox WHERE appointment_id = ?', [$b['appointment_id']]), 0, 'sem consentimento nada é programado');
    $k = DB::all('SELECT kind FROM outbox WHERE appointment_id = ?', [$a['appointment_id']]);
    eq(array_column($k, 'kind'), ['confirmacao'], 'Básico: sem lembretes');
    $s2 = mk_shop(mk_user('m2@x.com'), 'msg2', 'profissional'); $sv2 = mk_service($s2); $p2 = pro_of($s2);
    $c = book($s2, [$sv2], $p2, '2026-11-10 10:00', 'Com Aceite', '11955550003', ['whatsapp_consent' => true]);
    $kinds = array_column(DB::all('SELECT kind FROM outbox WHERE appointment_id = ? ORDER BY send_at', [$c['appointment_id']]), 'kind');
    eq($kinds, ['confirmacao', 'lembrete', 'lembrete'], 'Profissional: 24h e 2h + confirmação');
});
t('entrega simulada: nada vira "enviada de verdade"; limite mensal 0 = nenhuma mensagem', function () {
    // plano Básico tem 0 mensagens/mês → confirmação é "skipped" por limite
    $s = (int)DB::val("SELECT id FROM shops WHERE slug = 'msg'");
    set_now('2026-11-02 10:05:00');
    Messages::deliverDue();
    $st = DB::val("SELECT status FROM outbox WHERE shop_id = ? AND kind = 'confirmacao'", [$s]);
    eq($st, 'skipped'); ok(str_contains((string)DB::val("SELECT detail FROM outbox WHERE shop_id = ?", [$s]), 'limite'));
    // plano Profissional entrega (simulado)
    $s2 = (int)DB::val("SELECT id FROM shops WHERE slug = 'msg2'");
    eq(DB::val("SELECT status FROM outbox WHERE shop_id = ? AND kind = 'confirmacao'", [$s2]), 'simulated');
    ok(str_contains((string)DB::val("SELECT detail FROM outbox WHERE shop_id = ? AND kind = 'confirmacao'", [$s2]), 'nada foi enviado'));
    ok((int)DB::val("SELECT COUNT(*) FROM outbox WHERE status = 'sent'") === 0);
});
t('limite mensal do plano é imposto (sem ilimitado)', function () {
    $s2 = (int)DB::val("SELECT id FROM shops WHERE slug = 'msg2'");
    DB::exec("UPDATE plans SET max_messages_month = 2 WHERE code = 'profissional'");
    DB::exec("DELETE FROM usage_counters WHERE shop_id = ? AND metric = 'messages'", [$s2]);
    $sv = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$s2]); $p = pro_of($s2);
    for ($i = 0; $i < 4; $i++) book($s2, [$sv], $p, '2026-11-12 ' . (9 + $i) . ':00', 'Cliente ' . $i, '1195555010' . $i, ['whatsapp_consent' => true]);
    Messages::deliverDue(100);
    $sim = (int)DB::val("SELECT COUNT(*) FROM outbox o WHERE o.shop_id = ? AND o.status = 'simulated' AND o.created_at >= '2026-11-02 10:00:00'", [$s2]);
    $cnt = (int)DB::val("SELECT cnt FROM usage_counters WHERE shop_id = ? AND metric = 'messages'", [$s2]);
    eq($cnt, 2, 'contador não passa do limite'); 
    ok((int)DB::val("SELECT COUNT(*) FROM outbox WHERE shop_id = ? AND detail LIKE '%limite mensal%'", [$s2]) >= 1);
    DB::exec("UPDATE plans SET max_messages_month = 300 WHERE code = 'profissional'");
});
t('cancelar cancela lembretes na fila; lembrete de agendamento cancelado é ignorado', function () {
    $s = mk_shop(mk_user('mc@x.com'), 'msg3', 'profissional'); $sv = mk_service($s); $p = pro_of($s);
    $a = book($s, [$sv], $p, '2026-11-20 10:00', 'Cancela', '11955551001', ['whatsapp_consent' => true]);
    Scheduling::cancel($s, $a['appointment_id'], 'staff', 1);
    eq((int)DB::val("SELECT COUNT(*) FROM outbox WHERE appointment_id = ? AND kind = 'lembrete' AND status = 'queued'", [$a['appointment_id']]), 0);
    ok((int)DB::val("SELECT COUNT(*) FROM outbox WHERE appointment_id = ? AND kind = 'cancelamento'", [$a['appointment_id']]) === 1);
});
t('tarefa preguiçosa roda no máximo a cada 2 min', function () {
    DB::exec("DELETE FROM meta WHERE k = 'last_tick'");
    Messages::lazyTick(); $t1 = DB::val("SELECT v FROM meta WHERE k = 'last_tick'");
    set_now('2026-11-02 10:06:00'); Messages::lazyTick(); eq(DB::val("SELECT v FROM meta WHERE k = 'last_tick'"), $t1, '1 min depois: não roda');
    set_now('2026-11-02 10:09:00'); Messages::lazyTick(); ok(DB::val("SELECT v FROM meta WHERE k = 'last_tick'") !== $t1, '3 min depois: roda');
    set_now('2026-11-02 10:00:00');
});

// ------------------------------------------------------------------ autenticação
t('cadastro: senha fraca, e-mail inválido/duplicado', function () {
    throws(fn() => Auth::signup('Ana', 'ana@x.com', 'curta1'), 'weak_password');
    throws(fn() => Auth::signup('Ana', 'ana@x.com', 'somenteletrasaqui'), 'weak_password');
    throws(fn() => Auth::signup('Ana', 'nao-e-email', 'SenhaBoa12345'));
    Auth::signup('Ana', 'Ana@X.com', 'SenhaBoa12345');
    throws(fn() => Auth::signup('Ana', 'ana@x.com', 'SenhaBoa12345'), 'email_taken');
    ok(password_get_info(DB::val("SELECT password_hash FROM users WHERE email = 'ana@x.com'"))['algo'] !== null, 'hash, nunca texto puro');
});
t('login: mensagem genérica (sem enumerar usuários) e limite de tentativas', function () {
    $e1 = throws(fn() => Auth::login('ana@x.com', 'errada12345'), 'bad_credentials');
    $e2 = throws(fn() => Auth::login('naoexiste@x.com', 'errada12345'), 'bad_credentials');
    eq($e1->getMessage(), $e2->getMessage());
    Auth::login('ana@x.com', 'SenhaBoa12345');
    for ($i = 0; $i < 12; $i++) { try { Auth::login('bloq@x.com', 'x'); } catch (BusinessError $e) {} }
    throws(fn() => Auth::login('bloq@x.com', 'x'), 'rate_limited');
});
t('recuperação de senha: token de uso único, expira, resposta igual para e-mail inexistente', function () {
    @unlink(BF_ROOT . '/storage/mail.log');
    Auth::requestReset('ana@x.com', '1.1.1.1'); Auth::requestReset('fantasma@x.com', '1.1.1.1');
    $log = file_get_contents(BF_ROOT . '/storage/mail.log');
    ok(preg_match('#/redefinir-senha/([A-Za-z0-9_-]{40,})#', $log, $m), 'link no log (e-mail não é enviado em hospedagem gratuita)');
    ok(!str_contains($log, 'fantasma'));
    $tok = $m[1];
    ok(DB::val("SELECT reset_token_hash FROM users WHERE email = 'ana@x.com'") === hash('sha256', $tok) && !str_contains((string)DB::val("SELECT reset_token_hash FROM users WHERE email='ana@x.com'"), $tok), 'só o hash fica no banco');
    throws(fn() => Auth::resetPassword($tok, 'curta'), 'weak_password');
    Auth::resetPassword($tok, 'NovaSenha12345');
    throws(fn() => Auth::resetPassword($tok, 'OutraSenha12345'), 'bad_token');
    Auth::login('ana@x.com', 'NovaSenha12345');
    Auth::requestReset('ana@x.com', '2.2.2.2'); preg_match_all('#/redefinir-senha/([A-Za-z0-9_-]{40,})#', file_get_contents(BF_ROOT . '/storage/mail.log'), $mm);
    set_now('2026-11-02 12:00:00');
    throws(fn() => Auth::resetPassword(end($mm[1]), 'MaisUma12345'), 'bad_token', 'expirado após 1h');
    set_now('2026-11-02 10:00:00');
});

// ------------------------------------------------------------------ isolamento entre barbearias
t('isolamento: nenhuma operação por id alcança dados de outra barbearia', function () use ($sid, $pro) {
    $A = $sid; $B = (int)DB::val("SELECT id FROM shops WHERE slug = 'msg2'");
    $apptA = (int)DB::val('SELECT id FROM appointments WHERE shop_id = ? LIMIT 1', [$A]);
    $custA = (int)DB::val('SELECT id FROM customers WHERE shop_id = ? AND anonymized_at IS NULL LIMIT 1', [$A]);
    $svcA = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$A]);
    $proA = $pro;
    throws(fn() => Scheduling::cancel($B, $apptA, 'staff', 1), 'not_found');
    throws(fn() => Scheduling::setOutcome($B, $apptA, 'completed', 1), 'not_found');
    throws(fn() => Scheduling::reschedule($B, $apptA, local('2026-11-25 10:00'), 'staff', 1), 'not_found');
    throws(fn() => Shops::updateCustomer($B, 1, $custA, 'Hack Teste', '11999999999', '', ''), 'not_found');
    throws(fn() => Shops::updateService($B, 1, $svcA, ['name' => 'Hack', 'price_cents' => 1, 'duration_min' => 30]), 'not_found');
    throws(fn() => Shops::updateProfessional($B, 1, $proA, ['name' => 'Hack', 'active' => true]), 'not_found');
    throws(fn() => Shops::exportCustomer($B, 1, $custA), 'not_found');
    throws(fn() => Shops::anonymizeCustomer($B, 1, $custA), 'not_found');
    throws(fn() => Shops::setHours($B, $proA, [['weekday' => 1, 'start' => '09:00', 'end' => '10:00']]), 'not_found');
    throws(fn() => Shops::setProfessionalServices($B, $proA, [$svcA]), 'not_found');
    eq(Shops::customerHistory($B, $custA), null);
    throws(fn() => Shops::deleteBlock($B, 1, 1), 'not_found');
    throws(fn() => Finance::deleteExpense($B, 1, (int)DB::val('SELECT id FROM expenses LIMIT 1')), 'not_found');
    throws(fn() => Finance::refund($B, 1, (int)DB::val('SELECT id FROM shop_payments LIMIT 1')), 'not_found');
    eq(DB::val('SELECT status FROM appointments WHERE id = ?', [$apptA]), DB::val('SELECT status FROM appointments WHERE id = ?', [$apptA]));
    ok(!in_array($apptA, array_column(Shops::appointmentsBetween($B, local('2026-01-01')->setTimezone(new DateTimeZone('UTC')), local('2027-12-31')->setTimezone(new DateTimeZone('UTC'))), 'id')));
});
t('mesmo telefone em duas barbearias = dois clientes independentes', function () {
    $x = mk_shop(mk_user('iso1@x.com'), 'iso1'); $y = mk_shop(mk_user('iso2@x.com'), 'iso2');
    $sx = mk_service($x); $sy = mk_service($y);
    book($x, [$sx], pro_of($x), '2026-11-03 10:00', 'Compartilhado', '11970000001'); book($y, [$sy], pro_of($y), '2026-11-03 10:00', 'Compartilhado', '11970000001');
    eq((int)DB::val("SELECT COUNT(DISTINCT id) FROM customers WHERE phone = '5511970000001'"), 2);
});
t('sessão: shop_id de outra barbearia na sessão é ignorado (só vale se for membro)', function () {
    $u = (int)DB::val("SELECT id FROM users WHERE email = 'iso1@x.com'"); $otherShop = (int)DB::val("SELECT id FROM shops WHERE slug = 'iso2'");
    $ctx = Ctx::load(['id' => $u, 'name' => 'x', 'email' => 'iso1@x.com', 'is_admin' => 0], $otherShop);
    ok($ctx['shop_id'] !== $otherShop && $ctx['shop']['slug'] === 'iso1');
    eq(Ctx::load(['id' => 999999, 'name' => 'x', 'email' => 'z', 'is_admin' => 0], $otherShop), null, 'sem vínculo → sem contexto');
});
t('barbearia suspensa/restrita não aceita escrita', function () {
    $u = mk_user('susp@x.com'); $s = mk_shop($u, 'susp'); $user = ['id' => $u, 'name' => 'x', 'email' => 'x', 'is_admin' => 0];
    DB::exec("UPDATE shops SET status = 'suspended' WHERE id = ?", [$s]);
    $ctx = Ctx::load($user, $s); eq($ctx['billing']['access']['level'], 'blocked');
    throws(fn() => Ctx::needWritable($ctx), 'billing_restricted');
    DB::exec("UPDATE shops SET status = 'active' WHERE id = ?", [$s]);
    ok(Ctx::writable(Ctx::load($user, $s)));
});
t('limite de 3 barbearias por conta; endereço reservado/duplicado recusado', function () {
    $u = mk_user('tres@x.com'); Shops::create($u, 'Um', 'uum', '', 'basico'); Shops::create($u, 'Dois', 'ddois', '', 'basico'); Shops::create($u, 'Tres', 'ttres', '', 'basico');
    throws(fn() => Shops::create($u, 'Quatro', 'qquatro', '', 'basico'));
    $v = mk_user('res@x.com');
    throws(fn() => Shops::create($v, 'Admin', 'admin', '', 'basico'), 'slug_reserved');
    throws(fn() => Shops::create($v, 'Dup', 'uum', '', 'basico'), 'slug_taken');
    throws(fn() => Shops::create($v, 'Mau', 'a b!', '', 'basico'));
});
// ---- logo / capa ----
function tmp_img(string $kind, int $w = 600, int $h = 300): string
{
    $f = tempnam(sys_get_temp_dir(), 'img'); $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 180, 140, 80));
    $kind === 'png' ? imagepng($im, $f) : imagejpeg($im, $f); imagedestroy($im); return $f;
}
function fileent(string $path): array { return ['name' => 'x', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]; }
t('upload: PNG válido vira logo reduzida e antiga é apagada', function () {
    $u = mk_user('img1@x.com'); $s = mk_shop($u, 'img1');
    $n1 = Shops::saveImage($s, $u, 'logo', fileent(tmp_img('png', 900, 900)), true);
    $path = BF_ROOT . '/uploads/' . $n1; ok(is_file($path), 'arquivo gravado');
    [$w] = getimagesize($path); ok($w <= 480, 'redimensionada');
    ok(Shops::imageUrl($n1) !== null);
    $n2 = Shops::saveImage($s, $u, 'logo', fileent(tmp_img('jpg')), true);
    ok(!is_file($path), 'logo antiga removida'); ok(is_file(BF_ROOT . '/uploads/' . $n2));
    eq(DB::val('SELECT logo_path FROM shops WHERE id = ?', [$s]), $n2);
    Shops::removeImage($s, $u, 'logo'); ok(!is_file(BF_ROOT . '/uploads/' . $n2)); eq(DB::val('SELECT logo_path FROM shops WHERE id = ?', [$s]), null);
});
t('upload: arquivo PHP disfarçado de imagem é recusado', function () {
    $u = mk_user('img2@x.com'); $s = mk_shop($u, 'img2');
    $f = tempnam(sys_get_temp_dir(), 'evil'); file_put_contents($f, "<?php echo 'x'; ?>");
    throws(fn() => Shops::saveImage($s, $u, 'cover', fileent($f), true), 'bad_image');
    $g = tempnam(sys_get_temp_dir(), 'evil'); file_put_contents($g, "GIF89a<?php system('id'); ?>");
    throws(fn() => Shops::saveImage($s, $u, 'cover', fileent($g), true), 'bad_image', 'GIF não é aceito');
    eq(DB::val('SELECT cover_path FROM shops WHERE id = ?', [$s]), null);
});
t('upload: acima de 2 MB, tipo inválido e sem arquivo', function () {
    $u = mk_user('img3@x.com'); $s = mk_shop($u, 'img3');
    $big = tmp_img('png'); file_put_contents($big, str_repeat('0', 2 * 1024 * 1024 + 10), FILE_APPEND);
    throws(fn() => Shops::saveImage($s, $u, 'cover', fileent($big), true), 'image_too_big');
    throws(fn() => Shops::saveImage($s, $u, 'banner', fileent(tmp_img('png')), true));
    throws(fn() => Shops::saveImage($s, $u, 'logo', ['error' => UPLOAD_ERR_NO_FILE], true), 'no_file');
    throws(fn() => Shops::saveImage($s, $u, 'logo', fileent(tmp_img('png')), false), null, 'só aceita arquivo de upload HTTP');
});
t('imageUrl só aceita nome gerado pelo sistema (sem path traversal)', function () {
    eq(Shops::imageUrl('../config.php'), null); eq(Shops::imageUrl('1-logo-abc.php'), null); eq(Shops::imageUrl(null), null);
});
t('dashboard: gráfico semanal tem 7+7 dias e conta só confirmados', function () {
    $u = mk_user('dash@x.com'); $s = mk_shop($u, 'dash'); $sv = mk_service($s);
    $d = Shops::dashboard($s, SP);
    eq(count($d['week_ahead']), 7); eq(count($d['week_received']), 7); eq(array_sum(array_column($d['week_ahead'], 'count')), 0);
    eq($d['setup']['booking'], false);
});

done();
