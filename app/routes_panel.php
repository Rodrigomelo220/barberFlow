<?php
declare(strict_types=1);

/** Contexto do painel. $needFull: exige acesso completo para abrir a página (a maioria das telas é só leitura aceitável em "restricted"). */
function panel(bool $allowBlocked = false): array
{
    $u = Auth::user();
    if (!$u) redirect('/entrar?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/painel'));
    $memberships = Ctx::memberships((int)$u['id']);
    if (!$memberships) redirect((int)$u['is_admin'] ? '/admin' : '/nova-barbearia');
    $ctx = Ctx::load($u, isset($_SESSION['shop_id']) ? (int)$_SESSION['shop_id'] : null);
    $_SESSION['shop_id'] = $ctx['shop_id'];
    if (!$allowBlocked && $ctx['billing']['access']['level'] === 'blocked') redirect('/painel/assinatura');
    return $ctx;
}
function pact(array $ctx, string $perm, string $back, callable $fn, bool $writeOnly = true): never
{
    act($back, function () use ($ctx, $perm, $fn, $writeOnly) {
        if (!Ctx::can($ctx, $perm)) throw new BusinessError('Você não tem permissão para isso.', 'forbidden', 403);
        if ($writeOnly) Ctx::needWritable($ctx);
        return $fn();
    });
}
function local_range(string $tz, string $date): array
{
    $z = new DateTimeZone($tz);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $z);
    if (!$d || $d->format('Y-m-d') !== $date) $d = now_utc()->setTimezone($z)->setTime(0, 0);
    return [$d, $d->modify('+1 day')];
}
function appt_for(array $ctx, int $id): array
{
    $a = DB::one("SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, p.name AS professional_name FROM appointments a JOIN customers c ON c.id = a.customer_id JOIN professionals p ON p.id = a.professional_id WHERE a.id = ? AND a.shop_id = ?", [$id, $ctx['shop_id']]);
    if (!$a) render_error(404, 'Agendamento não encontrado', 'Ele pode ter sido removido.');
    $own = Ctx::ownProfessionalIds($ctx);
    if ($own !== null && !in_array((int)$a['professional_id'], $own, true)) render_error(403, 'Sem permissão', 'Este agendamento é de outro profissional.');
    return $a;
}
function ensure_own_appt(array $ctx, int $id): void { appt_for($ctx, $id); }
function parse_local_ts(string $tz, string $date, string $time): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", new DateTimeZone($tz));
    return $d && $d->format('Y-m-d H:i') === "$date $time" ? $d : null;
}

