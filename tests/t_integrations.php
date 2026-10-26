<?php
require __DIR__ . '/lib.php';
echo "== Integrações: segredos, SMTP, Asaas, WhatsApp, convites (servidores falsos locais)\n";
reset_db();
set_now('2026-11-02 10:00:00');
$GLOBALS['BF_CFG_TEST'] = ['app.secret' => 'segredo-de-teste-0123456789abcdef'];

// ---- servidores falsos
@unlink('/tmp/bf_fake_api.log'); @unlink('/tmp/bf_fake_api.state'); @unlink('/tmp/bf_fake_smtp.txt');
$procs = [];
$spawn = function (array $cmd) use (&$procs) { $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes); $procs[] = $p; };
$spawn(['php', '-S', '127.0.0.1:8091', '-t', __DIR__, __DIR__ . '/fake_apis.php']);
$spawn(['php', __DIR__ . '/fake_smtp.php', '8092']);
register_shutdown_function(function () use (&$procs) { foreach ($procs as $p) { $s = proc_get_status($p); if ($s['running']) { @exec('kill ' . (int)$s['pid'] . ' 2>/dev/null'); } proc_close($p); } });
for ($i = 0; $i < 40; $i++) { $a = @fsockopen('127.0.0.1', 8091, $e, $s, 0.2); $b = @fsockopen('127.0.0.1', 8092, $e, $s, 0.2); if ($a && $b) { fclose($a); fclose($b); break; } usleep(100000); }
$apiLog = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents('/tmp/bf_fake_api.log'))));
$apiMode = fn(string $m) => file_get_contents('http://127.0.0.1:8091/__mode?m=' . $m);
$ASAAS_KEY = '$aact_hmlg_test_key_1234567890'; $WA_TOKEN = 'EAAG_test_token_abcdefghijklmnop';

t('segredos: criptografa, devolve igual, recusa adulteração e não guarda texto puro', function () {
    $blob = Secrets::encrypt('minha-senha-123');
    ok(!str_contains($blob, 'minha-senha')); eq(Secrets::decrypt($blob), 'minha-senha-123');
    $bad = substr($blob, 0, -3) . 'AAA'; eq(Secrets::decrypt($bad), null, 'adulterado');
    eq(Secrets::decrypt('lixo'), null);
    Secrets::put('teste', ['a' => 1, 'senha' => 'xyz-secreta']);
    eq(Secrets::get('teste')['senha'], 'xyz-secreta');
    ok(!str_contains((string)DB::val("SELECT v FROM meta WHERE k = 'secret_teste'"), 'xyz-secreta'), 'no banco só cifrado');
    eq(Secrets::get('inexistente'), []); eq(Secrets::mask('abcdefgh1234'), '••••••1234');
});

t('e-mail: cabeçalhos sem injeção, assunto UTF-8, ponto no início da linha preservado', function () {
    $m = Smtp::build("Joao\r\nBcc: x@y.com", 'a@b.com', "v@x.com\r\nBcc: z@z.com", "Assunto\r\nBcc: q@q.com ação", "Linha 1\n.ponto\nLinha 3", 'site.com');
    ok(!preg_match('/^Bcc:/mi', $m), 'sem cabeçalho injetado');
    ok(str_contains($m, 'Subject: =?UTF-8?B?') , 'assunto com acento codificado');
    ok(str_contains($m, 'Content-Transfer-Encoding: base64'));
});

