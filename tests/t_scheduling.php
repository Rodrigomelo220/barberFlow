<?php
require __DIR__ . '/lib.php';
echo "== Agenda e reservas\n";
reset_db();
set_now('2026-11-02 10:00:00');   // segunda 07:00 em São Paulo
$o = mk_user('dono@x.com');
$sid = mk_shop(mk_user('d1@x.com'), 'loja-a');
$pro = pro_of($sid);
$corte = mk_service($sid, 'Corte', 5000, 30);
$barba = mk_service($sid, 'Barba', 3000, 20);

t('slots do dia respeitam expediente 09–18 e grade de 15 min', function () use ($sid, $corte) {
    $s = Scheduling::slots($sid, [$corte], 'any', '2026-11-03', true);
    eq($s[0]['label'], '09:00'); eq(end($s)['label'], '17:30');   // corte de 30 min termina às 18:00
    eq(count($s), 35);
});
t('domingo sem expediente → sem horários', function () use ($sid, $corte) { eq(Scheduling::slots($sid, [$corte], 'any', '2026-11-08', true), []); });
t('dia passado e além do limite → vazio', function () use ($sid, $corte) {
    eq(Scheduling::slots($sid, [$corte], 'any', '2026-11-01', true), []);
    eq(Scheduling::slots($sid, [$corte], 'any', '2027-03-01', true), []);
    eq(Scheduling::slots($sid, [$corte], 'any', 'lixo', true), []);
});
t('antecedência mínima remove horários próximos (hoje 07:00, mínimo 60 min)', function () use ($sid, $corte) {
    set_now('2026-11-03 12:10:00');   // terça 09:10 local
    $s = Scheduling::slots($sid, [$corte], 'any', '2026-11-03', true);
    try { eq($s[0]['label'], '10:15', 'primeiro horário ≥ 10:10 na grade de 15'); } finally { set_now('2026-11-02 10:00:00'); }
});
t('duração soma serviços (corte+barba = 50 min)', function () use ($sid, $corte, $barba) {
    $s = Scheduling::slots($sid, [$corte, $barba], 'any', '2026-11-03', true);
    eq(end($s)['label'], '17:00', 'último início que cabe');
});
$a1 = null;
t('reserva grava em UTC e devolve token', function () use ($sid, $corte, $pro, &$a1) {
    $a1 = book($sid, [$corte], $pro, '2026-11-03 10:00');
    eq(dt_db($a1['starts_at']), '2026-11-03 13:00:00');
    eq(strlen($a1['manage_token']) >= 40, true);
    eq(DB::val('SELECT starts_at FROM appointments WHERE id = ?', [$a1['appointment_id']]), '2026-11-03 13:00:00');
});
t('horário reservado some da grade; vizinhos continuam', function () use ($sid, $corte) {
    $l = array_column(Scheduling::slots($sid, [$corte], 'any', '2026-11-03', true), 'label');
    ok(!in_array('10:00', $l) && !in_array('09:45', $l) && !in_array('10:15', $l), 'sobreposição deve sumir');
    ok(in_array('09:30', $l) && in_array('10:30', $l), 'encostar é permitido');
});
t('segunda reserva no mesmo horário falha com slot_unavailable', function () use ($sid, $corte, $pro) {
    throws(fn() => book($sid, [$corte], $pro, '2026-11-03 10:00', 'Outro', '11977776666'), 'slot_unavailable');
    throws(fn() => book($sid, [$corte], $pro, '2026-11-03 10:15', 'Outro', '11977776666'), 'slot_unavailable', 'sobreposição parcial');
    eq((int)DB::val("SELECT COUNT(*) FROM appointments WHERE shop_id = ? AND status='confirmed'", [$sid]), 1);
});
t('horário fora da grade ou do expediente é recusado', function () use ($sid, $corte, $pro) {
    throws(fn() => book($sid, [$corte], $pro, '2026-11-03 08:00'), 'slot_unavailable');
    throws(fn() => book($sid, [$corte], $pro, '2026-11-03 10:07'), 'slot_unavailable');
    throws(fn() => book($sid, [$corte], $pro, '2026-11-03 17:45'), 'slot_unavailable', 'estoura o fim do expediente');
});
t('horário no passado é recusado', function () use ($sid, $corte, $pro) { throws(fn() => book($sid, [$corte], $pro, '2026-11-02 06:00'), 'in_the_past'); });
t('cancelar libera o horário e permite reservar de novo', function () use ($sid, $corte, $pro, &$a1) {
    Scheduling::cancel($sid, $a1['appointment_id'], 'staff', 1);
    $b = book($sid, [$corte], $pro, '2026-11-03 10:00', 'Novo', '11966665555');
    ok($b['appointment_id'] !== $a1['appointment_id']);
    Scheduling::cancel($sid, $b['appointment_id'], 'staff', 1);
});
t('cancelar duas vezes é idempotente', function () use ($sid, &$a1) { Scheduling::cancel($sid, $a1['appointment_id'], 'staff', 1); ok(true); });

