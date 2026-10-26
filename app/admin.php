<?php
declare(strict_types=1);

final class Admin
{
    public static function metrics(): array
    {
        $now = dt_db(now_utc());
        $subs = DB::one("SELECT SUM(status='active') AS active, SUM(status='trialing' AND trial_ends_at > ?) AS trialing, SUM(status='past_due') AS past_due, SUM(status='suspended') AS suspended,
              SUM(status='canceled') AS canceled, SUM(status='incomplete') AS incomplete, COALESCE(SUM(CASE WHEN status IN ('active','past_due') THEN price_cents END),0) AS mrr FROM subscriptions", [$now]);
        $rec = DB::one("SELECT COALESCE(SUM(CASE WHEN status='received' AND received_at >= DATE_FORMAT(?, '%Y-%m-01') THEN amount_cents END),0) AS received_month,
              COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount_cents END),0) AS open_cents, COALESCE(SUM(CASE WHEN status='overdue' THEN amount_cents END),0) AS overdue FROM billing_charges", [$now]);
        $g = DB::one('SELECT (SELECT COUNT(*) FROM shops) AS shops, (SELECT COUNT(*) FROM shops WHERE created_at > ? - INTERVAL 30 DAY) AS new_shops,
              (SELECT COUNT(*) FROM appointments WHERE created_at > ? - INTERVAL 30 DAY) AS appts', [$now, $now]);
        $h = DB::one("SELECT (SELECT COUNT(*) FROM billing_events WHERE status = 'failed') AS failed_events, (SELECT COUNT(*) FROM outbox WHERE status = 'queued' AND send_at < ? - INTERVAL 15 MINUTE) AS late_messages", [$now]);
        return ['subs' => array_map('intval', $subs), 'rec' => array_map('intval', $rec), 'growth' => array_map('intval', $g), 'health' => array_map('intval', $h)];
    }

    public static function shops(string $search = '', string $status = ''): array
    {
        $w = '1=1'; $a = [];
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $w .= ' AND (s.name LIKE ? OR s.slug LIKE ? OR EXISTS (SELECT 1 FROM shop_members m JOIN users u ON u.id = m.user_id WHERE m.shop_id = s.id AND u.email LIKE ?))';
            array_push($a, $like, $like, $like);
        }
        if ($status !== '') { $w .= ' AND (sub.status = ? OR s.status = ?)'; array_push($a, $status, $status); }
        return DB::all("SELECT s.id, s.slug, s.name, s.status AS shop_status, s.created_at, sub.status AS sub_status, p.name AS plan, sub.price_cents,
              (SELECT u.email FROM shop_members m JOIN users u ON u.id = m.user_id WHERE m.shop_id = s.id AND m.role = 'owner' ORDER BY u.id LIMIT 1) AS owner_email,
              (SELECT COUNT(*) FROM appointments a WHERE a.shop_id = s.id AND a.created_at > ? - INTERVAL 30 DAY) AS appts30
            FROM shops s LEFT JOIN subscriptions sub ON sub.shop_id = s.id LEFT JOIN plans p ON p.id = sub.plan_id WHERE $w ORDER BY s.created_at DESC LIMIT 100", array_merge([dt_db(now_utc())], $a));
    }

    public static function shopDetail(int $id): ?array
    {
        $shop = DB::one('SELECT * FROM shops WHERE id = ?', [$id]);
        if (!$shop) return null;
        return ['shop' => $shop,
            'sub' => DB::one('SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.shop_id = ?', [$id]),
            'charges' => DB::all('SELECT status, amount_cents, due_date, received_at FROM billing_charges WHERE shop_id = ? ORDER BY id DESC LIMIT 12', [$id]),
            'members' => DB::all('SELECT u.email, u.name, m.role FROM shop_members m JOIN users u ON u.id = m.user_id WHERE m.shop_id = ?', [$id]),
            'usage' => DB::all('SELECT period, metric, cnt FROM usage_counters WHERE shop_id = ? ORDER BY period DESC LIMIT 12', [$id]),
            'notes' => DB::all('SELECT n.note, n.created_at, u.email AS author FROM admin_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.shop_id = ? ORDER BY n.id DESC', [$id]),
            'audits' => DB::all('SELECT action, actor_kind, created_at, detail FROM audit_logs WHERE shop_id = ? ORDER BY id DESC LIMIT 30', [$id])];
    }

    private static function reason(string $r): string
    {
        $r = trim($r);
        if (mb_strlen($r) < 5) throw new BusinessError('Informe o motivo (mínimo de 5 caracteres).');
        return mb_substr($r, 0, 300);
    }
    public static function suspend(int $adminId, int $shopId, string $reason): void
    {
        $r = self::reason($reason);
        if (!DB::exec("UPDATE shops SET status = 'suspended' WHERE id = ?", [$shopId])) throw new BusinessError('Negócio não encontrado.', 'not_found', 404);
        audit($shopId, $adminId, 'admin.shop_suspended', 'shop', $shopId, $r, 'platform_admin');
    }
    public static function reactivate(int $adminId, int $shopId, string $reason): void
    {
        $r = self::reason($reason);
        if (!DB::exec("UPDATE shops SET status = 'active' WHERE id = ?", [$shopId])) throw new BusinessError('Negócio não encontrado.', 'not_found', 404);
        audit($shopId, $adminId, 'admin.shop_reactivated', 'shop', $shopId, $r, 'platform_admin');
    }
    /**
     * Registro manual de pagamento (Pix/dinheiro conferido por um administrador autenticado). Ativa ou renova 1 mês,
     * com a mesma regra do webhook. O token do formulário torna o registro idempotente (clique duplo não renova 2x).
     */
    public static function recordManualPayment(int $adminId, int $shopId, int $amountCents, string $note, string $token): void
    {
        if ($amountCents < 100 || $amountCents > 10000000) throw new BusinessError('Informe um valor válido.');
        if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) throw new BusinessError('Formulário inválido. Recarregue a página.');
        $note = mb_substr(trim($note), 0, 200);
        DB::tx(function () use ($adminId, $shopId, $amountCents, $note, $token) {
            $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ? FOR UPDATE', [$shopId]);
            if (!$sub) throw new BusinessError('Negócio sem assinatura.', 'not_found', 404);
            if (DB::one('SELECT id FROM billing_charges WHERE provider_ref = ?', ['manual_' . $token])) return;   // já registrado
            $now = now_utc();
            DB::insert('billing_charges', ['shop_id' => $shopId, 'subscription_id' => $sub['id'], 'provider_ref' => 'manual_' . $token, 'status' => 'received',
                'amount_cents' => $amountCents, 'due_date' => $now->format('Y-m-d'), 'received_at' => dt_db($now), 'created_at' => dt_db($now)]);
            $start = $now;
            if ($sub['status'] === 'trialing' && $sub['trial_ends_at']) { $te = dt_from_db($sub['trial_ends_at']); if ($te > $start) $start = $te; }
            if ($sub['status'] === 'active' && $sub['current_period_end']) { $pe = dt_from_db($sub['current_period_end']); if ($pe > $start) $start = $pe; }
            DB::exec("UPDATE subscriptions SET status='active', current_period_end=?, grace_until=NULL, cancel_at_period_end=0, canceled_at=NULL WHERE id = ?", [dt_db($start->modify('+1 month')), $sub['id']]);
            audit($shopId, $adminId, 'admin.manual_payment', 'subscription', $sub['id'], money($amountCents) . ($note !== '' ? ' — ' . $note : ''), 'platform_admin');
        });
    }