t('SMTP: envia para o servidor falso (AUTH LOGIN), destinatário e corpo corretos; senha errada falha sem vazar', function () {
    Secrets::put('smtp', ['host' => '127.0.0.1', 'port' => 8092, 'security' => 'none', 'user' => 'usuario', 'pass' => 's3nha', 'from_email' => 'no-reply@site.com', 'from_name' => 'Agendô']);
    ok(Mailer::configured());
    eq(Mailer::send('cliente@x.com', 'Olá, ação!', "Olá!\n.linha com ponto\nFim"), null, 'envio ok');
    $got = (string)@file_get_contents('/tmp/bf_fake_smtp.txt');
    ok(str_contains($got, 'ENVELOPE-TO: RCPT TO:<cliente@x.com>'), 'destinatário'); ok(str_contains($got, 'ENVELOPE-FROM: MAIL FROM:<no-reply@site.com>'));
    preg_match('/\n\n(.*)$/s', $got, $mm); $decoded = base64_decode(preg_replace('/\s+/', '', $mm[1])); ok(str_contains($decoded, ".linha com ponto"), 'corpo íntegro');
    Secrets::put('smtp', ['host' => '127.0.0.1', 'port' => 8092, 'security' => 'none', 'user' => 'usuario', 'pass' => 'ERRADA', 'from_email' => 'no-reply@site.com']);
    $err = Mailer::send('cliente@x.com', 'x', 'y');
    ok($err !== null && str_contains($err, 'senha'), 'falha de autenticação reportada'); ok(!str_contains((string)$err, 'ERRADA'), 'sem senha na mensagem');
    $log = (string)@file_get_contents(BF_ROOT . '/storage/mail.log');
    ok(!str_contains($log, 'ERRADA') && !str_contains($log, 's3nha'), 'senha fora do log');
    Secrets::put('smtp', []); ok(!Mailer::configured());
});

t('CPF/CNPJ: dígitos verificadores', function () {
    ok(Asaas::validDoc('529.982.247-25')); ok(!Asaas::validDoc('529.982.247-24')); ok(!Asaas::validDoc('111.111.111-11'));
    ok(Asaas::validDoc('11.222.333/0001-81')); ok(!Asaas::validDoc('11.222.333/0001-80')); ok(!Asaas::validDoc('123'));
});

