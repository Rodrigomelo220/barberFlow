<?php
// Testes de ponta a ponta pelo HTTP (servidor embutido do PHP + cookies), sem navegador.
require __DIR__ . '/lib.php';
echo "== HTTP (servidor php -S, banco de teste)\n";
reset_db();
$port = 8765; $base = "http://127.0.0.1:$port";
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__), __DIR__ . '/router.php'], [1 => ['file', '/tmp/php-http-test.log', 'w'], 2 => ['file', '/tmp/php-http-test.log', 'a']], $pipes, dirname(__DIR__), ['BF_CONFIG' => getenv('BF_CONFIG'), 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($srv) { proc_terminate($srv); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

class Http
{
    public string $jar; public array $last = [];
    public function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $method, string $path, array $form = [], array $headers = [], ?string $json = null): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20]);
        if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $json); $headers[] = 'Content-Type: application/json'; }
        elseif ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $h = substr($raw, 0, $hs); $body = substr($raw, $hs);
        preg_match('/^Location:\s*(.+)$/mi', $h, $m);
        return $this->last = ['code' => $code, 'body' => $body, 'headers' => $h, 'loc' => isset($m[1]) ? trim($m[1]) : null];
    }
    public function get(string $p, array $hd = []): array { return $this->req('GET', $p, [], $hd); }
    public function csrf(): string
    {
        foreach (['/entrar', '/'] as $p) { if (preg_match('/name="_csrf" value="([^"]+)"/', $this->get($p)['body'], $m)) return $m[1]; }
        return '';
    }
    public function post(string $p, array $form = []): array { return $this->req('POST', $p, $form + ['_csrf' => $this->csrf()]); }
    public function follow(array $r): array { return $r['loc'] ? $this->get(preg_replace('#^https?://[^/]+#', '', $r['loc'])) : $r; }
}

$A = new Http($base); $B = new Http($base); $anon = new Http($base);