    public static function saveSystem(int $adminId, string $brand, string $support, string $entity = '', string $privacyEmail = ''): void
    {
        $brand = trim(preg_replace('/\s+/u', ' ', $brand));
        if (mb_strlen($brand) < 2 || mb_strlen($brand) > 40) throw new BusinessError('O nome do sistema precisa ter de 2 a 40 caracteres.');
        $support = mb_substr(trim($support), 0, 600);
        DB::q("REPLACE INTO meta (k, v) VALUES ('brand_name', ?)", [$brand]);
        $entity = mb_substr(trim(preg_replace('/\s+/u', ' ', $entity)), 0, 160);
        $privacyEmail = trim($privacyEmail);
        if ($privacyEmail !== '' && !filter_var($privacyEmail, FILTER_VALIDATE_EMAIL)) throw new BusinessError('O e-mail de privacidade não parece válido.');
        DB::q("REPLACE INTO meta (k, v) VALUES ('support_contact', ?)", [$support]);
        DB::q("REPLACE INTO meta (k, v) VALUES ('legal_entity', ?)", [$entity]);
        DB::q("REPLACE INTO meta (k, v) VALUES ('privacy_email', ?)", [$privacyEmail]);
        sys_setting('', '', true);   // recarrega o cache
        audit(null, $adminId, 'admin.system_saved', 'meta', null, null, 'platform_admin');
    }

