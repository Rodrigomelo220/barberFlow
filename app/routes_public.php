<?php
declare(strict_types=1);

/** Executa uma ação POST: CSRF, erros de negócio viram aviso e voltam para a página. Retorno do callback: mensagem | [mensagem, destino]. */
function act(string $back, callable $fn): never
{
    csrf_check();
    $to = $back;
    try {
        $r = $fn();
        if (is_array($r)) { flash('ok', (string)$r[0]); $to = $r[1] ?? $back; }
        elseif (is_string($r) && $r !== '') flash('ok', $r);
        unset($_SESSION['old']);
    } catch (BusinessError $e) {
        flash('err', $e->getMessage());
        $_SESSION['old'] = $_POST;
    }
    redirect($to);
}

function safe_next(string $n, string $default): string
{
    return ($n !== '' && $n[0] === '/' && !str_starts_with($n, '//') && !str_contains($n, '\\') && !preg_match('/[\r\n]/', $n)) ? $n : $default;
}

function api_csrf(): void
{
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) json_out(['error' => 'csrf', 'message' => 'Sessão expirada. Atualize a página.'], 419);
}

function public_shop(string $slug): array
{
    $shop = DB::one('SELECT * FROM shops WHERE slug = ?', [mb_strtolower($slug)]);
    if (!$shop) render_error(404, 'Estabelecimento não encontrado', 'Confira o endereço do link.');
    return $shop;
}

function after_login_path(array $user): string
{
    return (int)$user['is_admin'] && !Ctx::memberships((int)$user['id']) ? '/admin' : '/painel';
}

