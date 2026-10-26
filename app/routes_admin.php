<?php
declare(strict_types=1);

function admin_user(): array
{
    $u = Auth::user();
    if (!$u) redirect('/entrar?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin'));
    if (!(int)$u['is_admin']) render_error(403, 'Sem permissão', 'Esta área é restrita à administração da plataforma.');
    return $u;
}
function aact(array $admin, string $back, callable $fn): never { act($back, fn() => $fn($admin)); }

function register_admin_routes(Router $r): void
{
    $r->get('/admin', function () {
        $u = admin_user();
        render('admin/home', ['u' => $u, 'm' => Admin::metrics(), 'title' => 'Administração'], 'layout_admin');
    });
    $r->get('/admin/barbearias', function () {
        $u = admin_user();
        render('admin/shops', ['u' => $u, 'rows' => Admin::shops(input('q'), input('status')), 'q' => input('q'), 'status' => input('status'), 'title' => 'Negócios'], 'layout_admin');
    });
    $r->get('/admin/barbearias/{id}', function ($id) {
        $u = admin_user();
        $d = Admin::shopDetail((int)$id) ?? render_error(404, 'Negócio não encontrado', 'Volte à lista.');
        render('admin/shop', ['u' => $u, 'd' => $d, 'title' => $d['shop']['name']], 'layout_admin');
    });
    $r->post('/admin/barbearias/{id}/{acao}', function ($id, $acao) {
        $u = admin_user();
        aact($u, '/admin/barbearias/' . (int)$id, function ($u) use ($id, $acao) {
            if ($acao === 'suspender') { Admin::suspend((int)$u['id'], (int)$id, input('reason')); return 'Negócio suspenso.'; }
            if ($acao === 'reativar') { Admin::reactivate((int)$u['id'], (int)$id, input('reason')); return 'Negócio reativado.'; }
            if ($acao === 'pagamento') {
                $raw = preg_replace('/[^\d,\.]/', '', input('amount')); if (str_contains($raw, ',')) $raw = str_replace('.', '', $raw);
                $v = (int)round(((float)str_replace(',', '.', $raw)) * 100);
                Admin::recordManualPayment((int)$u['id'], (int)$id, $v, input('note'), input('token')); return 'Pagamento registrado. A assinatura está ativa por mais 1 mês.';
            }
            if ($acao === 'nota') { Admin::addNote((int)$u['id'], (int)$id, input('note')); return 'Nota salva.'; }
            throw new BusinessError('Ação inválida.');
        });
    });
    $r->get('/admin/diagnostico', function () {
        $u = admin_user();
        render('admin/diag', ['u' => $u, 'title' => 'Diagnóstico', 'groups' => Diag::run(false)], 'layout_admin');
    });
    $r->post('/admin/diagnostico', function () {
        $u = admin_user(); csrf_check();
        render('admin/diag', ['u' => $u, 'title' => 'Diagnóstico', 'groups' => Diag::run(true)], 'layout_admin');
    });
    $r->get('/admin/sistema', function () {
        $u = admin_user();
        render('admin/system', ['u' => $u, 'title' => 'Sistema'], 'layout_admin');
    });
    $r->post('/admin/sistema', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) { Admin::saveSystem((int)$u['id'], input('brand'), input('support'), input('entity'), input('privacy_email')); return 'Configurações salvas.'; });
    });
    $r->post('/admin/sistema/email', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) { Admin::saveSmtp((int)$u['id'], $_POST); return Mailer::configured() ? 'E-mail salvo. Envie um e-mail de teste para conferir.' : 'Envio de e-mail desligado.'; });
    });
    $r->post('/admin/sistema/email-teste', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) {
            if (!Mailer::configured()) throw new BusinessError('Configure o e-mail primeiro.');
            if (!rate_limit('mailtest:' . $u['id'], 5, 600)) throw new BusinessError('Muitos testes seguidos. Aguarde alguns minutos.', 'rate_limited', 429);
            $err = Mailer::send((string)$u['email'], 'Teste de e-mail — ' . brand(), "Se você recebeu esta mensagem, o envio de e-mail está funcionando.\n");
            if ($err !== null) throw new BusinessError('Não consegui enviar: ' . $err);
            return 'E-mail de teste enviado para ' . $u['email'] . '. Confira a caixa de entrada e o spam.';
        });
    });
    $r->post('/admin/sistema/asaas', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) { Admin::saveAsaas((int)$u['id'], $_POST); return Asaas::configured() ? 'Chave salva. Use "Testar conexão".' : 'Cobrança automática desligada.'; });
    });
    $r->post('/admin/sistema/asaas-teste', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) {
            Asaas::call('GET', '/customers?limit=1');
            return 'Conexão com o Asaas funcionando (' . (Asaas::env() === 'production' ? 'produção' : 'teste') . ').';
        });
    });
    $r->post('/admin/sistema/whatsapp', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) { Admin::saveWhatsApp((int)$u['id'], $_POST); return WhatsApp::configured() ? 'WhatsApp salvo. Envie uma mensagem de teste.' : 'Envio automático pelo WhatsApp desligado.'; });
    });
    $r->post('/admin/sistema/whatsapp-teste', function () {
        $u = admin_user();
        aact($u, '/admin/sistema', function ($u) {
            if (!WhatsApp::configured()) throw new BusinessError('Configure o WhatsApp primeiro.');
            if (!rate_limit('watest:' . $u['id'], 5, 600)) throw new BusinessError('Muitos testes seguidos. Aguarde alguns minutos.', 'rate_limited', 429);
            $to = preg_replace('/\D/', '', input('to')); if (strlen($to) < 10 || strlen($to) > 13) throw new BusinessError('Informe o número com DDD (ex.: 11999998888).');
            if (strlen($to) <= 11) $to = '55' . $to;
            $err = WhatsApp::send($to, 'confirmacao', ['Teste', brand(), 'hoje às 10:00', 'Serviço de teste']);
            if ($err !== null) throw new BusinessError('A API recusou: ' . $err);
            return 'Mensagem de teste enviada para ' . $to . '.';
        });
    });
    $r->get('/admin/administradores', function () {
        $u = admin_user();
        render('admin/admins', ['u' => $u, 'rows' => Admin::admins(), 'title' => 'Administradores'], 'layout_admin');
    });
    $r->post('/admin/administradores', function () {
        $u = admin_user();
        aact($u, '/admin/administradores', function ($u) { Admin::grantAdmin((int)$u['id'], input('email')); return 'Administrador adicionado.'; });
    });
    $r->post('/admin/administradores/{id}/remover', function ($id) {
        $u = admin_user();
        aact($u, '/admin/administradores', function ($u) use ($id) { Admin::revokeAdmin((int)$u['id'], (int)$id); return 'Acesso de administrador removido.'; });
    });
    $r->get('/admin/planos', function () {
        $u = admin_user();
        render('admin/plans', ['u' => $u, 'plans' => Admin::plans(), 'title' => 'Planos'], 'layout_admin');
    });
    $r->post('/admin/planos', function () {
        $u = admin_user();
        aact($u, '/admin/planos', function ($u) {
            $price = reais_to_cents(input('price'));
            if ($price === null) throw new BusinessError('Preço inválido.');
            Admin::savePlan((int)$u['id'], ['code' => input('code'), 'name' => input('name'), 'description' => input('description'), 'price_cents' => $price, 'trial_days' => input_int('trial_days'),
                'max_professionals' => input('max_professionals'), 'max_appointments_month' => input('max_appointments_month'), 'max_messages_month' => input_int('max_messages_month'),
                'feat_reminders' => input_bool('feat_reminders'), 'feat_commissions' => input_bool('feat_commissions'), 'highlights' => (string)($_POST['highlights'] ?? ''),
                'is_active' => input_bool('is_active'), 'is_public' => input_bool('is_public'), 'sort_order' => input_int('sort_order')]);
            return 'Plano salvo. Quem já assina mantém o preço contratado.';
        });
    });
    $r->get('/admin/eventos', function () {
        $u = admin_user();
        render('admin/events', ['u' => $u, 'rows' => Admin::failedEvents(), 'title' => 'Eventos de cobrança'], 'layout_admin');
    });
    $r->post('/admin/eventos/{id}/reprocessar', function ($id) {
        $u = admin_user();
        aact($u, '/admin/eventos', function ($u) use ($id) { $res = Billing::retry((int)$id); audit(null, (int)$u['id'], 'admin.billing_event_retried', 'billing_event', $id, $res, 'platform_admin'); return "Reprocessado: $res."; });
    });
    $r->get('/admin/mensagens', function () {
        $u = admin_user();
        render('admin/outbox', ['u' => $u, 'rows' => Admin::recentOutbox(), 'title' => 'Mensagens (simuladas)'], 'layout_admin');
    });
}