// ---- Asaas
$ow = mk_user('asaas@x.com'); $sid = Shops::create($ow, 'ASAAS', 'asaas-shop', '11999990000', 'basico');
t('Asaas: sem chave configurada recusa; chave inválida dá erro amigável e não vaza a chave', function () use ($sid, $ow, $ASAAS_KEY, $apiLog) {
    ok(!Asaas::configured()); throws(fn() => Asaas::subscribe($sid, $ow, 'basico', 'Fulano de Tal', 'asaas@x.com', '529.982.247-25'), 'asaas_off');
    Secrets::put('asaas', ['api_key' => '$aact_hmlg_chave_errada_0000', 'env' => 'sandbox']);
    $e = throws(fn() => Asaas::subscribe($sid, $ow, 'basico', 'Fulano de Tal', 'asaas@x.com', '529.982.247-25'), 'asaas_auth');
    ok(!str_contains($e->getMessage(), 'chave_errada'), 'chave não aparece');
    Secrets::put('asaas', ['api_key' => $ASAAS_KEY, 'env' => 'sandbox']); ok(Asaas::configured());
});
t('Asaas: cria cliente e assinatura com os campos certos, NÃO ativa, guarda o link da fatura; webhook ativa', function () use ($sid, $ow, $apiLog, $ASAAS_KEY) {
    throws(fn() => Asaas::subscribe($sid, $ow, 'basico', 'Fulano de Tal', 'asaas@x.com', '123.456.789-00'), null, 'CPF inválido');
    throws(fn() => Asaas::subscribe($sid, $ow, 'basico', 'Fu', 'asaas@x.com', '529.982.247-25'), null, 'nome curto');
    @unlink('/tmp/bf_fake_api.log');
    $url = Asaas::subscribe($sid, $ow, 'basico', 'Fulano de Tal', 'asaas@x.com', '529.982.247-25');
    $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ?', [$sid]);
    ok(str_starts_with($sub['provider_ref'], 'sub_fake'), 'id da assinatura guardado'); ok(str_starts_with($sub['provider_customer'], 'cus_fake'));
    eq($sub['status'], 'incomplete', 'ainda não ativa'); ok(str_starts_with((string)$url, 'https://sandbox.asaas.com/i/'));
    $calls = $apiLog(); $post = array_values(array_filter($calls, fn($c) => $c['m'] === 'POST'));
    eq($post[0]['uri'], '/v3/customers'); eq($post[0]['body']['cpfCnpj'], '52998224725', 'só dígitos'); eq($post[0]['token'], $ASAAS_KEY); ok(str_contains((string)$post[0]['ua'], 'SaaS'), 'User-Agent');
    eq($post[1]['uri'], '/v3/subscriptions'); eq($post[1]['body']['cycle'], 'MONTHLY'); eq($post[1]['body']['value'], 39.9); eq($post[1]['body']['customer'], $sub['provider_customer']);
    eq($post[1]['body']['externalReference'], 'shop:' . $sid); ok(preg_match('/^\d{4}-\d\d-\d\d$/', $post[1]['body']['nextDueDate']));
    $ch = DB::one('SELECT * FROM billing_charges WHERE shop_id = ?', [$sid]); eq($ch['status'], 'pending'); ok(str_contains($ch['invoice_url'], 'pay_' . $sub['provider_ref']));
    // webhook real (formato Asaas) ativa
    $body = json_encode(['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_' . $sub['provider_ref'], 'subscription' => $sub['provider_ref'], 'value' => 99.9, 'dueDate' => date('Y-m-d'), 'invoiceUrl' => 'javascript:alert(1)']]);
    eq(Billing::ingest('tok_test_123', $body)['status'], 200);
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$sid]), 'active', 'ativou pelo webhook');
    eq(Billing::ingest('tok_test_123', $body)['duplicates'], 1, 'idempotente');
    ok(!str_contains((string)DB::val('SELECT invoice_url FROM billing_charges WHERE shop_id = ?', [$sid]), 'javascript'), 'link só https');
    throws(fn() => Asaas::subscribe($sid, $ow, 'basico', 'Fulano de Tal', 'asaas@x.com', '529.982.247-25'), 'already_subscribed');
});
t('Asaas: troca de plano atualiza o valor no provedor; cancelar apaga a recorrência; recusa do provedor vira mensagem clara', function () use ($sid, $ow, $apiLog, $apiMode) {
    @unlink('/tmp/bf_fake_api.log');
    Billing::changePlan($sid, $ow, 'profissional');
    $c = array_values(array_filter($apiLog(), fn($c) => str_starts_with($c['uri'], '/v3/subscriptions/')));
    eq($c[0]['body']['value'], 79.9, 'valor do novo plano (fixture)'); eq($c[0]['body']['updatePendingPayments'], true);
    @unlink('/tmp/bf_fake_api.log');
    Billing::cancel($sid, $ow);
    $d = array_values(array_filter($apiLog(), fn($c) => $c['m'] === 'DELETE')); eq(count($d), 1, 'DELETE da assinatura');
    $st = DB::one('SELECT status, cancel_at_period_end FROM subscriptions WHERE shop_id = ?', [$sid]); eq($st['status'], 'active', 'período pago continua'); eq((int)$st['cancel_at_period_end'], 1);
    throws(fn() => Billing::resume($sid, $ow), 'resume_unavailable');
    $o2 = mk_user('asaas2@x.com'); $s2 = Shops::create($o2, 'A2', 'asaas-2', '11999990000', 'basico'); $apiMode('reject');
    $e = throws(fn() => Asaas::subscribe($s2, $o2, 'basico', 'Fulana de Tal', 'asaas2@x.com', '529.982.247-25')); ok(str_contains($e->getMessage(), 'CPF/CNPJ'), 'mensagem do provedor repassada'); $apiMode('ok');
});
t('Asaas: SUBSCRIPTION_DELETED com período pago só agenda o cancelamento; sem período pago cancela', function () {
    $o = mk_user('asaas3@x.com'); $s = mk_shop($o, 'asaas-3', 'basico');
    $ref = DB::val('SELECT provider_ref FROM subscriptions WHERE shop_id = ?', [$s]);
    Billing::ingest('tok_test_123', json_encode(['id' => 'evt_del1', 'event' => 'SUBSCRIPTION_DELETED', 'subscription' => ['id' => $ref]]));
    $r = DB::one('SELECT status, cancel_at_period_end FROM subscriptions WHERE shop_id = ?', [$s]); eq($r['status'], 'active'); eq((int)$r['cancel_at_period_end'], 1);
    DB::exec("UPDATE subscriptions SET status = 'past_due', cancel_at_period_end = 0 WHERE shop_id = ?", [$s]);
    Billing::ingest('tok_test_123', json_encode(['id' => 'evt_del2', 'event' => 'SUBSCRIPTION_INACTIVATED', 'subscription' => ['id' => $ref]]));
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$s]), 'canceled');
});
t('webhook: 401 sem token correto; payload sem evento recusado', function () {
    eq(Billing::ingest('errado', '{"event":"PAYMENT_RECEIVED"}')['status'], 401); eq(Billing::ingest('tok_test_123', '{"x":1}')['status'], 400);
});