t('folgas antes/depois entram na ocupação', function () use ($o) {
    $s2 = mk_shop(mk_user('d2@x.com'), 'loja-b'); $p2 = pro_of($s2);
    $sv = mk_service($s2, 'Química', 9000, 60, 15, 15);
    book($s2, [$sv], $p2, '2026-11-03 10:00');        // ocupa 09:45–11:15
    $l = array_column(Scheduling::slots($s2, [$sv], 'any', '2026-11-03', true), 'label');
    ok(!in_array('09:00', $l), '09:00 termina 10:00 + 15 de folga = bate com 09:45');
    ok(in_array('08:30', $l) === false);
    ok(in_array('11:30', $l), '11:30: folga antes 11:15 ok');
    ok(!in_array('11:15', $l));
    ok(!in_array('11:00', $l));
});
t('bloqueio (almoço) remove horários e impede reserva', function () use ($o, $sid, $corte, $pro) {
    Shops::createBlock($sid, $o, null, local('2026-11-04 12:00')->setTimezone(new DateTimeZone('UTC')), local('2026-11-04 13:00')->setTimezone(new DateTimeZone('UTC')), 'lunch', 'Almoço');
    $l = array_column(Scheduling::slots($sid, [$corte], 'any', '2026-11-04', true), 'label');
    ok(!in_array('12:00', $l) && !in_array('12:45', $l) && !in_array('11:45', $l));
    ok(in_array('11:30', $l) && in_array('13:00', $l));
    throws(fn() => book($sid, [$corte], $pro, '2026-11-04 12:15'), 'slot_unavailable');
});
t('encaixe fora do expediente é permitido só no painel e sem sobrepor', function () use ($sid, $corte, $pro) {
    $b = Scheduling::book(['shop_id' => $sid, 'service_ids' => [$corte], 'professional_id' => $pro, 'starts_at' => local('2026-11-05 19:00'), 'name' => 'Encaixe', 'phone' => '11955554444', 'source' => 'manual', 'allow_outside_hours' => true]);
    ok($b['appointment_id'] > 0);
    throws(fn() => Scheduling::book(['shop_id' => $sid, 'service_ids' => [$corte], 'professional_id' => $pro, 'starts_at' => local('2026-11-05 19:15'), 'name' => 'Outro', 'phone' => '11955554443', 'source' => 'manual', 'allow_outside_hours' => true]), 'slot_unavailable');
    throws(fn() => Scheduling::book(['shop_id' => $sid, 'service_ids' => [$corte], 'professional_id' => $pro, 'starts_at' => local('2026-11-06 19:00'), 'name' => 'Online', 'phone' => '11955554442', 'source' => 'link', 'allow_outside_hours' => true]), 'slot_unavailable', 'link público ignora encaixe');
});
t('qualquer profissional: balanceia e usa o único livre', function () use ($o) {
    $s = mk_shop(mk_user('d3@x.com'), 'loja-c'); $sv = mk_service($s, 'Corte', 5000, 30);
    $p1 = pro_of($s); $p2 = Shops::createProfessional($s, 0, ['name' => 'Segundo', 'active' => true]);
    Shops::setHours($s, $p2, array_map(fn($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '18:00'], [1, 2, 3, 4, 5, 6]));
    Shops::setProfessionalServices($s, $p2, [$sv]);
    $a = book($s, [$sv], 'any', '2026-11-03 10:00', 'Ana', '11911110001');
    $b = book($s, [$sv], 'any', '2026-11-03 10:00', 'Bia', '11911110002');
    ok($a['professional_id'] !== $b['professional_id'], 'dois clientes no mesmo horário vão para profissionais diferentes');
    throws(fn() => book($s, [$sv], 'any', '2026-11-03 10:00', 'Caio', '11911110003'), 'slot_unavailable');
    $l = Scheduling::slots($s, [$sv], 'any', '2026-11-03', true);
    ok(!in_array('10:00', array_column($l, 'label')), 'nenhum livre às 10:00');
});
t('profissional que não faz o serviço não é elegível', function () use ($o) {
    $s = mk_shop(mk_user('d4@x.com'), 'loja-d'); $sv = mk_service($s, 'Corte'); $sv2 = mk_service($s, 'Barba', 3000, 20);
    $p1 = pro_of($s); Shops::setProfessionalServices($s, $p1, [$sv]);
    throws(fn() => book($s, [$sv2], $p1, '2026-11-03 10:00'), 'no_professional');
    eq(Scheduling::slots($s, [$sv2], 'any', '2026-11-03', true), []);
});
t('serviço de outra barbearia é recusado', function () use ($sid, $pro) {
    $s2 = (int)DB::val("SELECT id FROM shops WHERE slug = 'loja-b'"); $sv2 = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$s2]);
    throws(fn() => book($sid, [$sv2], $pro, '2026-11-03 14:00'), 'service_unavailable');
});
t('serviço só interno não aparece no link público', function () use ($o) {
    $s = mk_shop(mk_user('d5@x.com'), 'loja-e'); $sv = Shops::createService($s, 0, ['name' => 'Interno', 'price_cents' => 1000, 'duration_min' => 15, 'active' => 1, 'bookable_online' => 0]);
    throws(fn() => book($s, [$sv], pro_of($s), '2026-11-03 10:00'), 'service_unavailable');
    $b = Scheduling::book(['shop_id' => $s, 'service_ids' => [$sv], 'professional_id' => pro_of($s), 'starts_at' => local('2026-11-03 10:00'), 'name' => 'Xis', 'phone' => '11900000001', 'source' => 'manual']);
    ok($b['appointment_id'] > 0);
});
t('mudar preço do serviço não altera atendimentos já criados', function () use ($sid, $corte, $o) {
    $b = book($sid, [$corte], pro_of($sid), '2026-11-06 10:00', 'Preço', '11944443333');
    Shops::updateService($sid, $o, $corte, ['name' => 'Corte', 'price_cents' => 9999, 'duration_min' => 30, 'active' => 1, 'bookable_online' => 1]);
    eq((int)DB::val('SELECT total_price_cents FROM appointments WHERE id = ?', [$b['appointment_id']]), 5000);
    eq((int)DB::val('SELECT price_cents FROM appointment_services WHERE appointment_id = ?', [$b['appointment_id']]), 5000);
    Shops::updateService($sid, $o, $corte, ['name' => 'Corte', 'price_cents' => 5000, 'duration_min' => 30, 'active' => 1, 'bookable_online' => 1]);
});
t('remarcar: valida horário, libera o antigo e ocupa o novo', function () use ($sid, $corte, $pro) {
    $b = book($sid, [$corte], $pro, '2026-11-09 10:00', 'Rem', '11933332222');
    $c = book($sid, [$corte], $pro, '2026-11-09 11:00', 'Outro', '11933332221');
    throws(fn() => Scheduling::reschedule($sid, $b['appointment_id'], local('2026-11-09 11:00'), 'staff', 1), 'slot_unavailable', 'destino ocupado');
    Scheduling::reschedule($sid, $b['appointment_id'], local('2026-11-09 10:15'), 'staff', 1);   // sobrepõe o próprio horário antigo: permitido
    $b2 = book($sid, [$corte], $pro, '2026-11-09 09:00', 'Zeca', '11933332220');
    Scheduling::reschedule($sid, $b['appointment_id'], local('2026-11-09 14:00'), 'staff', 1);
    $l = array_column(Scheduling::slots($sid, [$corte], 'any', '2026-11-09', true), 'label');
    ok(in_array('10:15', $l) && in_array('10:00', $l), 'horário antigo liberado');
    ok(!in_array('14:00', $l), 'novo ocupado');
});
t('política do cliente: antecedência mínima e chave de cancelamento online', function () use ($sid, $corte, $pro, $o) {
    $b = book($sid, [$corte], $pro, '2026-11-10 10:00', 'Pol', '11922221111');
    set_now('2026-11-10 12:30:00');   // 09:30 local → faltam 30 min (< 120)
    throws(fn() => Scheduling::cancel($sid, $b['appointment_id'], 'customer'), 'policy_too_late');
    throws(fn() => Scheduling::reschedule($sid, $b['appointment_id'], local('2026-11-10 15:00'), 'customer'), 'policy_too_late');
    set_now('2026-11-10 10:00:00');   // 07:00 local → 3h antes
    Scheduling::cancel($sid, $b['appointment_id'], 'customer');
    eq(DB::val('SELECT status FROM appointments WHERE id = ?', [$b['appointment_id']]), 'canceled');
    $b2 = book($sid, [$corte], $pro, '2026-11-10 11:00', 'Pol2', '11922221112');
    DB::exec('UPDATE shops SET allow_online_cancel = 0 WHERE id = ?', [$sid]);
    throws(fn() => Scheduling::cancel($sid, $b2['appointment_id'], 'customer'), 'policy_no_online_change');
    Scheduling::cancel($sid, $b2['appointment_id'], 'staff', $o);     // equipe pode sempre
    DB::exec('UPDATE shops SET allow_online_cancel = 1 WHERE id = ?', [$sid]);
    set_now('2026-11-02 10:00:00');
});
t('concluir / faltou: não cria pagamento e encerra lembretes', function () use ($sid, $corte, $pro) {
    $b = book($sid, [$corte], $pro, '2026-11-11 10:00', 'Conc', '11911112222');
    Scheduling::setOutcome($sid, $b['appointment_id'], 'completed', 1);
    eq(DB::val('SELECT status FROM appointments WHERE id = ?', [$b['appointment_id']]), 'completed');
    eq((int)DB::val('SELECT COUNT(*) FROM shop_payments WHERE appointment_id = ?', [$b['appointment_id']]), 0, 'concluir não pode lançar pagamento');
    throws(fn() => Scheduling::cancel($sid, $b['appointment_id'], 'staff', 1), 'not_confirmed');
    $n = book($sid, [$corte], $pro, '2026-11-11 11:00', 'Falta', '11911112223');
    Scheduling::setOutcome($sid, $n['appointment_id'], 'no_show', 1);
    throws(fn() => Scheduling::setOutcome($sid, $n['appointment_id'], 'banana', 1));
});
t('fuso com horário de verão (New York, 08/03/2026) — UTC no banco, local na grade', function () use ($o) {
    $s = mk_shop(mk_user('d6@x.com'), 'loja-ny'); DB::exec("UPDATE shops SET timezone = 'America/New_York' WHERE id = ?", [$s]); DB::exec('UPDATE shops SET max_days_ahead = 200 WHERE id = ?', [$s]);
    $sv = mk_service($s, 'Corte', 5000, 30); $p = pro_of($s);
    set_now('2026-03-01 12:00:00');
    DB::exec('DELETE FROM working_hours WHERE professional_id = ?', [$p]);
    Shops::setHours($s, $p, [['weekday' => 7, 'start' => '01:00', 'end' => '05:00']]);   // domingo da virada
    $l = array_column(Scheduling::slots($s, [$sv], 'any', '2026-03-08', true), 'label');
    ok(!in_array('02:00', $l) && !in_array('02:30', $l), 'horas inexistentes (02:00–03:00) não podem aparecer: ' . implode(',', $l));
    ok(in_array('03:00', $l) && in_array('01:30', $l));
    $b = Scheduling::book(['shop_id' => $s, 'service_ids' => [$sv], 'professional_id' => $p, 'starts_at' => local('2026-03-08 04:00', 'America/New_York'), 'name' => 'NY', 'phone' => '11900000009', 'source' => 'link']);
    eq(dt_db($b['starts_at']), '2026-03-08 08:00:00', '04:00 EDT = 08:00 UTC');
    set_now('2026-11-02 10:00:00');
});
t('limite mensal de agendamentos do plano (Básico = 150) é respeitado', function () use ($o) {
    $s = mk_shop(mk_user('d7@x.com'), 'loja-lim', 'basico'); $sv = mk_service($s); $p = pro_of($s);
    DB::exec("UPDATE plans SET max_appointments_month = 2 WHERE code = 'basico'");
    DB::exec("UPDATE subscriptions SET plan_id = plan_id WHERE shop_id = ?", [$s]);
    book($s, [$sv], $p, '2026-11-03 09:00', 'Ana', '11900001001'); book($s, [$sv], $p, '2026-11-03 10:00', 'Bia', '11900001002');
    throws(fn() => book($s, [$sv], $p, '2026-11-03 11:00', 'Caio', '11900001003'), 'plan_limit');
    eq((int)DB::val("SELECT COUNT(*) FROM appointments WHERE shop_id = ?", [$s]), 2, 'a reserva recusada não pode deixar rastro');
    DB::exec("UPDATE plans SET max_appointments_month = 150 WHERE code = 'basico'");
});
t('telefone normalizado: mesmo cliente com formatos diferentes é um só', function () use ($sid, $corte, $pro) {
    book($sid, [$corte], $pro, '2026-11-12 09:00', 'Tel', '(11) 97777-1234');
    book($sid, [$corte], $pro, '2026-11-12 10:00', 'Tel', '+55 11 97777 1234');
    eq((int)DB::val("SELECT COUNT(*) FROM customers WHERE shop_id = ? AND phone = '5511977771234'", [$sid]), 1);
    throws(fn() => book($sid, [$corte], $pro, '2026-11-12 11:00', 'Tel', '123'), 'invalid_phone');
});
done();
