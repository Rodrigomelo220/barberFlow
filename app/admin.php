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
        if (!DB::exec("UPDATE shops SET status = 'suspended' WHERE id = ?", [$shopId])) throw new BusinessError('Barbearia não encontrada.', 'not_found', 404);
        audit($shopId, $adminId, 'admin.shop_suspended', 'shop', $shopId, $r, 'platform_admin');
    }
    public static function reactivate(int $adminId, int $shopId, string $reason): void
    {
        $r = self::reason($reason);
        if (!DB::exec("UPDATE shops SET status = 'active' WHERE id = ?", [$shopId])) throw new BusinessError('Barbearia não encontrada.', 'not_found', 404);
        audit($shopId, $adminId, 'admin.shop_reactivated', 'shop', $shopId, $r, 'platform_admin');
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
}