// ---- WhatsApp
t('WhatsApp (modo produção): sem API vira "manual"; com API envia por modelo; falha não consome limite', function () use ($WA_TOKEN, $apiLog, $apiMode) {
    $GLOBALS['BF_CFG_TEST']['app.mode'] = 'live';
    $u = mk_user('wa@x.com'); $s = mk_shop($u, 'wa-shop', 'profissional'); $sv = mk_service($s); $p = pro_of($s);
    $a = book($s, [$sv], $p, '2026-11-10 10:00', 'Maria da Silva', '11955550101', ['whatsapp_consent' => true]);
    set_now('2026-11-02 10:05:00'); Messages::deliverDue();
    eq(DB::val("SELECT status FROM outbox WHERE appointment_id = ? AND kind = 'confirmacao'", [$a['appointment_id']]), 'manual', 'sem API: envio manual');
    // com API
    Secrets::put('whatsapp', ['token' => $WA_TOKEN, 'phone_number_id' => '109999999999', 'api_version' => 'v21.0', 'lang' => 'pt_BR', 'templates' => ['confirmacao' => 'agenda_confirmacao', 'lembrete' => 'agenda_lembrete', 'cancelamento' => 'agenda_cancelamento', 'remarcado' => 'agenda_remarcado']]);
    ok(WhatsApp::configured());
    $b = book($s, [$sv], $p, '2026-11-10 11:00', 'João Pereira', '11955550102', ['whatsapp_consent' => true]);
    @unlink('/tmp/bf_fake_api.log'); Messages::deliverDue();
    eq(DB::val("SELECT status FROM outbox WHERE appointment_id = ? AND kind = 'confirmacao'", [$b['appointment_id']]), 'sent');
    $call = array_values(array_filter($apiLog(), fn($c) => str_contains($c['uri'], '/messages')))[0];
    eq($call['uri'], '/v21.0/109999999999/messages'); eq($call['auth'], 'Bearer ' . $WA_TOKEN); eq($call['body']['to'], '5511955550102'); eq($call['body']['type'], 'template');
    eq($call['body']['template']['name'], 'agenda_confirmacao'); eq($call['body']['template']['language']['code'], 'pt_BR');
    $params = array_column($call['body']['template']['components'][0]['parameters'], 'text');
    eq($params[0], 'João'); eq($params[1], 'WA-SHOP'); ok(str_contains($params[2], '10/11 às 11:00')); eq($params[3], 'Corte'); eq(count($params), 4);
    // falha da API: marca failed e devolve o consumo do limite
    $used0 = (int)DB::val("SELECT cnt FROM usage_counters WHERE shop_id = ? AND metric = 'messages'", [$s]);
    $c = book($s, [$sv], $p, '2026-11-10 12:00', 'Ana Costa', '11955550103', ['whatsapp_consent' => true]);
    $apiMode('wa_fail'); Messages::deliverDue(); $apiMode('ok');
    eq(DB::val("SELECT status FROM outbox WHERE appointment_id = ? AND kind = 'confirmacao'", [$c['appointment_id']]), 'failed');
    ok(str_contains((string)DB::val("SELECT detail FROM outbox WHERE appointment_id = ? AND kind = 'confirmacao'", [$c['appointment_id']]), 'Template name'), 'motivo registrado');
    ok(!str_contains((string)DB::val("SELECT detail FROM outbox WHERE appointment_id = ? AND kind = 'confirmacao'", [$c['appointment_id']]), $WA_TOKEN), 'token fora do detalhe');
    eq((int)DB::val("SELECT cnt FROM usage_counters WHERE shop_id = ? AND metric = 'messages'", [$s]), $used0, 'falha não consome o limite');
    unset($GLOBALS['BF_CFG_TEST']['app.mode']);
});
t('WhatsApp: link wa.me com texto codificado; token inválido é recusado pela API', function () use ($WA_TOKEN) {
    eq(WhatsApp::waLink('5511955550101', 'Olá, tudo bem?'), 'https://wa.me/5511955550101?text=Ol%C3%A1%2C%20tudo%20bem%3F');
    Secrets::put('whatsapp', ['token' => 'EAAG_token_errado_abcdefghijk', 'phone_number_id' => '109999999999', 'templates' => ['confirmacao' => 'x']]);
    $err = WhatsApp::send('5511955550101', 'confirmacao', ['a', 'b', 'c', 'd']); ok($err !== null && str_contains($err, 'HTTP 401'));
    Secrets::put('whatsapp', []);
});

