<?php
require __DIR__ . '/lib.php';
echo "== Assinatura, webhook e acesso\n";
reset_db();
set_now('2026-11-02 10:00:00');
$o = mk_user('dono@x.com');

t('decide(): matriz de estados e janelas de carência', function () {
    $now = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC'));
    $d = fn($sub, $shop = 'active') => Billing::decide($sub, $shop, $now)['level'];
    $mk = fn($a) => $a + ['cancel_at_period_end' => 0, 'trial_ends_at' => null, 'current_period_end' => null, 'grace_until' => null];
    eq($d(null), 'blocked');
    eq($d($mk(['status' => 'incomplete'])), 'blocked');
    eq($d($mk(['status' => 'trialing', 'trial_ends_at' => '2026-11-12 00:00:00'])), 'full');
    eq($d($mk(['status' => 'trialing', 'trial_ends_at' => '2026-11-09 00:00:00'])), 'blocked', 'teste vencido');
    eq($d($mk(['status' => 'active', 'current_period_end' => '2026-12-01 00:00:00'])), 'full');
    eq($d($mk(['status' => 'active', 'current_period_end' => '2026-11-01 00:00:00'])), 'full', 'active vencido ainda full até o sweep marcar past_due');
    eq($d($mk(['status' => 'past_due', 'grace_until' => '2026-11-12 00:00:00'])), 'full', 'carência');
    eq($d($mk(['status' => 'past_due', 'grace_until' => '2026-11-05 00:00:00'])), 'restricted', '5 dias depois da carência: leitura');
    eq($d($mk(['status' => 'past_due', 'grace_until' => '2026-10-20 00:00:00'])), 'blocked', 'passou a janela restrita');
    eq($d($mk(['status' => 'canceled'])), 'blocked');
    eq($d($mk(['status' => 'suspended'])), 'blocked');
    eq($d($mk(['status' => 'active', 'current_period_end' => '2026-12-01 00:00:00']), 'suspended'), 'blocked', 'barbearia suspensa pelo admin');
    eq($d($mk(['status' => 'active', 'cancel_at_period_end' => 1, 'current_period_end' => '2026-11-09 00:00:00'])), 'blocked', 'cancelamento agendado, período acabou');
    eq($d($mk(['status' => 'active', 'cancel_at_period_end' => 1, 'current_period_end' => '2026-11-20 00:00:00'])), 'full', 'cancelamento agendado, ainda dentro do período pago');
});