function register_public_routes(Router $r): void
{
    $r->get('/', function () {
        render('landing', ['plans' => Billing::publicPlans(), 'user' => Auth::user(), 'title' => 'Agenda online para barbearias, salões e estúdios']);
    });
    $r->get('/termos', fn() => render('terms', ['title' => 'Termos de uso']));
    $r->get('/privacidade', fn() => render('privacy', ['title' => 'Privacidade e LGPD']));

    // ------------------------------------------------------------ conta
    $r->get('/entrar', function () {
        if ($u = Auth::user()) redirect(after_login_path($u));
        render('login', ['title' => 'Entrar', 'next' => input('next')]);
    });
    $r->post('/entrar', function () {
        csrf_check();
        try {
            $id = Auth::login(input('email'), (string)($_POST['password'] ?? ''), client_ip());
            Auth::startSession($id);
            $u = Auth::user();
            audit(null, $id, 'user.logged_in', 'user', $id);
            redirect(safe_next(input('next'), after_login_path($u)));
        } catch (BusinessError $e) {
            flash('err', $e->getMessage());
            $_SESSION['old'] = ['email' => input('email')];
            redirect('/entrar' . (input('next') !== '' ? '?next=' . rawurlencode(input('next')) : ''));
        }
    });
    $r->post('/sair', function () { csrf_check(); Auth::logout(); redirect('/'); });

    $r->get('/cadastro', function () {
        if (Auth::user()) redirect('/painel');
        render('signup', ['title' => 'Criar conta', 'plan' => input('plano')]);
    });
    $r->post('/cadastro', function () {
        csrf_check();
        try {
            $id = Auth::signup(input('name'), input('email'), (string)($_POST['password'] ?? ''), client_ip());
            Auth::startSession($id);
            redirect('/nova-barbearia' . (input('plano') !== '' ? '?plano=' . rawurlencode(input('plano')) : ''));
        } catch (BusinessError $e) {
            flash('err', $e->getMessage());
            $_SESSION['old'] = ['name' => input('name'), 'email' => input('email')];
            redirect('/cadastro');
        }
    });

    $r->get('/recuperar-senha', fn() => render('forgot', ['title' => 'Recuperar senha']));
    $r->post('/recuperar-senha', function () {
        csrf_check();
        Auth::requestReset(input('email'), client_ip());
        flash('ok', 'Se esse e-mail estiver cadastrado, enviamos o link para criar uma nova senha.');
        redirect('/recuperar-senha');
    });
    $r->get('/redefinir-senha/{token}', fn($token) => render('reset', ['title' => 'Nova senha', 'token' => $token]));
    $r->post('/redefinir-senha/{token}', function ($token) {
        csrf_check();
        try {
            Auth::resetPassword($token, (string)($_POST['password'] ?? ''));
            flash('ok', 'Senha alterada. Entre com a nova senha.');
            redirect('/entrar');
        } catch (BusinessError $e) { flash('err', $e->getMessage()); redirect('/redefinir-senha/' . $token); }
    });

    // ------------------------------------------------------------ criar barbearia
    $r->get('/nova-barbearia', function () {
        $u = Auth::user() ?: redirect('/entrar?next=' . rawurlencode('/nova-barbearia'));
        render('shop_new', ['title' => 'Novo negócio', 'plans' => Billing::publicPlans(), 'plan' => input('plano'), 'user' => $u]);
    });
    $r->post('/nova-barbearia', function () {
        $u = Auth::user() ?: redirect('/entrar');
        act('/nova-barbearia', function () use ($u) {
            $name = input('name');
            $slug = input('slug') !== '' ? input('slug') : Shops::slugify($name);
            $sid = Shops::create((int)$u['id'], $name, $slug, input('phone'), input('plan'), input('business_type'));
            $_SESSION['shop_id'] = $sid;
            return ['Negócio criado. Falta escolher como começar.', '/painel/assinatura'];
        });
    });

    // ------------------------------------------------------------ página pública de agendamento
    $r->get('/b/{slug}', function ($slug) {
        $shop = public_shop($slug);
        $st = Billing::state((int)$shop['id']);
        if ($st['access']['level'] !== 'full') render('book_unavailable', ['shop' => $shop, 'title' => $shop['name']], 'layout_public');
        $services = DB::all('SELECT id, name, description, price_cents, duration_min FROM services WHERE shop_id = ? AND active = 1 AND bookable_online = 1 ORDER BY name', [$shop['id']]);
        $pros = DB::all('SELECT p.id, p.name, p.bio, p.color FROM professionals p WHERE p.shop_id = ? AND p.active = 1 ORDER BY p.sort_order, p.id', [$shop['id']]);
        $map = [];
        foreach (DB::all('SELECT professional_id, service_id FROM professional_services WHERE shop_id = ?', [$shop['id']]) as $m) $map[(int)$m['professional_id']][] = (int)$m['service_id'];
        render('book', ['shop' => $shop, 'services' => $services, 'pros' => $pros, 'map' => $map, 'title' => 'Agendar — ' . $shop['name']], 'layout_public');
    });

    $r->get('/api/slots', function () {
        if (!rate_limit('slots:' . client_ip(), 240, 60)) json_out(['error' => 'rate', 'message' => 'Muitas consultas. Aguarde um instante.'], 429);
        $shop = DB::one('SELECT id FROM shops WHERE slug = ?', [mb_strtolower(input('shop'))]);
        if (!$shop || Billing::state((int)$shop['id'])['access']['level'] !== 'full') json_out(['slots' => []]);
        $ids = array_filter(array_map('intval', explode(',', input('services'))));
        $pro = input('pro', 'any');
        try {
            json_out(['slots' => Scheduling::slots((int)$shop['id'], $ids, $pro === 'any' ? 'any' : (int)$pro, input('date'), true)]);
        } catch (BusinessError $e) { json_out(['slots' => [], 'message' => $e->getMessage()]); }
    });

    $r->post('/api/book', function () {
        api_csrf();
        if (!rate_limit('book:' . client_ip(), 15, 3600)) json_out(['error' => 'rate', 'message' => 'Muitos agendamentos seguidos. Tente mais tarde.'], 429);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) json_out(['error' => 'bad_request', 'message' => 'Requisição inválida.'], 400);
        $shop = DB::one('SELECT * FROM shops WHERE slug = ?', [mb_strtolower((string)($in['shop'] ?? ''))]);
        if (!$shop) json_out(['error' => 'not_found', 'message' => 'Estabelecimento não encontrado.'], 404);
        $st = Billing::state((int)$shop['id']);
        if ($st['access']['level'] !== 'full') json_out(['error' => 'unavailable', 'message' => 'Este estabelecimento não está recebendo agendamentos online agora.'], 409);
        try {
            if (empty($in['consent_terms'])) throw new BusinessError('Para agendar, aceite o uso dos seus dados para este agendamento.');
            $b = Scheduling::book(['shop_id' => (int)$shop['id'], 'service_ids' => array_map('intval', (array)($in['services'] ?? [])), 'professional_id' => ($in['pro'] ?? 'any') === 'any' ? 'any' : (int)$in['pro'],
                'starts_at' => new DateTimeImmutable('@' . (int)($in['ts'] ?? 0)), 'name' => (string)($in['name'] ?? ''), 'phone' => (string)($in['phone'] ?? ''), 'notes' => (string)($in['notes'] ?? ''),
                'whatsapp_consent' => !empty($in['whatsapp_consent']), 'source' => 'link']);
            $tz = $shop['timezone'];
            json_out(['ok' => true, 'manage_url' => abs_url('/agendamento/' . $b['manage_token']), 'manage_path' => url('/agendamento/' . $b['manage_token']),
                'when' => weekday_pt($b['starts_at']->setTimezone(new DateTimeZone($tz))) . ', ' . dt_local(dt_db($b['starts_at']), $tz, 'd/m/Y \à\s H:i'), 'total' => money($b['total_price_cents']),
                'starts_ts' => $b['starts_at']->getTimestamp(), 'ends_ts' => $b['ends_at']->getTimestamp(), 'shop_name' => $shop['name'], 'address' => (string)$shop['address']]);
        } catch (BusinessError $e) {
            json_out(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    });

    // ------------------------------------------------------------ cliente gerencia o próprio agendamento (link secreto)
    $loadByToken = function (string $token): array {
        if (!rate_limit('token:' . client_ip(), 60, 600)) render_error(429, 'Muitas tentativas', 'Aguarde alguns minutos.');
        $a = DB::one('SELECT a.*, c.name AS customer_name, p.name AS professional_name FROM appointments a JOIN customers c ON c.id = a.customer_id JOIN professionals p ON p.id = a.professional_id WHERE a.manage_token_hash = ?', [hash('sha256', $token)]);
        if (!$a) render_error(404, 'Agendamento não encontrado', 'O link está incorreto ou foi substituído.');
        $a['services'] = DB::all('SELECT service_id, name, price_cents, duration_min FROM appointment_services WHERE appointment_id = ? ORDER BY position', [$a['id']]);
        return $a;
    };
    $r->get('/agendamento/{token}', function ($token) use ($loadByToken) {
        $a = $loadByToken($token);
        $shop = DB::one('SELECT * FROM shops WHERE id = ?', [$a['shop_id']]);
        render('manage', ['a' => $a, 'shop' => $shop, 'token' => $token, 'title' => 'Seu agendamento'], 'layout_public');
    });
    $r->get('/api/slots-token', function () use ($loadByToken) {
        $a = $loadByToken(input('token'));
        $ids = array_values(array_filter(array_map(fn($s) => $s['service_id'], $a['services'])));
        if (count($ids) !== count($a['services'])) json_out(['slots' => [], 'message' => 'Um serviço foi removido. Fale com o estabelecimento.']);
        try { json_out(['slots' => Scheduling::slots((int)$a['shop_id'], $ids, (int)$a['professional_id'], input('date'), false, (int)$a['id'])]); }
        catch (BusinessError $e) { json_out(['slots' => [], 'message' => $e->getMessage()]); }
    });
    $r->post('/agendamento/{token}/cancelar', function ($token) use ($loadByToken) {
        csrf_check();
        $a = $loadByToken($token);
        try { Scheduling::cancel((int)$a['shop_id'], (int)$a['id'], 'customer'); flash('ok', 'Agendamento cancelado.'); }
        catch (BusinessError $e) { flash('err', $e->getMessage()); }
        redirect('/agendamento/' . $token);
    });
    $r->post('/agendamento/{token}/remarcar', function ($token) use ($loadByToken) {
        csrf_check();
        $a = $loadByToken($token);
        try {
            Scheduling::reschedule((int)$a['shop_id'], (int)$a['id'], new DateTimeImmutable('@' . input_int('ts')), 'customer');
            flash('ok', 'Horário remarcado.');
        } catch (BusinessError $e) { flash('err', $e->getMessage()); }
        redirect('/agendamento/' . $token);
    });

    // ------------------------------------------------------------ webhook de cobrança (mesmo caminho para provedor real ou simulado)
    $r->post('/webhook/pagamento', function () {
        $raw = (string)file_get_contents('php://input');
        $tok = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null);
        $res = Billing::ingest(is_string($tok) ? $tok : null, $raw);
        json_out(['ok' => $res['status'] === 200, 'accepted' => $res['accepted'], 'duplicates' => $res['duplicates']] + (isset($res['error']) ? ['error' => $res['error']] : []), $res['status']);
    });
}