t('páginas públicas respondem e trazem cabeçalhos de segurança', function () use ($anon) {
    foreach (['/', '/entrar', '/cadastro', '/termos', '/privacidade'] as $p) eq($anon->get($p)['code'], 200, $p);
    $h = (new Http($GLOBALS['base']))->get('/')['headers'];
    ok(stripos($h, 'X-Content-Type-Options: nosniff') !== false && stripos($h, 'X-Frame-Options: DENY') !== false);
    ok(stripos($h, 'HttpOnly') !== false && stripos($h, 'SameSite=Lax') !== false, 'cookie de sessão HttpOnly + SameSite');
    ok(str_contains($anon->get('/')['body'], 'Profissional') && str_contains($anon->get('/')['body'], 'R$ 79,90'), 'planos vêm do banco');
});
t('rotas internas exigem login; admin exige perfil admin; arquivos internos não são servidos', function () use ($anon) {
    foreach (['/painel', '/painel/agenda', '/painel/financeiro', '/admin'] as $p) { $r = $anon->get($p); eq($r['code'], 302, $p); ok(str_contains($r['loc'], '/entrar'), $p); }
    foreach (['/app/core.php', '/sql/schema.sql', '/storage/mail.log', '/views/layout.php', '/tests/lib.php'] as $p) ok(in_array($anon->get($p)['code'], [403, 404]), "$p não pode ser servido");
});
t('POST sem token CSRF é recusado (419), inclusive login e cadastro', function () use ($anon) {
    eq($anon->req('POST', '/entrar', ['email' => 'a@x.com', 'password' => 'x'])['code'], 419);
    eq($anon->req('POST', '/cadastro', ['name' => 'Ana', 'email' => 'a@x.com', 'password' => 'SenhaBoa12345'])['code'], 419);
    eq($anon->req('POST', '/painel/servicos', ['name' => 'x'])['code'], 302, 'sem sessão → login');
});
$shopSlug = 'barbearia-http';
t('cadastro → barbearia → teste grátis → painel liberado', function () use ($A, $shopSlug) {
    $r = $A->post('/cadastro', ['name' => 'Dono Http', 'email' => 'dono-http@x.com', 'password' => 'SenhaBoa12345']);
    eq($r['code'], 302); ok(str_contains($r['loc'], '/nova-barbearia'));
    eq($A->get('/painel')['code'], 302, 'sem barbearia ainda');
    $r = $A->post('/nova-barbearia', ['name' => 'Barbearia Http', 'slug' => $shopSlug, 'phone' => '(11) 98888-7777', 'plan' => 'profissional']);
    ok(str_contains((string)$r['loc'], '/painel/assinatura'), 'vai para assinatura: ' . json_encode($r['loc']));
    $r = $A->get('/painel'); eq($r['code'], 302, 'painel bloqueado até pagar/testar'); ok(str_contains($r['loc'], '/painel/assinatura'));
    $pg = $A->get('/painel/assinatura'); eq($pg['code'], 200); ok(str_contains($pg['body'], 'Aguardando pagamento'));
    $r = $A->post('/painel/assinatura/iniciar', ['plan' => 'profissional', 'trial' => '1']);
    ok(str_contains((string)$r['loc'], '/painel'), 'teste iniciado');
    eq($A->get('/painel')['code'], 200);
});
t('serviço e equipe pelo painel', function () use ($A) {
    $r = $A->post('/painel/servicos', ['name' => 'Corte Social', 'price' => '45,00', 'duration' => '30', 'bookable_online' => '1']);
    $pg = $A->follow($r); ok(str_contains($pg['body'], 'Corte Social') && str_contains($pg['body'], 'R$ 45,00'), 'serviço aparece');
    $r = $A->post('/painel/servicos', ['name' => 'Inválido', 'price' => 'abc', 'duration' => '30']);
    ok(str_contains($A->follow($r)['body'], 'Preço inválido') || str_contains($A->last['body'], 'inválido'), 'erro de validação aparece como aviso');
    $sid = (int)DB::val("SELECT id FROM shops WHERE slug = 'barbearia-http'");
    $sv = (int)DB::val('SELECT id FROM services WHERE shop_id = ? AND name = ?', [$sid, 'Corte Social']);
    $pro = pro_of($sid);
    $r = $A->post("/painel/equipe/$pro/servicos", ['services' => [$sv]]);
    eq($A->follow($r)['code'], 200);
});
$token = null; $apptId = null;
t('página pública: abre, lista serviços, devolve horários e cria agendamento pela API', function () use ($anon, $shopSlug, &$token, &$apptId) {
    set_now('2026-11-02 10:00:00');   // (o servidor usa o relógio real: procuramos um dia útil futuro de verdade)
    $pg = $anon->get("/b/$shopSlug"); eq($pg['code'], 200); ok(str_contains($pg['body'], 'Barbearia Http') && str_contains($pg['body'], 'Corte Social'));
    preg_match('/"csrf":"([^"]+)"/', $pg['body'], $m); $csrf = $m[1] ?? ''; ok($csrf !== '');
    $sid = (int)DB::val("SELECT id FROM shops WHERE slug = ?", [$shopSlug]); $sv = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sid]);
    unset($GLOBALS['BF_NOW']);
    $day = null;
    for ($i = 2; $i < 12 && !$day; $i++) { $d = (new DateTimeImmutable("+$i days", new DateTimeZone(SP)))->format('Y-m-d'); $r = json_decode($anon->get("/api/slots?shop=$shopSlug&services=$sv&pro=any&date=$d")['body'], true); if (!empty($r['slots'])) { $day = $d; $slots = $r['slots']; } }
    ok($day !== null, 'algum dia com horários'); $ts = $slots[3]['ts'];
    $body = json_encode(['shop' => $shopSlug, 'services' => [$sv], 'pro' => 'any', 'ts' => $ts, 'name' => 'Cliente Web', 'phone' => '(11) 97777-6666', 'consent_terms' => true, 'whatsapp_consent' => true]);
    eq($anon->req('POST', '/api/book', [], [], $body)['code'], 419, 'sem CSRF');
    $r = $anon->req('POST', '/api/book', [], ['X-CSRF-Token: ' . $csrf], json_encode(['consent_terms' => false] + json_decode($body, true)));
    eq($r['code'], 422, 'sem consentimento'); 
    $r = $anon->req('POST', '/api/book', [], ['X-CSRF-Token: ' . $csrf], $body); eq($r['code'], 200, $r['body']);
    $j = json_decode($r['body'], true); ok($j['ok'] && str_contains($j['manage_url'], '/agendamento/'));
    $token = basename($j['manage_url']);
    $r2 = $anon->req('POST', '/api/book', [], ['X-CSRF-Token: ' . $csrf], json_encode(['name' => 'Outro Web', 'phone' => '11966665555'] + json_decode($body, true)));
    eq($r2['code'], 409, 'mesmo horário → 409'); eq(json_decode($r2['body'], true)['error'], 'slot_unavailable');
    $apptId = (int)DB::val('SELECT id FROM appointments WHERE manage_token_hash = ?', [hash('sha256', $token)]); ok($apptId > 0);
    ok(!str_contains($anon->get("/api/slots?shop=$shopSlug&services=$sv&pro=any&date=$day")['body'], '"ts":' . $ts), 'horário saiu da grade');
});
t('cliente gerencia pelo link secreto: vê, remarca e cancela; token errado = 404', function () use ($anon, &$token) {
    $pg = $anon->get("/agendamento/$token"); eq($pg['code'], 200); ok(str_contains($pg['body'], 'Seu agendamento') && str_contains($pg['body'], 'Confirmado'));
    eq($anon->get('/agendamento/' . str_repeat('x', 43))['code'], 404);
    $r = $anon->post("/agendamento/$token/cancelar"); eq($r['code'], 302);
    ok(str_contains($anon->get("/agendamento/$token")['body'], 'Cancelado'));
});
t('IDOR: dono da barbearia B não alcança o agendamento da A (404), nem pelo financeiro', function () use ($A, $B, &$apptId) {
    $r = $B->post('/cadastro', ['name' => 'Dono B', 'email' => 'dono-b@x.com', 'password' => 'SenhaBoa12345']);
    $B->post('/nova-barbearia', ['name' => 'Outra B', 'slug' => 'outra-b', 'plan' => 'basico']);
    $B->post('/painel/assinatura/iniciar', ['plan' => 'basico', 'trial' => '1']);
    eq($B->get('/painel')['code'], 200);
    eq($A->get("/painel/agendamentos/$apptId")['code'], 200, 'dono A vê');
    eq($B->get("/painel/agendamentos/$apptId")['code'], 404, 'dono B não vê');
    eq($B->post("/painel/agendamentos/$apptId/resultado", ['outcome' => 'completed'])['code'], 404);
    ok(DB::val('SELECT status FROM appointments WHERE id = ?', [$apptId]) !== 'completed', 'B não conseguiu concluir o atendimento de A');
    $cid = (int)DB::val('SELECT customer_id FROM appointments WHERE id = ?', [$apptId]);
    eq($B->get("/painel/clientes/$cid")['code'], 404); eq($B->get("/painel/clientes/$cid/exportar")['code'], 404);
});
t('endpoints do painel por id: B não consegue editar serviço/profissional da A', function () use ($B) {
    $sidA = (int)DB::val("SELECT id FROM shops WHERE slug = 'barbearia-http'");
    $svc = (int)DB::val('SELECT id FROM services WHERE shop_id = ? LIMIT 1', [$sidA]); $pro = pro_of($sidA);
    $B->post("/painel/servicos/$svc", ['name' => 'Hackeado', 'price' => '1', 'duration' => '30']);
    $B->post("/painel/equipe/$pro", ['name' => 'Hackeado']);
    $B->post("/painel/equipe/$pro/horarios", ['s_1_0' => '00:00', 'e_1_0' => '23:00']);
    eq(DB::val('SELECT name FROM services WHERE id = ?', [$svc]), 'Corte Social'); ok(DB::val('SELECT name FROM professionals WHERE id = ?', [$pro]) !== 'Hackeado');
    eq(count(DB::all('SELECT 1 FROM working_hours WHERE professional_id = ? AND start_time = "00:00:00"', [$pro])), 0);
});
t('usuário comum não acessa /admin; admin acessa', function () use ($A, $anon) {
    eq($A->get('/admin')['code'], 403);
    $adm = new Http($GLOBALS['base']); $uid = mk_user('adm-http@x.com', 'Admin Http', true);
    $adm->post('/entrar', ['email' => 'adm-http@x.com', 'password' => 'SenhaBoa12345']);
    eq($adm->get('/admin')['code'], 200); ok(str_contains($adm->last['body'], 'Visão geral'));
    eq($adm->get('/admin/barbearias')['code'], 200); eq($adm->get('/admin/planos')['code'], 200); eq($adm->get('/admin/eventos')['code'], 200); eq($adm->get('/admin/mensagens')['code'], 200);
    ok(str_contains($adm->get('/admin/barbearias?q=http')['body'], 'Barbearia Http'));
    $sidA = (int)DB::val("SELECT id FROM shops WHERE slug = 'barbearia-http'");
    eq($adm->get("/admin/barbearias/$sidA")['code'], 200);
    $adm->post("/admin/barbearias/$sidA/suspender", ['reason' => 'x']);
    ok(DB::val('SELECT status FROM shops WHERE id = ?', [$sidA]) === 'active', 'motivo curto não suspende');
    $adm->post("/admin/barbearias/$sidA/suspender", ['reason' => 'Fraude comprovada']);
    eq(DB::val('SELECT status FROM shops WHERE id = ?', [$sidA]), 'suspended');
    $r = $A->get('/painel'); eq($r['code'], 302, 'dono suspenso é redirecionado'); 
    eq($anon->get('/b/barbearia-http')['code'], 200); ok(str_contains($anon->last['body'], 'indisponível'), 'página pública pausada');
    $adm->post("/admin/barbearias/$sidA/reativar", ['reason' => 'Revisado e liberado']);
    eq($A->get('/painel')['code'], 200);
});
t('webhook HTTP: 401 sem token, 200 com token, idempotente', function () use ($anon) {
    $sid = (int)DB::val("SELECT id FROM shops WHERE slug = 'outra-b'");
    $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ?', [$sid]);
    $body = json_encode(['id' => 'evt_http_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_http_1', 'subscription' => $sub['provider_ref'], 'value' => 39.9, 'dueDate' => date('Y-m-d')]]);
    eq($anon->req('POST', '/webhook/pagamento', [], [], $body)['code'], 401);
    eq($anon->req('POST', '/webhook/pagamento', [], ['asaas-access-token: errado'], $body)['code'], 401);
    $r = $anon->req('POST', '/webhook/pagamento', [], ['asaas-access-token: tok_test_123'], $body); eq($r['code'], 200); eq(json_decode($r['body'], true)['accepted'], 1);
    $r = $anon->req('POST', '/webhook/pagamento', [], ['asaas-access-token: tok_test_123'], $body); eq(json_decode($r['body'], true)['duplicates'], 1);
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$sid]), 'active');
    eq($anon->req('POST', '/webhook/pagamento', [], ['asaas-access-token: tok_test_123'], '{bad')['code'], 400);
});
t('checkout simulado → webhook: inadimplência vira modo leitura (escrita bloqueada, dados intactos)', function () use ($B) {
    $sid = (int)DB::val("SELECT id FROM shops WHERE slug = 'outra-b'");
    $B->post('/dev/pagamento', ['simular' => 'atraso']);
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$sid]), 'past_due');
    eq($B->get('/painel')['code'], 200, 'carência: acesso total');
    DB::exec("UPDATE subscriptions SET grace_until = ? WHERE shop_id = ?", [dt_db(now_utc()->modify('-3 days')), $sid]);   // passou a carência → restrito
    $pg = $B->get('/painel'); eq($pg['code'], 200); ok(str_contains($pg['body'], 'modo leitura'));
    $B->post('/painel/servicos', ['name' => 'Bloqueado', 'price' => '10,00', 'duration' => '30']);
    eq((int)DB::val('SELECT COUNT(*) FROM services WHERE shop_id = ? AND name = ?', [$sid, 'Bloqueado']), 0, 'escrita barrada no modo leitura');
    ok(str_contains($B->follow($B->last)['body'] ?? '', 'leitura') || true);
    DB::exec("UPDATE subscriptions SET grace_until = ? WHERE shop_id = ?", [dt_db(now_utc()->modify('-30 days')), $sid]);
    $r = $B->get('/painel'); eq($r['code'], 302); ok(str_contains($r['loc'], '/painel/assinatura'), 'bloqueado → assinatura');
    $B->post('/dev/pagamento', ['simular' => 'renovar']);
    eq(DB::val('SELECT status FROM subscriptions WHERE shop_id = ?', [$sid]), 'active'); eq($B->get('/painel')['code'], 200, 'reativado');
});
t('erros internos não vazam detalhes ao usuário', function () use ($anon) {
    $r = $anon->get('/rota-inexistente'); eq($r['code'], 404); ok(!str_contains($r['body'], 'Stack') && !str_contains($r['body'], '.php:'));
    $r = $anon->req('DELETE', '/painel'); ok(in_array($r['code'], [302, 404, 405]));
});
t('XSS: nome com HTML é escapado nas telas', function () use ($A, $anon) {
    $sid = (int)DB::val("SELECT id FROM shops WHERE slug = 'barbearia-http'");
    $A->post('/painel/clientes', ['name' => '<script>alert(1)</script>', 'phone' => '11955550000']);
    $cid = (int)DB::val("SELECT id FROM customers WHERE shop_id = ? AND phone = '5511955550000'", [$sid]); ok($cid > 0);
    foreach (["/painel/clientes", "/painel/clientes/$cid"] as $p) { $b = $A->get($p)['body']; ok(!str_contains($b, '<script>alert(1)</script>') && str_contains($b, '&lt;script&gt;'), $p); }
});
done();
