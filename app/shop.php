<?php
declare(strict_types=1);

final class Shops
{
    private const RESERVED = ['admin', 'app', 'api', 'b', 'entrar', 'cadastro', 'sair', 'dev', 'termos', 'privacidade', 'agendamento', 'webhook', 'cron', 'instalar', 'assets', 'www', 'suporte', 'planos', 'recuperar-senha', 'redefinir-senha', 'storage'];

    public static function slugify(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($s)) ?: '';
        return trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    }

    public static function slugAvailable(string $slug): bool
    {
        return !in_array($slug, self::RESERVED, true) && DB::val('SELECT 1 FROM shops WHERE slug = ?', [$slug]) === null;
    }

    public static function create(int $userId, string $name, string $slug, string $phone, string $planCode): int
    {
        $name = trim($name); $slug = mb_strtolower(trim($slug));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new BusinessError('Informe o nome da barbearia.');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug)) throw new BusinessError('Endereço: use de 3 a 50 letras minúsculas, números ou hífen.');
        if (in_array($slug, self::RESERVED, true)) throw new BusinessError('Este endereço não está disponível. Escolha outro.', 'slug_reserved');
        $phoneN = null;
        if (trim($phone) !== '') $phoneN = Scheduling::normalizePhone($phone);
        $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$planCode]);
        if (!$plan) throw new BusinessError('Plano inválido.');
        if ((int)DB::val('SELECT COUNT(*) FROM shop_members WHERE user_id = ? AND role = \'owner\'', [$userId]) >= 3) throw new BusinessError('Limite de 3 barbearias por conta.');
        $user = DB::one('SELECT name FROM users WHERE id = ?', [$userId]);
        try {
            return DB::tx(function () use ($userId, $name, $slug, $phoneN, $plan, $user) {
                $sid = DB::insert('shops', ['slug' => $slug, 'name' => $name, 'phone' => $phoneN, 'created_at' => dt_db(now_utc())]);
                DB::insert('shop_members', ['shop_id' => $sid, 'user_id' => $userId, 'role' => 'owner']);
                $pid = DB::insert('professionals', ['shop_id' => $sid, 'user_id' => $userId, 'name' => $user['name']]);
                self::setHours($sid, $pid, array_map(fn($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '18:00'], [1, 2, 3, 4, 5, 6]));
                Billing::createInitial($sid, $plan['code']);
                audit($sid, $userId, 'shop.created', 'shop', $sid);
                return $sid;
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) throw new BusinessError('Este endereço já está em uso. Escolha outro.', 'slug_taken');
            throw $e;
        }
    }

    // ---------------------------------------------------------- profissionais

    private static function clock(string $t): int
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $t, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) throw new BusinessError('Horário inválido.');
        return (int)$m[1] * 60 + (int)$m[2];
    }

    public static function setHours(int $shopId, int $proId, array $rows): void
    {
        foreach ($rows as $r) {
            if ((int)$r['weekday'] < 1 || (int)$r['weekday'] > 7) throw new BusinessError('Dia da semana inválido.');
            if (self::clock($r['end']) <= self::clock($r['start'])) throw new BusinessError('O fim do expediente deve ser depois do início.');
        }
        for ($wd = 1; $wd <= 7; $wd++) {
            $day = array_values(array_filter($rows, fn($r) => (int)$r['weekday'] === $wd));
            usort($day, fn($a, $b) => self::clock($a['start']) <=> self::clock($b['start']));
            for ($i = 1; $i < count($day); $i++) if (self::clock($day[$i]['start']) < self::clock($day[$i - 1]['end'])) throw new BusinessError('Os intervalos do mesmo dia se sobrepõem.');
        }
        DB::tx(function () use ($shopId, $proId, $rows) {
            if (DB::val('SELECT 1 FROM professionals WHERE id = ? AND shop_id = ?', [$proId, $shopId]) === null) throw new BusinessError('Profissional não encontrado.', 'not_found', 404);
            DB::exec('DELETE FROM working_hours WHERE professional_id = ?', [$proId]);
            foreach ($rows as $r) DB::insert('working_hours', ['shop_id' => $shopId, 'professional_id' => $proId, 'weekday' => (int)$r['weekday'], 'start_time' => $r['start'] . ':00', 'end_time' => $r['end'] . ':00']);
        });
    }

    private static function checkProfessional(int $shopId, array $d, ?array $cur): void
    {
        $plan = Billing::state($shopId)['plan'];
        if ($d['commission'] < 0 || $d['commission'] > 100) throw new BusinessError('Comissão deve estar entre 0 e 100%.');
        if ($d['commission'] > 0 && (!$plan || !(int)$plan['feat_commissions']) && (!$cur || (float)$cur['commission_percent'] !== (float)$d['commission'])) {
            throw new BusinessError('Comissões não estão incluídas no seu plano.', 'feature_not_in_plan', 403);
        }
        if ($d['active'] && (!$cur || !(int)$cur['active'])) {
            $lim = $plan['max_professionals'] ?? null;
            if ($lim !== null && (int)DB::val('SELECT COUNT(*) FROM professionals WHERE shop_id = ? AND active = 1', [$shopId]) >= (int)$lim) {
                throw new BusinessError("Seu plano permite {$lim} profissional(is) ativo(s). Faça upgrade para adicionar mais.", 'plan_limit');
            }
        }
    }

    public static function createProfessional(int $shopId, int $userId, array $d): int
    {
        $d = self::cleanPro($d);
        return DB::tx(function () use ($shopId, $userId, $d) {
            DB::one('SELECT id FROM shops WHERE id = ? FOR UPDATE', [$shopId]);
            self::checkProfessional($shopId, $d, null);
            $id = DB::insert('professionals', ['shop_id' => $shopId, 'name' => $d['name'], 'bio' => $d['bio'], 'color' => $d['color'], 'commission_percent' => $d['commission'], 'active' => $d['active'] ? 1 : 0]);
            audit($shopId, $userId, 'professional.created', 'professional', $id);
            return $id;
        });
    }

    private static function cleanPro(array $d): array
    {
        $name = trim((string)$d['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new BusinessError('Informe o nome do profissional.');
        $color = (string)($d['color'] ?? '#c8963e');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#c8963e';
        return ['name' => $name, 'bio' => mb_substr(trim((string)($d['bio'] ?? '')), 0, 300), 'color' => $color, 'commission' => (float)($d['commission'] ?? 0), 'active' => !empty($d['active'])];
    }

    public static function updateProfessional(int $shopId, int $userId, int $id, array $d): void
    {
        $d = self::cleanPro($d);
        DB::tx(function () use ($shopId, $userId, $id, $d) {
            DB::one('SELECT id FROM shops WHERE id = ? FOR UPDATE', [$shopId]);
            $cur = DB::one('SELECT * FROM professionals WHERE id = ? AND shop_id = ? FOR UPDATE', [$id, $shopId]);
            if (!$cur) throw new BusinessError('Profissional não encontrado.', 'not_found', 404);
            self::checkProfessional($shopId, $d, $cur);
            if (!$d['active'] && (int)$cur['active']) {
                if ((int)DB::val('SELECT COUNT(*) FROM professionals WHERE shop_id = ? AND active = 1 AND id <> ?', [$shopId, $id]) === 0) throw new BusinessError('É preciso manter ao menos um profissional ativo.', 'last_professional');
                $f = (int)DB::val("SELECT COUNT(*) FROM appointments WHERE professional_id = ? AND status = 'confirmed' AND starts_at > ?", [$id, dt_db(now_utc())]);
                if ($f > 0) throw new BusinessError("Este profissional tem $f agendamento(s) futuro(s). Remarque ou cancele antes de desativar.", 'has_future_appointments');
            }
            DB::exec('UPDATE professionals SET name=?, bio=?, color=?, commission_percent=?, active=? WHERE id=?', [$d['name'], $d['bio'], $d['color'], $d['commission'], $d['active'] ? 1 : 0, $id]);
            audit($shopId, $userId, 'professional.updated', 'professional', $id);
        });
    }

    public static function setProfessionalServices(int $shopId, int $proId, array $serviceIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));
        DB::tx(function () use ($shopId, $proId, $ids) {
            if (DB::val('SELECT 1 FROM professionals WHERE id = ? AND shop_id = ?', [$proId, $shopId]) === null) throw new BusinessError('Profissional não encontrado.', 'not_found', 404);
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                if ((int)DB::val("SELECT COUNT(*) FROM services WHERE shop_id = ? AND id IN ($in)", array_merge([$shopId], $ids)) !== count($ids)) throw new BusinessError('Serviço inválido.');
            }
            DB::exec('DELETE FROM professional_services WHERE professional_id = ?', [$proId]);
            foreach ($ids as $sid) DB::insert('professional_services', ['professional_id' => $proId, 'service_id' => $sid, 'shop_id' => $shopId]);
        });
    }

    // ---------------------------------------------------------- serviços

    private static function cleanService(array $d): array
    {
        $name = trim((string)$d['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new BusinessError('Informe o nome do serviço.');
        $price = (int)$d['price_cents']; $dur = (int)$d['duration_min']; $b = (int)($d['buffer_before_min'] ?? 0); $a = (int)($d['buffer_after_min'] ?? 0);
        if ($price < 0 || $price > 1000000) throw new BusinessError('Preço inválido.');
        if ($dur < 5 || $dur > 600) throw new BusinessError('A duração deve ficar entre 5 e 600 minutos.');
        if ($b < 0 || $b > 240 || $a < 0 || $a > 240) throw new BusinessError('As folgas devem ficar entre 0 e 240 minutos.');
        return ['name' => $name, 'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 300), 'price_cents' => $price, 'duration_min' => $dur,
            'buffer_before_min' => $b, 'buffer_after_min' => $a, 'active' => !empty($d['active']) ? 1 : 0, 'bookable_online' => !empty($d['bookable_online']) ? 1 : 0];
    }

    public static function createService(int $shopId, int $userId, array $d): int
    {
        $c = self::cleanService($d);
        return DB::tx(function () use ($shopId, $userId, $c) {
            $id = DB::insert('services', ['shop_id' => $shopId] + $c);
            foreach (DB::all('SELECT id FROM professionals WHERE shop_id = ? AND active = 1', [$shopId]) as $p) {
                DB::insert('professional_services', ['professional_id' => $p['id'], 'service_id' => $id, 'shop_id' => $shopId]);
            }
            audit($shopId, $userId, 'service.created', 'service', $id);
            return $id;
        });
    }

    /** Agendamentos já feitos guardam nome/preço/duração da época: alterar o serviço não os muda. */
    public static function updateService(int $shopId, int $userId, int $id, array $d): void
    {
        $c = self::cleanService($d);
        $n = DB::exec('UPDATE services SET name=?, description=?, price_cents=?, duration_min=?, buffer_before_min=?, buffer_after_min=?, active=?, bookable_online=? WHERE id=? AND shop_id=?',
            [$c['name'], $c['description'], $c['price_cents'], $c['duration_min'], $c['buffer_before_min'], $c['buffer_after_min'], $c['active'], $c['bookable_online'], $id, $shopId]);
        if (!$n && DB::val('SELECT 1 FROM services WHERE id = ? AND shop_id = ?', [$id, $shopId]) === null) throw new BusinessError('Serviço não encontrado.', 'not_found', 404);
        audit($shopId, $userId, 'service.updated', 'service', $id);
    }

    // ---------------------------------------------------------- clientes e LGPD

    public static function listCustomers(int $shopId, string $search = '', int $limit = 30, int $offset = 0): array
    {
        $w = 'c.shop_id = ? AND c.anonymized_at IS NULL'; $a = [$shopId];
        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            $w .= ' AND (c.name LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ')';
            $a[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            if ($digits !== '') $a[] = '%' . $digits . '%';
        }
        return DB::all("SELECT c.id, c.name, c.phone, c.whatsapp_optin,
              (SELECT COUNT(*) FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed') AS visits,
              (SELECT MAX(a.starts_at) FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed') AS last_visit,
              (SELECT COUNT(*) FROM appointments a WHERE a.customer_id = c.id AND a.status = 'no_show') AS no_shows
            FROM customers c WHERE $w ORDER BY c.name LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset, $a);
    }

    public static function createCustomer(int $shopId, int $userId, string $name, string $phone, string $notes = '', string $prefs = ''): int
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new BusinessError('Informe o nome do cliente.');
        $p = Scheduling::normalizePhone($phone);
        try {
            $id = DB::insert('customers', ['shop_id' => $shopId, 'name' => $name, 'phone' => $p, 'notes' => mb_substr($notes, 0, 1000), 'preferences' => mb_substr($prefs, 0, 500), 'created_at' => dt_db(now_utc())]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) throw new BusinessError('Já existe um cliente com esse telefone.', 'duplicate_customer');
            throw $e;
        }
        audit($shopId, $userId, 'customer.created', 'customer', $id);
        return $id;
    }

    public static function updateCustomer(int $shopId, int $userId, int $id, string $name, string $phone, string $notes, string $prefs): void
    {
        $name = trim($name);
        if (mb_strlen($name) < 2) throw new BusinessError('Informe o nome do cliente.');
        $p = Scheduling::normalizePhone($phone);
        if (DB::val('SELECT 1 FROM customers WHERE id = ? AND shop_id = ? AND anonymized_at IS NULL', [$id, $shopId]) === null) throw new BusinessError('Cliente não encontrado.', 'not_found', 404);
        try {
            DB::exec('UPDATE customers SET name=?, phone=?, notes=?, preferences=? WHERE id=? AND shop_id=?', [$name, $p, mb_substr($notes, 0, 1000), mb_substr($prefs, 0, 500), $id, $shopId]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) throw new BusinessError('Já existe um cliente com esse telefone.', 'duplicate_customer');
            throw $e;
        }
        audit($shopId, $userId, 'customer.updated', 'customer', $id);
    }

    public static function customerHistory(int $shopId, int $id): ?array
    {
        $c = DB::one('SELECT id, name, phone, notes, preferences, whatsapp_optin, created_at FROM customers WHERE id = ? AND shop_id = ? AND anonymized_at IS NULL', [$id, $shopId]);
        if (!$c) return null;
        $ap = DB::all("SELECT a.id, a.starts_at, a.status, a.total_price_cents, p.name AS professional,
              (SELECT GROUP_CONCAT(s.name ORDER BY s.position SEPARATOR ' + ') FROM appointment_services s WHERE s.appointment_id = a.id) AS services
            FROM appointments a JOIN professionals p ON p.id = a.professional_id WHERE a.customer_id = ? AND a.shop_id = ? ORDER BY a.starts_at DESC LIMIT 200", [$id, $shopId]);
        return ['customer' => $c, 'appointments' => $ap];
    }

    public static function exportCustomer(int $shopId, int $userId, int $id): array
    {
        $h = self::customerHistory($shopId, $id);
        if (!$h) throw new BusinessError('Cliente não encontrado.', 'not_found', 404);
        $msgs = DB::all('SELECT kind, body, status, send_at FROM outbox WHERE shop_id = ? AND customer_id = ? ORDER BY id', [$shopId, $id]);
        audit($shopId, $userId, 'customer.exported', 'customer', $id);
        return ['cliente' => $h['customer'], 'agendamentos' => $h['appointments'], 'mensagens' => $msgs, 'exportado_em' => dt_db(now_utc()) . ' UTC'];
    }

    public static function anonymizeCustomer(int $shopId, int $userId, int $id): void
    {
        DB::tx(function () use ($shopId, $userId, $id) {
            $c = DB::one('SELECT id FROM customers WHERE id = ? AND shop_id = ? AND anonymized_at IS NULL FOR UPDATE', [$id, $shopId]);
            if (!$c) throw new BusinessError('Cliente não encontrado.', 'not_found', 404);
            $open = (int)DB::val("SELECT COUNT(*) FROM appointments WHERE customer_id = ? AND status = 'confirmed' AND starts_at > ?", [$id, dt_db(now_utc())]);
            if ($open > 0) throw new BusinessError('Este cliente tem agendamento futuro. Cancele-o antes de anonimizar.', 'has_future_appointments');
            DB::exec("UPDATE outbox SET status = 'canceled', detail = 'cliente anonimizado' WHERE customer_id = ? AND status = 'queued'", [$id]);
            DB::exec('DELETE FROM outbox WHERE customer_id = ?', [$id]);
            DB::exec("UPDATE customers SET name = 'Cliente anonimizado', phone = ?, notes = '', preferences = '', whatsapp_optin = 0, anonymized_at = ? WHERE id = ?", ['anon' . $id, dt_db(now_utc()), $id]);
            audit($shopId, $userId, 'customer.anonymized', 'customer', $id);
        });
    }

    // ---------------------------------------------------------- bloqueios e configurações

    public static function createBlock(int $shopId, int $userId, ?int $proId, DateTimeImmutable $from, DateTimeImmutable $to, string $kind, string $reason): array
    {
        if ($to <= $from) throw new BusinessError('O fim deve ser depois do início.');
        if ($proId !== null && DB::val('SELECT 1 FROM professionals WHERE id = ? AND shop_id = ?', [$proId, $shopId]) === null) throw new BusinessError('Profissional inválido.');
        if (!in_array($kind, ['lunch', 'time_off', 'vacation', 'other'], true)) $kind = 'other';
        $conf = (int)DB::val("SELECT COUNT(*) FROM appointments WHERE shop_id = ? AND status = 'confirmed' AND starts_at < ? AND ends_at > ? AND (? IS NULL OR professional_id = ?)", [$shopId, dt_db($to), dt_db($from), $proId, $proId]);
        $id = DB::insert('schedule_blocks', ['shop_id' => $shopId, 'professional_id' => $proId, 'starts_at' => dt_db($from), 'ends_at' => dt_db($to), 'kind' => $kind, 'reason' => mb_substr($reason, 0, 200)]);
        audit($shopId, $userId, 'schedule_block.created', 'schedule_block', $id, "conflitos=$conf");
        return ['id' => $id, 'conflicts' => $conf];
    }
    public static function deleteBlock(int $shopId, int $userId, int $id): void
    {
        if (!DB::exec('DELETE FROM schedule_blocks WHERE id = ? AND shop_id = ?', [$id, $shopId])) throw new BusinessError('Bloqueio não encontrado.', 'not_found', 404);
        audit($shopId, $userId, 'schedule_block.deleted', 'schedule_block', $id);
    }

    public static function updateSettings(int $shopId, int $userId, array $d): void
    {
        $name = trim((string)$d['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new BusinessError('Informe o nome da barbearia.');
        $phone = trim((string)$d['phone']) === '' ? null : Scheduling::normalizePhone((string)$d['phone']);
        $hours = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s;]+/', (string)$d['reminder_hours']) ?: []), fn($h) => $h >= 1 && $h <= 336)));
        if (count($hours) > 4) throw new BusinessError('No máximo 4 lembretes.');
        rsort($hours);
        $grid = (int)$d['slot_grid_minutes']; $maxd = (int)$d['max_days_ahead'];
        if ($grid < 5 || $grid > 120) throw new BusinessError('Intervalo entre horários: de 5 a 120 minutos.');
        if ($maxd < 1 || $maxd > 365) throw new BusinessError('Agendar até: de 1 a 365 dias.');
        DB::exec('UPDATE shops SET name=?, description=?, phone=?, address=?, slot_grid_minutes=?, min_notice_minutes=?, max_days_ahead=?, allow_any_professional=?, allow_online_cancel=?, cancel_min_notice_minutes=?, reminders_enabled=?, reminder_hours=? WHERE id=?',
            [$name, mb_substr(trim((string)$d['description']), 0, 500), $phone, mb_substr(trim((string)$d['address']), 0, 200), $grid, max(0, (int)$d['min_notice_minutes']), $maxd,
                !empty($d['allow_any_professional']) ? 1 : 0, !empty($d['allow_online_cancel']) ? 1 : 0, max(0, (int)$d['cancel_min_notice_minutes']), !empty($d['reminders_enabled']) ? 1 : 0, implode(',', $hours), $shopId]);
        audit($shopId, $userId, 'shop.settings_updated', 'shop', $shopId);
    }

    // ---------------------------------------------------------- agenda e painel

    public static function appointmentsBetween(int $shopId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $proId = null): array
    {
        $sql = "SELECT a.id, a.starts_at, a.ends_at, a.status, a.source, a.total_price_cents, a.notes, a.professional_id, p.name AS professional_name, p.color AS professional_color,
              c.id AS customer_id, c.name AS customer_name, c.phone AS customer_phone,
              (SELECT GROUP_CONCAT(s.name ORDER BY s.position SEPARATOR ' + ') FROM appointment_services s WHERE s.appointment_id = a.id) AS services
            FROM appointments a JOIN professionals p ON p.id = a.professional_id JOIN customers c ON c.id = a.customer_id
            WHERE a.shop_id = ? AND a.starts_at >= ? AND a.starts_at < ?";
        $args = [$shopId, dt_db($from), dt_db($to)];
        if ($proId) { $sql .= ' AND a.professional_id = ?'; $args[] = $proId; }
        return DB::all($sql . ' ORDER BY a.starts_at LIMIT 500', $args);
    }

    public static function dashboard(int $shopId, string $tz): array
    {
        $z = new DateTimeZone($tz);
        $now = now_utc();
        $d0 = $now->setTimezone($z)->setTime(0, 0); $d1 = $d0->modify('+1 day');
        $m0 = $d0->modify('first day of this month'); $m1 = $m0->modify('+1 month');
        $today = DB::one("SELECT COUNT(*) AS total, SUM(status='confirmed') AS confirmed, SUM(status='completed') AS completed, SUM(status='no_show') AS no_show,
              COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN total_price_cents END),0) AS expected
            FROM appointments WHERE shop_id = ? AND starts_at >= ? AND starts_at < ?", [$shopId, dt_db($d0), dt_db($d1)]);
        $recv = (int)DB::val("SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount_cents ELSE amount_cents END),0) FROM shop_payments WHERE shop_id = ? AND status = 'received' AND received_at >= ? AND received_at < ?", [$shopId, dt_db($m0), dt_db($m1)]);
        $exp = (int)DB::val("SELECT COALESCE(SUM(total_price_cents),0) FROM appointments WHERE shop_id = ? AND status IN ('confirmed','completed') AND starts_at >= ? AND starts_at < ?", [$shopId, dt_db($m0), dt_db($m1)]);
        $newc = (int)DB::val('SELECT COUNT(*) FROM customers WHERE shop_id = ? AND created_at >= ? AND created_at < ?', [$shopId, dt_db($m0), dt_db($m1)]);
        $ns = DB::one("SELECT SUM(status='no_show') AS ns, SUM(status IN ('completed','no_show')) AS closed FROM appointments WHERE shop_id = ? AND starts_at >= ? AND starts_at < ?", [$shopId, dt_db($m0), dt_db($m1)]);
        $rate = (int)$ns['closed'] > 0 ? round(100 * (int)$ns['ns'] / (int)$ns['closed'], 1) : null;
        $next = DB::all("SELECT a.id, a.starts_at, c.name AS customer_name, p.name AS professional_name,
              (SELECT GROUP_CONCAT(s.name ORDER BY s.position SEPARATOR ' + ') FROM appointment_services s WHERE s.appointment_id = a.id) AS services
            FROM appointments a JOIN customers c ON c.id = a.customer_id JOIN professionals p ON p.id = a.professional_id
            WHERE a.shop_id = ? AND a.status = 'confirmed' AND a.starts_at >= ? ORDER BY a.starts_at LIMIT 5", [$shopId, dt_db($now)]);
        return ['today' => array_map('intval', $today), 'received' => $recv, 'expected' => $exp, 'new_customers' => $newc, 'no_show_rate' => $rate, 'next' => $next];
    }
}