function register_panel_routes(Router $r): void
{
    $r->post('/painel/trocar', function () {
        $ctx = panel(true);
        csrf_check();
        foreach ($ctx['memberships'] as $m) if ((int)$m['id'] === input_int('shop_id')) $_SESSION['shop_id'] = (int)$m['id'];
        redirect('/painel');
    });

    $r->get('/painel', function () {
        $ctx = panel();
        render('panel/home', ['ctx' => $ctx, 'd' => Shops::dashboard($ctx['shop_id'], $ctx['tz']), 'title' => 'Início']);
    });

    // ------------------------------------------------------------ agenda
    $r->get('/painel/agenda', function () {
        $ctx = panel();
        [$d0, $d1] = local_range($ctx['tz'], input('data'));
        $pros = DB::all('SELECT id, name, color FROM professionals WHERE shop_id = ? AND active = 1 ORDER BY sort_order, id', [$ctx['shop_id']]);
        $own = Ctx::ownProfessionalIds($ctx);
        $proFilter = input_int('prof') ?: null;
        $rows = Shops::appointmentsBetween($ctx['shop_id'], $d0, $d1, $proFilter);
        $blocks = DB::all('SELECT b.id, b.starts_at, b.ends_at, b.kind, b.reason, b.professional_id, p.name AS professional FROM schedule_blocks b LEFT JOIN professionals p ON p.id = b.professional_id WHERE b.shop_id = ? AND b.starts_at < ? AND b.ends_at > ? ORDER BY b.starts_at', [$ctx['shop_id'], dt_db($d1), dt_db($d0)]);
        $wh = DB::all('SELECT professional_id, start_time, end_time FROM working_hours WHERE shop_id = ? AND weekday = ?', [$ctx['shop_id'], (int)$d0->format('N')]);
        render('panel/agenda', ['wh' => $wh, 'ctx' => $ctx, 'day' => $d0, 'rows' => $rows, 'blocks' => $blocks, 'pros' => $pros, 'own' => $own, 'prof' => $proFilter, 'title' => 'Agenda']);
    });

    $r->get('/painel/agenda/novo', function () {
        $ctx = panel();
        $sv = DB::all('SELECT id, name, price_cents, duration_min FROM services WHERE shop_id = ? AND active = 1 ORDER BY name', [$ctx['shop_id']]);
        $pros = DB::all('SELECT id, name FROM professionals WHERE shop_id = ? AND active = 1 ORDER BY sort_order, id', [$ctx['shop_id']]);
        $own = Ctx::ownProfessionalIds($ctx);
        if ($own !== null) $pros = array_values(array_filter($pros, fn($p) => in_array((int)$p['id'], $own, true)));
        render('panel/agenda_new', ['ctx' => $ctx, 'services' => $sv, 'pros' => $pros, 'cust' => input_int('cliente') ? DB::one('SELECT id, name, phone FROM customers WHERE id = ? AND shop_id = ? AND anonymized_at IS NULL', [input_int('cliente'), $ctx['shop_id']]) : null, 'title' => 'Novo agendamento']);
    });
    $r->get('/painel/api/slots', function () {
        $ctx = panel();
        $ids = array_filter(array_map('intval', explode(',', input('services'))));
        $pro = input('pro', 'any');
        $own = Ctx::ownProfessionalIds($ctx);
        if ($own !== null && ($pro === 'any' || !in_array((int)$pro, $own, true))) json_out(['slots' => []]);
        try { json_out(['slots' => Scheduling::slots($ctx['shop_id'], $ids, $pro === 'any' ? 'any' : (int)$pro, input('date'), false, input_int('exclude') ?: null)]); }
        catch (BusinessError $e) { json_out(['slots' => [], 'message' => $e->getMessage()]); }
    });
    $r->post('/painel/agenda/novo', function () {
        $ctx = panel();
        $back = '/painel/agenda/novo';
        csrf_check();
        try {
            if (!Ctx::can($ctx, 'agenda.manage_own')) throw new BusinessError('Sem permissão.', 'forbidden', 403);
            Ctx::needWritable($ctx);
            $own = Ctx::ownProfessionalIds($ctx);
            $pro = input('pro', 'any');
            if ($own !== null && ($pro === 'any' || !in_array((int)$pro, $own, true))) throw new BusinessError('Você só agenda para o seu próprio calendário.', 'forbidden', 403);
            $outside = input_bool('outside') && $pro !== 'any';
            $ts = $outside ? parse_local_ts($ctx['tz'], input('date'), input('time')) : (input_int('ts') ? new DateTimeImmutable('@' . input_int('ts')) : null);
            if (!$ts) throw new BusinessError('Escolha o horário.');
            $b = Scheduling::book(['shop_id' => $ctx['shop_id'], 'service_ids' => array_map('intval', (array)($_POST['services'] ?? [])), 'professional_id' => $pro === 'any' ? 'any' : (int)$pro,
                'starts_at' => $ts, 'name' => input('name'), 'phone' => input('phone'), 'notes' => input('notes'), 'whatsapp_consent' => input_bool('whatsapp_consent'),
                'source' => 'manual', 'created_by' => (int)$ctx['user']['id'], 'allow_outside_hours' => $outside]);
            flash('ok', 'Agendamento criado.');
            unset($_SESSION['old']);
            redirect('/painel/agendamentos/' . $b['appointment_id']);
        } catch (BusinessError $e) { flash('err', $e->getMessage()); $_SESSION['old'] = $_POST; redirect($back); }
    });

    $r->get('/painel/agendamentos/{id}', function ($id) {
        $ctx = panel();
        $a = appt_for($ctx, (int)$id);
        $svc = DB::all('SELECT service_id, name, price_cents, duration_min FROM appointment_services WHERE appointment_id = ? ORDER BY position', [$a['id']]);
        $pay = DB::all('SELECT id, kind, refund_of, amount_cents, method, status, received_at, created_at, notes FROM shop_payments WHERE appointment_id = ? AND shop_id = ? ORDER BY id', [$a['id'], $ctx['shop_id']]);
        $paid = Finance::paidSoFar((int)$a['id']);
        $pending = (int)DB::val("SELECT COALESCE(SUM(amount_cents),0) FROM shop_payments WHERE appointment_id = ? AND kind = 'payment' AND status = 'pending'", [$a['id']]);
        render('panel/appointment', ['ctx' => $ctx, 'a' => $a, 'svc' => $svc, 'pay' => $pay, 'paid' => $paid, 'pending' => $pending, 'title' => 'Atendimento']);
    });
    $r->post('/painel/agendamentos/{id}/resultado', function ($id) {
        $ctx = panel(); $back = '/painel/agendamentos/' . (int)$id;
        pact($ctx, 'agenda.manage_own', $back, function () use ($ctx, $id) {
            ensure_own_appt($ctx, (int)$id);
            Scheduling::setOutcome($ctx['shop_id'], (int)$id, input('outcome'), (int)$ctx['user']['id']);
            return input('outcome') === 'completed' ? 'Atendimento concluído. O pagamento ainda não foi lançado.' : 'Marcado como falta.';
        });
    });
    $r->post('/painel/agendamentos/{id}/cancelar', function ($id) {
        $ctx = panel(); $back = '/painel/agendamentos/' . (int)$id;
        pact($ctx, 'agenda.manage_own', $back, function () use ($ctx, $id) {
            ensure_own_appt($ctx, (int)$id);
            Scheduling::cancel($ctx['shop_id'], (int)$id, 'staff', (int)$ctx['user']['id'], input('reason'));
            return 'Agendamento cancelado.';
        });
    });
    $r->post('/painel/agendamentos/{id}/remarcar', function ($id) {
        $ctx = panel(); $back = '/painel/agendamentos/' . (int)$id;
        pact($ctx, 'agenda.manage_own', $back, function () use ($ctx, $id) {
            ensure_own_appt($ctx, (int)$id);
            Scheduling::reschedule($ctx['shop_id'], (int)$id, new DateTimeImmutable('@' . input_int('ts')), 'staff', (int)$ctx['user']['id']);
            return 'Horário remarcado.';
        });
    });
    $r->post('/painel/agendamentos/{id}/pagamento', function ($id) {
        $ctx = panel(); $back = '/painel/agendamentos/' . (int)$id;
        pact($ctx, 'payments.record', $back, function () use ($ctx, $id) {
            $amount = input('amount') === '' ? null : reais_to_cents(input('amount'));
            if (input('amount') !== '' && $amount === null) throw new BusinessError('Valor inválido.');
            $res = Finance::recordPayment($ctx['shop_id'], (int)$ctx['user']['id'], Ctx::ownProfessionalIds($ctx), (int)$id, input('method'), input('status', 'received'), $amount, input('notes'));
            return 'Pagamento lançado. Falta receber: ' . money($res['remaining_cents']) . '.';
        });
    });
    $r->post('/painel/pagamentos/{id}/{acao}', function ($id, $acao) {
        $ctx = panel();
        $back = input('voltar') !== '' ? safe_next(input('voltar'), '/painel/financeiro') : '/painel/financeiro';
        pact($ctx, 'payments.record', $back, function () use ($ctx, $id, $acao) {
            $own = Ctx::ownProfessionalIds($ctx);
            if ($acao === 'recebido') { Finance::markReceived($ctx['shop_id'], (int)$ctx['user']['id'], $own, (int)$id); return 'Marcado como recebido.'; }
            if (!Ctx::can($ctx, 'finance.manage')) throw new BusinessError('Somente quem gerencia o financeiro pode fazer isso.', 'forbidden', 403);
            if ($acao === 'descartar') { Finance::discardPending($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id); return 'Lançamento pendente descartado.'; }
            if ($acao === 'estorno') {
                $amt = input('amount') === '' ? null : reais_to_cents(input('amount'));
                Finance::refund($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id, $amt, input('notes'));
                return 'Estorno registrado.';
            }
            throw new BusinessError('Ação inválida.');
        });
    });

    // ------------------------------------------------------------ bloqueios
    $r->get('/painel/bloqueios', function () {
        $ctx = panel(); Ctx::need($ctx, 'agenda.manage');
        $rows = DB::all('SELECT b.id, b.starts_at, b.ends_at, b.kind, b.reason, p.name AS professional FROM schedule_blocks b LEFT JOIN professionals p ON p.id = b.professional_id WHERE b.shop_id = ? AND b.ends_at > ? ORDER BY b.starts_at LIMIT 100', [$ctx['shop_id'], dt_db(now_utc())]);
        render('panel/blocks', ['ctx' => $ctx, 'rows' => $rows, 'pros' => DB::all('SELECT id, name FROM professionals WHERE shop_id = ? AND active = 1', [$ctx['shop_id']]), 'title' => 'Bloqueios']);
    });
    $r->post('/painel/bloqueios', function () {
        $ctx = panel();
        pact($ctx, 'agenda.manage', '/painel/bloqueios', function () use ($ctx) {
            $from = parse_local_ts($ctx['tz'], input('from_date'), input('from_time') ?: '00:00');
            $to = parse_local_ts($ctx['tz'], input('to_date') ?: input('from_date'), input('to_time') ?: '23:59');
            if (!$from || !$to) throw new BusinessError('Informe datas e horas válidas.');
            $res = Shops::createBlock($ctx['shop_id'], (int)$ctx['user']['id'], input_int('prof') ?: null, $from, $to, input('kind', 'other'), input('reason'));
            return $res['conflicts'] > 0 ? "Bloqueio criado. Atenção: {$res['conflicts']} agendamento(s) já existem nesse período e não foram alterados." : 'Bloqueio criado.';
        });
    });
    $r->post('/painel/bloqueios/{id}/excluir', function ($id) {
        $ctx = panel();
        pact($ctx, 'agenda.manage', '/painel/bloqueios', function () use ($ctx, $id) { Shops::deleteBlock($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id); return 'Bloqueio removido.'; });
    });

    // ------------------------------------------------------------ clientes
    $r->get('/painel/clientes', function () {
        $ctx = panel(); Ctx::need($ctx, 'customers.view');
        $page = max(1, input_int('p', 1));
        render('panel/customers', ['ctx' => $ctx, 'rows' => Shops::listCustomers($ctx['shop_id'], input('q'), 31, ($page - 1) * 30), 'q' => input('q'), 'page' => $page, 'title' => 'Clientes']);
    });
    $r->post('/painel/clientes', function () {
        $ctx = panel();
        pact($ctx, 'customers.manage', '/painel/clientes', function () use ($ctx) {
            $id = Shops::createCustomer($ctx['shop_id'], (int)$ctx['user']['id'], input('name'), input('phone'), input('notes'), input('preferences'));
            return ['Cliente cadastrado.', '/painel/clientes/' . $id];
        });
    });
    $r->get('/painel/clientes/{id}', function ($id) {
        $ctx = panel(); Ctx::need($ctx, 'customers.view');
        $h = Shops::customerHistory($ctx['shop_id'], (int)$id) ?? render_error(404, 'Cliente não encontrado', 'Ele pode ter sido anonimizado.');
        render('panel/customer', ['ctx' => $ctx, 'h' => $h, 'title' => $h['customer']['name']]);
    });
    $r->post('/painel/clientes/{id}', function ($id) {
        $ctx = panel();
        pact($ctx, 'customers.manage', '/painel/clientes/' . (int)$id, function () use ($ctx, $id) {
            Shops::updateCustomer($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id, input('name'), input('phone'), input('notes'), input('preferences'));
            return 'Cliente atualizado.';
        });
    });
    $r->get('/painel/clientes/{id}/exportar', function ($id) {
        $ctx = panel(); Ctx::need($ctx, 'customers.manage');
        $data = Shops::exportCustomer($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cliente-' . (int)$id . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    });
    $r->post('/painel/clientes/{id}/anonimizar', function ($id) {
        $ctx = panel();
        pact($ctx, 'customers.manage', '/painel/clientes/' . (int)$id, function () use ($ctx, $id) {
            if (!Ctx::can($ctx, 'settings.manage')) throw new BusinessError('Somente o dono ou gerente pode anonimizar.', 'forbidden', 403);
            Shops::anonymizeCustomer($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id);
            return ['Dados pessoais do cliente anonimizados. O histórico financeiro foi mantido sem identificação.', '/painel/clientes'];
        });
    });

    // ------------------------------------------------------------ serviços
    $r->get('/painel/servicos', function () {
        $ctx = panel(); Ctx::need($ctx, 'services.manage');
        render('panel/services', ['ctx' => $ctx, 'rows' => DB::all('SELECT * FROM services WHERE shop_id = ? ORDER BY active DESC, name', [$ctx['shop_id']]), 'title' => 'Serviços']);
    });
    $svcData = function (): array {
        $price = reais_to_cents(input('price'));
        return ['name' => input('name'), 'description' => input('description'), 'price_cents' => $price ?? -1, 'duration_min' => input_int('duration', 0), 'buffer_before_min' => input_int('buffer_before', 0),
            'buffer_after_min' => input_int('buffer_after', 0), 'active' => input_bool('active'), 'bookable_online' => input_bool('bookable_online')];
    };
    $r->post('/painel/servicos', function () use ($svcData) {
        $ctx = panel();
        pact($ctx, 'services.manage', '/painel/servicos', function () use ($ctx, $svcData) {
            $d = $svcData(); $d['active'] = true;
            Shops::createService($ctx['shop_id'], (int)$ctx['user']['id'], $d);
            return 'Serviço criado.';
        });
    });
    $r->post('/painel/servicos/{id}', function ($id) use ($svcData) {
        $ctx = panel();
        pact($ctx, 'services.manage', '/painel/servicos', function () use ($ctx, $id, $svcData) {
            Shops::updateService($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id, $svcData());
            return 'Serviço atualizado.';
        });
    });

    // ------------------------------------------------------------ equipe
    $r->get('/painel/equipe', function () {
        $ctx = panel(); Ctx::need($ctx, 'professionals.manage');
        render('panel/team', ['ctx' => $ctx, 'rows' => DB::all('SELECT * FROM professionals WHERE shop_id = ? ORDER BY active DESC, sort_order, id', [$ctx['shop_id']]), 'title' => 'Equipe']);
    });
    $proData = fn() => ['name' => input('name'), 'bio' => input('bio'), 'color' => input('color', '#c8963e'), 'commission' => (float)str_replace(',', '.', input('commission', '0') ?: '0'), 'active' => input_bool('active')];
    $r->post('/painel/equipe', function () use ($proData) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe', function () use ($ctx, $proData) {
            $d = $proData(); $d['active'] = true;
            $id = Shops::createProfessional($ctx['shop_id'], (int)$ctx['user']['id'], $d);
            Shops::setHours($ctx['shop_id'], $id, array_map(fn($w) => ['weekday' => $w, 'start' => '09:00', 'end' => '18:00'], [1, 2, 3, 4, 5, 6]));
            $all = array_column(DB::all('SELECT id FROM services WHERE shop_id = ? AND active = 1', [$ctx['shop_id']]), 'id');
            Shops::setProfessionalServices($ctx['shop_id'], $id, $all);
            return ['Profissional criado com horário padrão (seg–sáb, 9h–18h). Ajuste abaixo.', '/painel/equipe/' . $id];
        });
    });
    $r->get('/painel/equipe/{id}', function ($id) {
        $ctx = panel(); Ctx::need($ctx, 'professionals.manage');
        $p = DB::one('SELECT * FROM professionals WHERE id = ? AND shop_id = ?', [(int)$id, $ctx['shop_id']]) ?? render_error(404, 'Profissional não encontrado', 'Volte à lista da equipe.');
        $hours = DB::all('SELECT weekday, TIME_FORMAT(start_time, "%H:%i") AS s, TIME_FORMAT(end_time, "%H:%i") AS e FROM working_hours WHERE professional_id = ? ORDER BY weekday, start_time', [$p['id']]);
        $svc = DB::all('SELECT s.id, s.name, s.active, (SELECT 1 FROM professional_services ps WHERE ps.professional_id = ? AND ps.service_id = s.id) AS enabled FROM services s WHERE s.shop_id = ? ORDER BY s.name', [$p['id'], $ctx['shop_id']]);
        render('panel/professional', ['ctx' => $ctx, 'p' => $p, 'hours' => $hours, 'svc' => $svc, 'title' => $p['name']]);
    });
    $r->post('/painel/equipe/{id}', function ($id) use ($proData) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe/' . (int)$id, function () use ($ctx, $id, $proData) {
            Shops::updateProfessional($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id, $proData());
            return 'Profissional atualizado.';
        });
    });
    $r->post('/painel/equipe/{id}/convite', function ($id) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe/' . (int)$id, function () use ($ctx, $id) {
            $link = Invites::create($ctx['shop_id'], (int)$ctx['user']['id'], input('email'), input('role') ?: 'barber', (int)$id);
            return 'Convite criado' . (Mailer::configured() ? ' e enviado por e-mail' : '') . '. Link (válido por 7 dias): ' . $link;
        });
    });
    $r->post('/painel/convites/{id}/cancelar', function ($id) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe', function () use ($ctx, $id) { Invites::cancel($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id); return 'Convite cancelado.'; });
    });
    $r->post('/painel/acessos/{uid}/remover', function ($uid) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe', function () use ($ctx, $uid) { Invites::revoke($ctx['shop_id'], (int)$ctx['user']['id'], (int)$uid); return 'Acesso removido.'; });
    });
    $r->post('/painel/equipe/{id}/horarios', function ($id) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe/' . (int)$id, function () use ($ctx, $id) {
            $rows = [];
            foreach (WEEKDAYS as $wd => $_) {
                for ($i = 0; $i < 2; $i++) {
                    $s = trim((string)($_POST["s_{$wd}_$i"] ?? '')); $e = trim((string)($_POST["e_{$wd}_$i"] ?? ''));
                    if ($s === '' && $e === '') continue;
                    if ($s === '' || $e === '') throw new BusinessError('Preencha início e fim do expediente (' . WEEKDAYS[$wd] . ').');
                    $rows[] = ['weekday' => $wd, 'start' => $s, 'end' => $e];
                }
            }
            Shops::setHours($ctx['shop_id'], (int)$id, $rows);
            return 'Horários salvos.';
        });
    });
    $r->post('/painel/equipe/{id}/servicos', function ($id) {
        $ctx = panel();
        pact($ctx, 'professionals.manage', '/painel/equipe/' . (int)$id, function () use ($ctx, $id) {
            Shops::setProfessionalServices($ctx['shop_id'], (int)$id, (array)($_POST['services'] ?? []));
            return 'Serviços do profissional salvos.';
        });
    });

    // ------------------------------------------------------------ financeiro
    $r->get('/painel/financeiro', function () {
        $ctx = panel(); Ctx::need($ctx, 'finance.view');
        $p = Finance::period($ctx['tz'], input('de'), input('ate'));
        render('panel/finance', ['ctx' => $ctx, 'p' => $p, 'sum' => Finance::summary($ctx['shop_id'], $p, $ctx['tz']), 'comm' => Finance::commissions($ctx['shop_id'], $p),
            'pays' => Finance::payments($ctx['shop_id'], $p), 'exps' => Finance::expenses($ctx['shop_id'], $p), 'title' => 'Financeiro']);
    });
    $r->get('/painel/financeiro/exportar', function () {
        $ctx = panel(); Ctx::need($ctx, 'finance.view');
        $p = Finance::period($ctx['tz'], input('de'), input('ate'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="financeiro-' . $p['from_date'] . '_' . $p['to_date'] . '.csv"');
        echo Finance::paymentsCsv($ctx['shop_id'], $p, $ctx['tz']);
        exit;
    });
    $r->post('/painel/despesas', function () {
        $ctx = panel();
        pact($ctx, 'finance.manage', '/painel/financeiro', function () use ($ctx) {
            $c = reais_to_cents(input('amount'));
            if ($c === null) throw new BusinessError('Valor inválido.');
            Finance::addExpense($ctx['shop_id'], (int)$ctx['user']['id'], input('description'), input('category'), $c, input('method') ?: null, input('paid_on') ?: now_utc()->setTimezone(new DateTimeZone($ctx['tz']))->format('Y-m-d'));
            return 'Despesa lançada.';
        });
    });
    $r->post('/painel/despesas/{id}/excluir', function ($id) {
        $ctx = panel();
        pact($ctx, 'finance.manage', '/painel/financeiro', function () use ($ctx, $id) { Finance::deleteExpense($ctx['shop_id'], (int)$ctx['user']['id'], (int)$id); return 'Despesa excluída.'; });
    });

    // ------------------------------------------------------------ mensagens (simuladas)
    $r->get('/painel/mensagens', function () {
        $ctx = panel(); Ctx::need($ctx, 'messages.view');
        $rows = DB::all("SELECT o.id, o.kind, o.phone, o.body, o.status, o.send_at, o.detail FROM outbox o WHERE o.shop_id = ? ORDER BY (o.status = 'manual') DESC, o.id DESC LIMIT 100", [$ctx['shop_id']]);
        $used = (int)DB::val("SELECT COALESCE(SUM(cnt),0) FROM usage_counters WHERE shop_id = ? AND metric = 'messages' AND period = ?", [$ctx['shop_id'], now_utc()->setTimezone(new DateTimeZone($ctx['tz']))->format('Y-m')]);
        render('panel/messages', ['ctx' => $ctx, 'rows' => $rows, 'used' => $used, 'demo' => cfg('app.mode', 'demo') === 'demo', 'api' => WhatsApp::configured(), 'title' => 'Mensagens']);
    });
    $r->post('/painel/mensagens/{id}/enviada', function ($id) {
        $ctx = panel();
        pact($ctx, 'messages.view', '/painel/mensagens', function () use ($ctx, $id) {
            $n = DB::exec("UPDATE outbox SET status = 'sent', sent_at = ?, detail = 'enviada manualmente' WHERE id = ? AND shop_id = ? AND status IN ('manual','queued','simulated')", [dt_db(now_utc()), (int)$id, $ctx['shop_id']]);
            if (!$n) throw new BusinessError('Mensagem não encontrada.', 'not_found', 404);
            return 'Marcada como enviada.';
        });
    });

    // ------------------------------------------------------------ configurações
    $r->get('/painel/configuracoes', function () {
        $ctx = panel(); Ctx::need($ctx, 'settings.manage');
        render('panel/settings', ['ctx' => $ctx, 'title' => 'Configurações']);
    });
    $r->post('/painel/configuracoes', function () {
        $ctx = panel();
        pact($ctx, 'settings.manage', '/painel/configuracoes', function () use ($ctx) {
            Shops::updateSettings($ctx['shop_id'], (int)$ctx['user']['id'], ['name' => input('name'), 'business_type' => input('business_type'), 'description' => input('description'), 'phone' => input('phone'), 'address' => input('address'),
                'slot_grid_minutes' => input_int('slot_grid_minutes', 15), 'min_notice_minutes' => input_int('min_notice_minutes', 60), 'max_days_ahead' => input_int('max_days_ahead', 30),
                'allow_any_professional' => input_bool('allow_any_professional'), 'allow_online_cancel' => input_bool('allow_online_cancel'), 'cancel_min_notice_minutes' => input_int('cancel_min_notice_minutes', 120),
                'reminders_enabled' => input_bool('reminders_enabled'), 'reminder_hours' => input('reminder_hours', '24,2')]);
            return 'Configurações salvas.';
        });
    });

    $r->post('/painel/configuracoes/imagem', function () {
        $ctx = panel();
        pact($ctx, 'settings.manage', '/painel/configuracoes', function () use ($ctx) {
            $kind = input('kind');
            if (input_bool('remove')) { Shops::removeImage($ctx['shop_id'], (int)$ctx['user']['id'], $kind); return 'Imagem removida.'; }
            Shops::saveImage($ctx['shop_id'], (int)$ctx['user']['id'], $kind, $_FILES['image'] ?? []);
            return $kind === 'logo' ? 'Logo atualizada.' : 'Capa atualizada.';
        });
    });

    $r->post('/painel/configuracoes/exemplo', function () {
        $ctx = panel();
        pact($ctx, 'settings.manage', '/painel/configuracoes', function () use ($ctx) {
            if (cfg('app.mode', 'demo') !== 'demo') throw new BusinessError('Dados de exemplo só existem no modo de demonstração.', 'forbidden', 403);
            $r = Shops::seedDemo($ctx['shop_id'], (int)$ctx['user']['id']);
            return [$r['appointments'] ? 'Dados de exemplo criados: ' . $r['appointments'] . ' atendimentos, ' . $r['customers'] . ' clientes fictícios e ' . $r['services'] . ' serviços novos.' : 'Este negócio já tem atendimentos, então não criei novos. Serviços adicionados: ' . $r['services'] . '.', '/painel'];
        });
    });

    // ------------------------------------------------------------ assinatura e checkout simulado
    $r->get('/painel/assinatura', function () {
        $ctx = panel(true);
        $charges = DB::all('SELECT status, amount_cents, due_date, received_at, provider_ref, invoice_url FROM billing_charges WHERE shop_id = ? ORDER BY id DESC LIMIT 12', [$ctx['shop_id']]);
        render('panel/subscription', ['ctx' => $ctx, 'plans' => Billing::publicPlans(), 'charges' => $charges, 'demo' => cfg('app.mode', 'demo') === 'demo', 'asaas' => Asaas::configured() && cfg('app.mode', 'demo') !== 'demo', 'title' => 'Assinatura']);
    });
    $subAct = function (string $path, callable $fn) {
        $ctx = panel(true);
        pact($ctx, 'billing.manage', $path, function () use ($ctx, $fn) { return $fn($ctx); }, false);
    };
    $r->post('/painel/assinatura/iniciar', function () use ($subAct) {
        $subAct('/painel/assinatura', function ($ctx) {
            $res = Billing::start($ctx['shop_id'], (int)$ctx['user']['id'], input('plan'), input_bool('trial'));
            return $res['status'] === 'trialing' ? ['Período de teste iniciado.', '/painel'] : ['Plano escolhido. Veja abaixo como concluir o pagamento.', cfg('app.mode', 'demo') === 'demo' ? '/dev/pagamento' : '/painel/assinatura'];
        });
    });
    // Cobrança real (Asaas): coleta CPF/CNPJ, cria a assinatura recorrente e leva o cliente à fatura. Só o webhook ativa.
    $r->get('/painel/assinatura/assinar', function () {
        $ctx = panel(true);
        if (!Asaas::configured() || cfg('app.mode', 'demo') === 'demo') render_error(404, 'Página não encontrada', 'A cobrança automática não está ativa.');
        $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1 AND is_public = 1', [input('plano')]) ?? render_error(404, 'Plano não encontrado', 'Volte e escolha um plano.');
        render('panel/subscribe', ['ctx' => $ctx, 'plan' => $plan, 'title' => 'Assinar']);
    });
    $r->post('/painel/assinatura/assinar', function () use ($subAct) {
        $subAct('/painel/assinatura', function ($ctx) {
            if (!Asaas::configured() || cfg('app.mode', 'demo') === 'demo') throw new BusinessError('A cobrança automática não está ativa.');
            if (!rate_limit('subscribe:' . $ctx['shop_id'], 6, 3600)) throw new BusinessError('Muitas tentativas. Tente mais tarde.', 'rate_limited', 429);
            Asaas::subscribe($ctx['shop_id'], (int)$ctx['user']['id'], input('plan'), input('name'), (string)$ctx['user']['email'], input('doc'));
            return ['Assinatura criada. Abra a cobrança abaixo para pagar; a ativação é automática assim que o pagamento for confirmado.', '/painel/assinatura'];
        });
    });
    $r->post('/painel/assinatura/plano', function () use ($subAct) {
        $subAct('/painel/assinatura', function ($ctx) { Billing::changePlan($ctx['shop_id'], (int)$ctx['user']['id'], input('plan')); return 'Plano alterado.'; });
    });
    $r->post('/painel/assinatura/cancelar', function () use ($subAct) {
        $subAct('/painel/assinatura', function ($ctx) { Billing::cancel($ctx['shop_id'], (int)$ctx['user']['id']); return 'Cancelamento registrado. O acesso continua até o fim do período já pago.'; });
    });
    $r->post('/painel/assinatura/retomar', function () use ($subAct) {
        $subAct('/painel/assinatura', function ($ctx) { Billing::resume($ctx['shop_id'], (int)$ctx['user']['id']); return 'Cancelamento desfeito.'; });
    });

    // Checkout de demonstração: monta um webhook no formato do Asaas e o entrega ao mesmo endpoint de produção.
    $r->get('/dev/pagamento', function () {
        $ctx = panel(true);
        if (cfg('app.mode', 'demo') !== 'demo') render_error(404, 'Página não encontrada', 'O checkout de demonstração está desativado.');
        $ch = DB::one("SELECT c.* FROM billing_charges c WHERE c.shop_id = ? AND c.status IN ('pending','overdue') ORDER BY c.id DESC LIMIT 1", [$ctx['shop_id']]);
        render('panel/checkout', ['ctx' => $ctx, 'charge' => $ch, 'title' => 'Pagamento (simulado)']);
    });
    $r->post('/dev/pagamento', function () {
        $ctx = panel(true);
        if (cfg('app.mode', 'demo') !== 'demo') render_error(404, 'Página não encontrada', 'O checkout de demonstração está desativado.');
        pact($ctx, 'billing.manage', '/dev/pagamento', function () use ($ctx) {
            $sub = $ctx['billing']['sub'] ?? null;
            if (!$sub || empty($sub['provider_ref'])) throw new BusinessError('Escolha um plano antes.');
            $kind = input('simular');
            $event = ['pagar' => 'PAYMENT_RECEIVED', 'atraso' => 'PAYMENT_OVERDUE', 'renovar' => 'PAYMENT_RECEIVED', 'estorno' => 'PAYMENT_CHARGEBACK_REQUESTED'][$kind] ?? null;
            if (!$event) throw new BusinessError('Simulação inválida.');
            $open = DB::one("SELECT * FROM billing_charges WHERE shop_id = ? AND status IN ('pending','overdue') ORDER BY id DESC LIMIT 1", [$ctx['shop_id']]);
            $ref = ($kind === 'pagar' && $open) ? $open['provider_ref'] : 'sim_pay_' . bin2hex(random_bytes(8));
            $res = Billing::simulate($event, $sub['provider_ref'], $ref, (int)$sub['price_cents'], now_utc()->format('Y-m-d'));
            if ($res['status'] !== 200) throw new BusinessError('O webhook foi recusado: ' . ($res['error'] ?? 'erro'));
            return ['Evento simulado entregue ao webhook (' . $event . ').', '/painel/assinatura'];
        }, false);
    });
}