    public static function saveSmtp(int $adminId, array $in): void
    {
        $old = Secrets::get('smtp');
        $host = trim((string)($in['host'] ?? '')); $port = (int)($in['port'] ?? 0); $sec = (string)($in['security'] ?? 'tls');
        $fromEmail = trim((string)($in['from_email'] ?? '')); $fromName = mb_substr(trim((string)($in['from_name'] ?? '')), 0, 60);
        if ($host === '') { Secrets::put('smtp', []); audit(null, $adminId, 'admin.smtp_cleared', 'meta', null, null, 'platform_admin'); return; }   // vazio = desligar
        if (!preg_match('/^[A-Za-z0-9.-]{3,190}$/', $host)) throw new BusinessError('Servidor SMTP inválido.');
        if ($port < 1 || $port > 65535) throw new BusinessError('Porta inválida.');
        if (!in_array($sec, ['tls', 'ssl', 'none'], true)) throw new BusinessError('Criptografia inválida.');
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new BusinessError('E-mail de envio inválido.');
        $pass = (string)($in['pass'] ?? '');
        Secrets::put('smtp', ['host' => $host, 'port' => $port, 'security' => $sec, 'user' => trim((string)($in['user'] ?? '')), 'pass' => $pass !== '' ? $pass : (string)($old['pass'] ?? ''),
            'from_email' => $fromEmail, 'from_name' => $fromName]);
        audit(null, $adminId, 'admin.smtp_saved', 'meta', null, $host . ':' . $port, 'platform_admin');
    }

    public static function saveAsaas(int $adminId, array $in): void
    {
        $old = Secrets::get('asaas');
        $env = ($in['env'] ?? '') === 'production' ? 'production' : 'sandbox';
        $key = trim((string)($in['api_key'] ?? ''));
        if ($key === '' && !empty($in['clear'])) { Secrets::put('asaas', []); audit(null, $adminId, 'admin.asaas_cleared', 'meta', null, null, 'platform_admin'); return; }
        if ($key === '') $key = (string)($old['api_key'] ?? '');
        if ($key === '') throw new BusinessError('Cole a chave da API do Asaas.');
        if (!preg_match('/^\$?aact_[A-Za-z0-9_=.\-]{10,}$/', $key)) throw new BusinessError('A chave não parece uma chave do Asaas (começa com $aact_).');
        if ($env === 'production' && !str_contains($key, 'prod')) throw new BusinessError('Para o ambiente de produção use uma chave de produção ($aact_prod_...).');
        if ($env === 'sandbox' && str_contains($key, 'prod')) throw new BusinessError('Esta é uma chave de produção. Escolha o ambiente de produção ou use uma chave de teste ($aact_hmlg_...).');
        Secrets::put('asaas', ['api_key' => $key, 'env' => $env]);
        audit(null, $adminId, 'admin.asaas_saved', 'meta', null, $env, 'platform_admin');
    }