// ---- convites de funcionário
t('convite: cria, aceita com a conta certa, vincula profissional, limita a agenda própria; revoga; Solo não pode', function () {
    $o = mk_user('dono-eq@x.com'); $s = mk_shop($o, 'equipe-shop', 'profissional'); $sv = mk_service($s);
    $pro2 = Shops::createProfessional($s, $o, ['name' => 'Bia', 'color' => '#aa0000', 'bio' => '']);
    $link = Invites::create($s, $o, 'Bia@X.com', 'barber', $pro2);
    ok(preg_match('#/convite/([A-Za-z0-9_-]{40,})$#', $link, $m)); $tok = $m[1];
    ok(!str_contains((string)DB::val('SELECT token_hash FROM staff_invites LIMIT 1'), $tok), 'token só como hash');
    ok(Invites::find($tok) !== null); eq(Invites::find('curto'), null);
    $outro = mk_user('outra@x.com'); throws(fn() => Invites::accept($tok, ['id' => $outro, 'email' => 'outra@x.com']), 'wrong_account');
    $bia = mk_user('bia@x.com', 'Bia'); $sid2 = Invites::accept($tok, ['id' => $bia, 'email' => 'bia@x.com']); eq($sid2, $s);
    eq(DB::val('SELECT role FROM shop_members WHERE shop_id = ? AND user_id = ?', [$s, $bia]), 'barber'); eq((int)DB::val('SELECT user_id FROM professionals WHERE id = ?', [$pro2]), $bia);
    throws(fn() => Invites::accept($tok, ['id' => $bia, 'email' => 'bia@x.com']), 'bad_token', 'convite de uso único');
    $ctx = Ctx::load(['id' => $bia, 'email' => 'bia@x.com', 'name' => 'Bia', 'is_admin' => 0], $s);
    eq(Ctx::ownProfessionalIds($ctx), [$pro2], 'atendente só opera a própria agenda'); ok(!Ctx::can($ctx, 'finance.view')); ok(!Ctx::can($ctx, 'settings.manage'));
    throws(fn() => Invites::create($s, $o, 'bia@x.com', 'barber', $pro2), null, 'já tem acesso');
    throws(fn() => Invites::revoke($s, $bia, $bia), 'forbidden'); throws(fn() => Invites::revoke($s, $bia, $o), 'forbidden', 'nunca remove o dono');
    Invites::revoke($s, $o, $bia);
    eq((int)DB::val('SELECT COUNT(*) FROM shop_members WHERE shop_id = ? AND user_id = ?', [$s, $bia]), 0); eq(DB::val('SELECT user_id FROM professionals WHERE id = ?', [$pro2]), null);
    // expirado
    $l2 = Invites::create($s, $o, 'exp@x.com', 'manager', null); preg_match('#/convite/(.+)$#', $l2, $m2);
    DB::exec("UPDATE staff_invites SET expires_at = '2020-01-01 00:00:00' WHERE email = 'exp@x.com'"); eq(Invites::find($m2[1]), null, 'expirado');
    // Solo
    $solo = mk_shop(mk_user('solo-eq@x.com'), 'solo-eq', 'basico'); throws(fn() => Invites::create($solo, 0, 'x@x.com', 'barber', null), 'feature_not_in_plan');
    throws(fn() => Invites::create($s, $o, 'nao-e-email', 'barber', null)); throws(fn() => Invites::create($s, $o, 'a@b.com', 'root', null));
    // convite de outro negócio não vincula profissional alheio
    $s3 = mk_shop(mk_user('dono3@x.com'), 'outro-neg', 'profissional'); throws(fn() => Invites::create($s3, 0, 'z@z.com', 'barber', $pro2), 'not_found');
});
t('admin: salvar SMTP/Asaas/WhatsApp valida entradas, guarda cifrado e preserva segredo em branco', function () {
    $adm = mk_user('adm-int@x.com', 'Adm', true);
    throws(fn() => Admin::saveSmtp($adm, ['host' => 'smtp.x.com', 'port' => 99999, 'security' => 'tls', 'from_email' => 'a@b.com']));
    Admin::saveSmtp($adm, ['host' => 'smtp.x.com', 'port' => 587, 'security' => 'tls', 'user' => 'u', 'pass' => 'segredo1', 'from_email' => 'a@b.com']);
    Admin::saveSmtp($adm, ['host' => 'smtp.x.com', 'port' => 587, 'security' => 'tls', 'user' => 'u', 'pass' => '', 'from_email' => 'a@b.com']);
    eq(Mailer::settings()['pass'], 'segredo1', 'senha em branco mantém');
    Admin::saveSmtp($adm, ['host' => '']); ok(!Mailer::configured());
    throws(fn() => Admin::saveAsaas($adm, ['api_key' => 'abc', 'env' => 'sandbox']));
    throws(fn() => Admin::saveAsaas($adm, ['api_key' => '$aact_prod_AAAAAAAAAAAAAAAA', 'env' => 'sandbox']), null, 'chave de produção em ambiente de teste');
    throws(fn() => Admin::saveAsaas($adm, ['api_key' => '$aact_hmlg_AAAAAAAAAAAAAAAA', 'env' => 'production']), null, 'chave de teste em produção');
    Admin::saveAsaas($adm, ['api_key' => '$aact_hmlg_AAAAAAAAAAAAAAAA', 'env' => 'sandbox']); ok(Asaas::configured()); eq(Asaas::env(), 'sandbox');
    Admin::saveAsaas($adm, ['clear' => 1]); ok(!Asaas::configured());
    throws(fn() => Admin::saveWhatsApp($adm, ['token' => 'curto', 'phone_number_id' => '123']));
    $ok = ['token' => str_repeat('A', 30), 'phone_number_id' => '109999999999', 'api_version' => 'v21.0', 'lang' => 'pt_BR'] + array_combine(array_map(fn($k) => 'tpl_' . $k, WhatsApp::KINDS), array_map(fn($k) => 'agenda_' . $k, WhatsApp::KINDS));
    Admin::saveWhatsApp($adm, $ok); ok(WhatsApp::configured()); throws(fn() => Admin::saveWhatsApp($adm, ['tpl_lembrete' => 'Nome Inválido'] + $ok), null, 'nome de modelo inválido');
    Admin::saveWhatsApp($adm, ['clear' => 1]); ok(!WhatsApp::configured());
    $aud = (int)DB::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin.%saved'"); ok($aud >= 3, 'auditado');
    ok(!str_contains(json_encode(DB::all('SELECT detail FROM audit_logs')), 'segredo1'), 'segredo fora da auditoria');
});
t('termos e privacidade: mostram razão social/contato configurados e só citam prestadores realmente ligados', function () {
    $adm = mk_user('adm-legal@x.com', 'Adm', true);
    throws(fn() => Admin::saveSystem($adm, 'Agendô', '', 'Empresa X', 'nao-e-email'));
    Admin::saveSystem($adm, 'Agendô', 'Pix 123', 'Empresa Teste Ltda — CNPJ 11.222.333/0001-81', 'privacidade@exemplo.com');
    $out = function (string $v) { ob_start(); include BF_ROOT . "/views/$v.php"; return ob_get_clean(); };
    $priv = $out('privacy'); $terms = $out('terms');
    ok(str_contains($priv, 'privacidade@exemplo.com') && str_contains($terms, 'Empresa Teste Ltda'), 'dados do operador');
    ok(!str_contains($priv, 'Asaas (recebe') && !str_contains($priv, 'Meta (WhatsApp'), 'sem prestador não configurado');
    Secrets::put('asaas', ['api_key' => '$aact_hmlg_AAAAAAAAAAAAAAAA', 'env' => 'sandbox']);
    ok(str_contains($out('privacy'), 'Asaas (recebe'), 'cita Asaas quando ligado'); Secrets::put('asaas', []);
    ok(str_contains($terms, Billing::GRACE_DAYS . ' dias') && str_contains($terms, Billing::RESTRICTED_DAYS . ' dias') && str_contains($terms, 'Nenhum dado é apagado'), 'regra de carência vem do código');
});
done();