$sid = Shops::create($o, 'Loja', 'loja', '11999990000', 'profissional');
$sub = fn() => DB::one('SELECT * FROM subscriptions WHERE shop_id = ?', [$sid]);
t('conta nova: assinatura incompleta e painel bloqueado', function () use ($sid, $sub) {
    eq($sub()['status'], 'incomplete');
    eq(Billing::state($sid)['access']['level'], 'blocked');
});
$ch = null;
t('iniciar pagamento NÃO ativa a assinatura', function () use ($sid, $o, $sub, &$ch) {
    $ch = Billing::start($sid, $o, 'profissional', false);
    eq($sub()['status'], 'incomplete');
    eq(Billing::state($sid)['access']['level'], 'blocked');
});
$ev = fn(string $event, string $id, ?string $sref = null, ?string $cref = null) => json_encode(['id' => $id, 'event' => $event, 'payment' => ['id' => $cref, 'subscription' => $sref, 'value' => 79.90, 'dueDate' => '2026-11-02']]);
t('webhook sem token / token errado / sem token configurado → 401 e nada muda', function () use ($ev, $sub, &$ch) {
    $b = $ev('PAYMENT_RECEIVED', 'evt_x1', $ch['sub_ref'], $ch['charge_ref']);
    eq(Billing::ingest(null, $b)['status'], 401);
    eq(Billing::ingest('errado', $b)['status'], 401);
    eq(Billing::ingest('', $b)['status'], 401);
    eq($sub()['status'], 'incomplete');
    eq((int)DB::val('SELECT COUNT(*) FROM billing_events'), 0, 'evento não autenticado não é gravado');
});
t('payload inválido → 400; corpo enorme → 413', function () {
    eq(Billing::ingest('tok_test_123', 'não é json')['status'], 400);
    eq(Billing::ingest('tok_test_123', json_encode(['sem' => 'event']))['status'], 400);
    eq(Billing::ingest('tok_test_123', str_repeat('a', 1_100_000))['status'], 413);
});
t('PAYMENT_CONFIRMED sozinho NÃO ativa (confirmado ≠ recebido)', function () use ($ev, $sub, &$ch) {
    $r = Billing::ingest('tok_test_123', $ev('PAYMENT_CONFIRMED', 'evt_c1', $ch['sub_ref'], $ch['charge_ref']));
    eq($r['status'], 200); eq($sub()['status'], 'incomplete');
});
t('PAYMENT_RECEIVED ativa, define período de 1 mês e libera o painel', function () use ($ev, $sub, $sid, &$ch) {
    $r = Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_r1', $ch['sub_ref'], $ch['charge_ref']));
    eq($r['accepted'], 1);
    eq($sub()['status'], 'active'); eq($sub()['current_period_end'], '2026-12-02 10:00:00');
    eq(Billing::state($sid)['access']['level'], 'full');
});
t('mesmo evento reenviado (idempotência) não estende o período nem duplica', function () use ($ev, $sub, &$ch) {
    $r = Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_r1', $ch['sub_ref'], $ch['charge_ref']));
    eq($r['duplicates'], 1); eq($r['status'], 200);
    eq($sub()['current_period_end'], '2026-12-02 10:00:00');
    eq((int)DB::val("SELECT COUNT(*) FROM billing_events WHERE event_key = 'evt_r1'"), 1);
});
t('mesma cobrança com OUTRO id de evento também não estende (cobrança já recebida)', function () use ($ev, $sub, &$ch) {
    Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_r2', $ch['sub_ref'], $ch['charge_ref']));
    eq($sub()['current_period_end'], '2026-12-02 10:00:00');
});
t('evento atrasado/fora de ordem (OVERDUE depois de RECEIVED) não regride', function () use ($ev, $sub, &$ch) {
    Billing::ingest('tok_test_123', $ev('PAYMENT_OVERDUE', 'evt_o1', $ch['sub_ref'], $ch['charge_ref']));
    eq($sub()['status'], 'active');
    eq(DB::val('SELECT status FROM billing_charges WHERE provider_ref = ?', [$ch['charge_ref']]), 'received');
});
t('assinatura sem correspondência → evento fica "failed" e pode ser reprocessado', function () use ($ev, $sid) {
    $r = Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_u1', 'sim_sub_inexistente', 'sim_pay_zzz'));
    eq($r['status'], 200, 'responde 200 só porque gravou');
    eq(DB::val("SELECT status FROM billing_events WHERE event_key = 'evt_u1'"), 'failed');
    DB::exec("UPDATE subscriptions SET provider_ref = 'sim_sub_inexistente' WHERE shop_id = ?", [$sid]);
    $id = (int)DB::val("SELECT id FROM billing_events WHERE event_key = 'evt_u1'");
    Billing::retry($id);
    eq(DB::val('SELECT status FROM billing_events WHERE id = ?', [$id]), 'processed');
    eq(Admin::failedEvents(), []);
});
t('renovação mensal paga estende a partir do fim do período (não de hoje)', function () use ($ev, $sub) {
    $ref = $sub()['provider_ref'];
    $antes = dt_from_db($sub()['current_period_end']);
    Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_ren1', $ref, 'sim_pay_ren1'));
    eq($sub()['current_period_end'], dt_db($antes->modify('+1 month')));
});
t('atraso: PAYMENT_OVERDUE de nova cobrança → past_due com carência de 5 dias, ainda acesso total', function () use ($ev, $sub, $sid) {
    $ref = $sub()['provider_ref'];
    Billing::ingest('tok_test_123', $ev('PAYMENT_OVERDUE', 'evt_od1', $ref, 'sim_pay_od1'));
    eq($sub()['status'], 'past_due'); eq($sub()['grace_until'], '2026-11-07 10:00:00');
    eq(Billing::state($sid)['access']['level'], 'full');
    set_now('2026-11-09 10:00:00'); eq(Billing::state($sid)['access']['level'], 'restricted', 'depois da carência: leitura');
    set_now('2026-11-20 10:00:00'); eq(Billing::state($sid)['access']['level'], 'blocked', 'depois da janela: bloqueado');
    set_now('2026-11-02 10:00:00');
});
t('pagamento depois do atraso reativa e limpa a carência', function () use ($ev, $sub, $sid) {
    $ref = $sub()['provider_ref'];
    Billing::ingest('tok_test_123', $ev('PAYMENT_RECEIVED', 'evt_od1_pay', $ref, 'sim_pay_od1'));
    eq($sub()['status'], 'active'); eq($sub()['grace_until'], null);
    eq(Billing::state($sid)['access']['level'], 'full');
});
t('bloqueio por inadimplência NÃO apaga dados', function () use ($sid, $o) {
    $sv = mk_service($sid); $b = book($sid, [$sv], pro_of($sid), '2026-11-03 10:00', 'Cliente Fiel');
    DB::exec("UPDATE subscriptions SET status = 'suspended' WHERE shop_id = ?", [$sid]);
    eq(Billing::state($sid)['access']['level'], 'blocked');
    eq((int)DB::val('SELECT COUNT(*) FROM appointments WHERE shop_id = ?', [$sid]), 1);
    eq((int)DB::val('SELECT COUNT(*) FROM customers WHERE shop_id = ?', [$sid]), 1);
    DB::exec("UPDATE subscriptions SET status = 'active' WHERE shop_id = ?", [$sid]);
});
t('chargeback suspende; cancelamento do provedor cancela', function () use ($ev, $sub, $sid) {
    $ref = $sub()['provider_ref'];
    Billing::ingest('tok_test_123', $ev('PAYMENT_CHARGEBACK_REQUESTED', 'evt_cb', $ref, 'sim_pay_cb'));
    eq($sub()['status'], 'suspended');
    Billing::ingest('tok_test_123', json_encode(['id' => 'evt_del', 'event' => 'SUBSCRIPTION_DELETED', 'subscription' => ['id' => $ref]]));
    eq($sub()['status'], 'canceled');
});
t('reativar após cancelamento: novo checkout volta a "incomplete" e só o webhook ativa', function () use ($sid, $o, $sub) {
    $r = Billing::start($sid, $o, 'equipe', false);
    eq($sub()['status'], 'incomplete'); eq((int)$sub()['price_cents'], 14990);
    Billing::simulate('PAYMENT_RECEIVED', $r['sub_ref'], $r['charge_ref'], $r['amount_cents'], '2026-11-02');
    eq($sub()['status'], 'active');
    throws(fn() => Billing::start($sid, $o, 'basico', false), 'already_subscribed');
});
t('teste grátis: um por barbearia; vira bloqueado ao vencer', function () {
    $u = mk_user('t@x.com'); $s = Shops::create($u, 'Teste', 'teste-gratis', '', 'basico');
    $r = Billing::start($s, $u, 'basico', true);
    eq($r['status'], 'trialing'); eq(Billing::state($s)['access']['level'], 'full');
    set_now('2026-11-10 10:00:00'); eq(Billing::state($s)['access']['level'], 'blocked', '8 dias depois: teste de 7 dias vencido');
    Billing::sweep(); eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$s]), 'incomplete');
    throws(fn() => Billing::start($s, $u, 'basico', true), 'trial_unavailable');
    set_now('2026-11-02 10:00:00');
});
t('cancelar assinatura ativa: acesso segue até o fim do período; depois bloqueia; pode desfazer', function () use ($sid, $o, $sub) {
    Billing::cancel($sid, $o); eq((int)$sub()['cancel_at_period_end'], 1); eq(Billing::state($sid)['access']['level'], 'full');
    Billing::resume($sid, $o); eq((int)$sub()['cancel_at_period_end'], 0);
    Billing::cancel($sid, $o);
    set_now('2027-02-01 10:00:00'); Billing::sweep(); eq($sub()['status'], 'canceled'); eq(Billing::state($sid)['access']['level'], 'blocked');
    set_now('2026-11-02 10:00:00');
});
t('trocar de plano valida limite de profissionais', function () use ($o) {
    $u = mk_user('p@x.com'); $s = mk_shop($u, 'troca', 'equipe');
    Shops::createProfessional($s, $u, ['name' => 'Dois', 'active' => true]); Shops::createProfessional($s, $u, ['name' => 'Tres', 'active' => true]);
    throws(fn() => Billing::changePlan($s, $u, 'basico'), 'plan_limit');
    Billing::changePlan($s, $u, 'profissional');
    eq((int)DB::val('SELECT price_cents FROM subscriptions WHERE shop_id = ?', [$s]), 7990);
});
t('varredura: ativo com período vencido vira past_due (renovação não paga)', function () {
    $u = mk_user('s@x.com'); $s = mk_shop($u, 'varre');
    set_now('2026-12-10 10:00:00'); Billing::sweep();
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$s]), 'past_due');
    set_now('2026-11-02 10:00:00');
});
t('mudar preço de um plano não altera quem já assina', function () use ($sid) {
    $adm = mk_user('adm@x.com', 'Admin', true);
    $antes = (int)DB::val('SELECT price_cents FROM subscriptions WHERE shop_id = ?', [$sid]);
    $p = DB::one("SELECT * FROM plans WHERE code = 'equipe'");
    Admin::savePlan($adm, ['code' => 'equipe', 'name' => 'Equipe', 'price_cents' => 99900, 'trial_days' => 7, 'max_professionals' => 10, 'max_messages_month' => 1000, 'feat_reminders' => 1, 'feat_commissions' => 1, 'is_active' => 1, 'is_public' => 1]);
    eq((int)DB::val('SELECT price_cents FROM plans WHERE code = ?', ['equipe']), 99900);
    eq((int)DB::val('SELECT price_cents FROM subscriptions WHERE shop_id = ?', [$sid]), $antes, 'assinante antigo mantém o preço');
    Admin::savePlan($adm, ['code' => 'equipe', 'name' => 'Equipe', 'price_cents' => 14990, 'trial_days' => 7, 'max_professionals' => 10, 'max_messages_month' => 1000, 'feat_reminders' => 1, 'feat_commissions' => 1, 'is_active' => 1, 'is_public' => 1]);
});
t('plano com mensagens "ilimitadas" não existe: valor negativo vira 0', function () {
    $adm = (int)DB::val("SELECT id FROM users WHERE email = 'adm@x.com'");
    Admin::savePlan($adm, ['code' => 'x1', 'name' => 'Xx', 'price_cents' => 100, 'max_messages_month' => -1]);
    eq((int)DB::val("SELECT max_messages_month FROM plans WHERE code = 'x1'"), 0);
    Admin::savePlan($adm, ['code' => 'x1', 'name' => 'Xx', 'price_cents' => 100, 'max_messages_month' => 'abc']);
    eq((int)DB::val("SELECT max_messages_month FROM plans WHERE code = 'x1'"), 0);
});
t('admin: MRR contratado é diferente de recebido; suspender exige motivo e bloqueia', function () {
    $sid = mk_shop(mk_user('adm-shop@x.com'), 'adm-shop');
    $adm = (int)DB::val("SELECT id FROM users WHERE email = 'adm@x.com'");
    $m = Admin::metrics();
    ok($m['subs']['mrr'] > 0 && $m['rec']['received_month'] >= 0);
    throws(fn() => Admin::suspend($adm, $sid, 'x'), null, 'motivo curto');
    Admin::suspend($adm, $sid, 'Chargeback confirmado');
    eq(Billing::state($sid)['access']['level'], 'blocked');
    eq(DB::val("SELECT action FROM audit_logs WHERE shop_id = ? AND action = 'admin.shop_suspended'", [$sid]), 'admin.shop_suspended');
    Admin::reactivate($adm, $sid, 'Regularizado hoje');
    eq(Billing::state($sid)['access']['level'], 'full');
});
done();