    public static function saveWhatsApp(int $adminId, array $in): void
    {
        $old = Secrets::get('whatsapp');
        if (!empty($in['clear'])) { Secrets::put('whatsapp', []); audit(null, $adminId, 'admin.whatsapp_cleared', 'meta', null, null, 'platform_admin'); return; }
        $token = trim((string)($in['token'] ?? '')); if ($token === '') $token = (string)($old['token'] ?? '');
        $pid = preg_replace('/\D/', '', (string)($in['phone_number_id'] ?? ''));
        if ($token === '' || strlen($token) < 20 || preg_match('/\s/', $token)) throw new BusinessError('Cole o token de acesso (sem espaços).');
        if (strlen($pid) < 8 || strlen($pid) > 20) throw new BusinessError('Informe o ID do número de telefone (só números).');
        $ver = trim((string)($in['api_version'] ?? 'v21.0')); if (!preg_match('/^v\d{1,2}\.\d$/', $ver)) throw new BusinessError('Versão da API inválida (ex.: v21.0).');
        $lang = trim((string)($in['lang'] ?? 'pt_BR')); if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)) throw new BusinessError('Idioma inválido (ex.: pt_BR).');
        $tpl = [];
        foreach (WhatsApp::KINDS as $k) {
            $n = trim((string)($in['tpl_' . $k] ?? ''));
            if (!preg_match('/^[a-z0-9_]{1,100}$/', $n)) throw new BusinessError("Nome do modelo de '$k' inválido (letras minúsculas, números e _).");
            $tpl[$k] = $n;
        }
        Secrets::put('whatsapp', ['token' => $token, 'phone_number_id' => $pid, 'api_version' => $ver, 'lang' => $lang, 'templates' => $tpl]);
        audit(null, $adminId, 'admin.whatsapp_saved', 'meta', null, null, 'platform_admin');
    }

    public static function addNote(int $adminId, int $shopId, string $note): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) throw new BusinessError('Nota inválida.');
        DB::insert('admin_notes', ['shop_id' => $shopId, 'author_id' => $adminId, 'note' => $note, 'created_at' => dt_db(now_utc())]);
        audit($shopId, $adminId, 'admin.note_added', 'shop', $shopId, null, 'platform_admin');
    }

    public static function plans(): array { return DB::all('SELECT * FROM plans ORDER BY sort_order, price_cents'); }

    /** Mudança de preço/limites vale para novas contratações: assinaturas existentes mantêm price_cents. */
    public static function savePlan(int $adminId, array $d): void
    {
        $code = trim((string)$d['code']);
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $code)) throw new BusinessError('Código: letras minúsculas, números, - ou _.');
        $name = trim((string)$d['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) throw new BusinessError('Nome do plano inválido.');
        $price = (int)$d['price_cents'];
        if ($price < 0 || $price > 1000000) throw new BusinessError('Preço inválido.');
        $nullable = fn($v) => ($v === '' || $v === null) ? null : max(1, (int)$v);
        $msgs = max(0, (int)($d['max_messages_month'] ?? 0));   // 0 = sem mensagens; não existe ilimitado
        $hl = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($d['highlights'] ?? '')) ?: [])));
        $row = ['name' => $name, 'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 255), 'price_cents' => $price, 'trial_days' => max(0, min(60, (int)($d['trial_days'] ?? 0))),
            'max_professionals' => $nullable($d['max_professionals'] ?? ''), 'max_appointments_month' => $nullable($d['max_appointments_month'] ?? ''), 'max_messages_month' => $msgs,
            'feat_reminders' => !empty($d['feat_reminders']) ? 1 : 0, 'feat_commissions' => !empty($d['feat_commissions']) ? 1 : 0, 'highlights' => json_encode($hl, JSON_UNESCAPED_UNICODE),
            'is_active' => !empty($d['is_active']) ? 1 : 0, 'is_public' => !empty($d['is_public']) ? 1 : 0, 'sort_order' => (int)($d['sort_order'] ?? 0)];
        $exists = DB::val('SELECT 1 FROM plans WHERE code = ?', [$code]) !== null;
        if ($exists) {
            DB::exec('UPDATE plans SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($row))) . ' WHERE code = ?', array_merge(array_values($row), [$code]));
        } else {
            DB::insert('plans', ['code' => $code] + $row);
        }
        audit(null, $adminId, 'admin.plan_saved', 'plan', $code, (string)$price, 'platform_admin');
    }

    public static function failedEvents(): array { return DB::all("SELECT id, event_type, error, received_at FROM billing_events WHERE status = 'failed' ORDER BY id DESC LIMIT 100"); }
    public static function recentOutbox(int $limit = 50): array
    {
        return DB::all('SELECT o.id, o.shop_id, s.name AS shop, o.kind, o.status, o.send_at, o.detail FROM outbox o JOIN shops s ON s.id = o.shop_id ORDER BY o.id DESC LIMIT ' . (int)$limit);
    }

    // ---------------------------------------------------------- administradores da plataforma
    public static function admins(): array
    {
        return DB::all('SELECT id, name, email, created_at FROM users WHERE is_admin = 1 ORDER BY id');
    }

    /** Promove uma conta JÁ EXISTENTE (cadastrada) a administrador da plataforma. */
    public static function grantAdmin(int $actorId, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $u = DB::one('SELECT id, is_admin FROM users WHERE email = ?', [$email]);
        if (!$u) throw new BusinessError('Não há conta com este e-mail. Peça para a pessoa criar a conta em /cadastro primeiro.', 'not_found', 404);
        if ((int)$u['is_admin']) throw new BusinessError('Esta pessoa já é administradora.');
        DB::exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$u['id']]);
        audit(null, $actorId, 'admin.granted', 'user', $u['id'], $email);
    }

    public static function revokeAdmin(int $actorId, int $userId): void
    {
        if ($userId === $actorId) throw new BusinessError('Você não pode remover o seu próprio acesso. Peça a outro administrador.', 'forbidden', 403);
        DB::tx(function () use ($actorId, $userId) {
            $n = (int)DB::val('SELECT COUNT(*) FROM users WHERE is_admin = 1 FOR UPDATE');
            $u = DB::one('SELECT id, email, is_admin FROM users WHERE id = ?', [$userId]);
            if (!$u || !(int)$u['is_admin']) throw new BusinessError('Administrador não encontrado.', 'not_found', 404);
            if ($n <= 1) throw new BusinessError('Precisa existir ao menos um administrador.', 'last_admin');
            DB::exec('UPDATE users SET is_admin = 0 WHERE id = ?', [$userId]);
            audit(null, $actorId, 'admin.revoked', 'user', $userId, (string)$u['email']);
        });
    }
}
