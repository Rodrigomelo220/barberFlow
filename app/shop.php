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

    // ---------------------------------------------------------- logo e capa

    private const IMG_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /**
     * Guarda logo ou capa. Valida o conteúdo de verdade (getimagesize), limita tamanho/dimensões, regrava a imagem com GD quando
     * disponível (descarta metadados e qualquer carga embutida) e usa nome aleatório. $file é uma entrada de $_FILES.
     * $trusted=true só nos testes (arquivo não veio de upload HTTP).
     */
    public static function saveImage(int $shopId, int $userId, string $kind, array $file, bool $trusted = false): string
    {
        if (!in_array($kind, ['logo', 'cover'], true)) throw new BusinessError('Tipo de imagem inválido.');
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new BusinessError('A imagem é grande demais (máximo 2 MB).', 'image_too_big');
        if ($err !== UPLOAD_ERR_OK) throw new BusinessError('Escolha uma imagem para enviar.', 'no_file');
        $tmp = (string)$file['tmp_name'];
        if (!$trusted && !is_uploaded_file($tmp)) throw new BusinessError('Envio inválido.');
        if (filesize($tmp) > 2 * 1024 * 1024) throw new BusinessError('A imagem é grande demais (máximo 2 MB).', 'image_too_big');
        $info = @getimagesize($tmp);
        if (!$info || !isset(self::IMG_TYPES[$info[2]])) throw new BusinessError('Use uma imagem JPG, PNG ou WebP.', 'bad_image');
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) throw new BusinessError('Dimensões da imagem fora do limite (até 6000 px).', 'bad_image');
        $ext = self::IMG_TYPES[$info[2]];
        $dir = BF_ROOT . '/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new BusinessError('Não foi possível gravar a imagem neste servidor (pasta uploads/).', 'storage');
        $name = $shopId . '-' . $kind . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        $done = false;
        if (extension_loaded('gd')) {
            $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp), IMAGETYPE_PNG => @imagecreatefrompng($tmp), default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false };
            if ($src) {
                $max = $kind === 'logo' ? 480 : 1600;
                $w = imagesx($src); $h = imagesy($src);
                $scale = min(1, $max / max($w, $h));
                $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                if ($ext !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $done = match ($ext) { 'jpg' => imagejpeg($dst, $dest, 86), 'png' => imagepng($dst, $dest, 7), default => function_exists('imagewebp') ? imagewebp($dst, $dest, 84) : false };
                imagedestroy($src); imagedestroy($dst);
            }
        }
        if (!$done) {
            if (!$trusted ? !move_uploaded_file($tmp, $dest) : !copy($tmp, $dest)) throw new BusinessError('Não foi possível gravar a imagem.', 'storage');
        }
        @chmod($dest, 0644);
        $col = $kind . '_path';
        $old = DB::val("SELECT $col FROM shops WHERE id = ?", [$shopId]);
        DB::exec("UPDATE shops SET $col = ? WHERE id = ?", [$name, $shopId]);
        if ($old) @unlink($dir . '/' . basename((string)$old));
        audit($shopId, $userId, 'shop.image_updated', 'shop', $shopId, $kind);
        return $name;
    }

    public static function removeImage(int $shopId, int $userId, string $kind): void
    {
        if (!in_array($kind, ['logo', 'cover'], true)) throw new BusinessError('Tipo de imagem inválido.');
        $col = $kind . '_path';
        $old = DB::val("SELECT $col FROM shops WHERE id = ?", [$shopId]);
        DB::exec("UPDATE shops SET $col = NULL WHERE id = ?", [$shopId]);
        if ($old) @unlink(BF_ROOT . '/uploads/' . basename((string)$old));
        audit($shopId, $userId, 'shop.image_removed', 'shop', $shopId, $kind);
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path && preg_match('/^[0-9]+-(logo|cover)-[0-9a-f]{12}\.(jpg|png|webp)$/', $path) ? url('/uploads/' . $path) : null;
    }

    // ------------------------------------------------------- dados de exemplo (modo demo)

    /**
     * Preenche uma barbearia VAZIA com dados fictícios para testar o painel: serviços, equipe, clientes,
     * ~5 semanas de atendimentos (concluídos com pagamento registrado, faltas, cancelados), agenda dos próximos dias e despesas.
     * Nomes e telefones são inventados (telefones 11 99999-00xx). Se já houver atendimentos, só completa serviços/equipe (não gera atendimentos).
     * @return array{services:int,professionals:int,customers:int,appointments:int}
     */
    public static function seedDemo(int $shopId, int $userId): array
    {
        $shop = DB::one('SELECT timezone FROM shops WHERE id = ?', [$shopId]);
        $tz = new DateTimeZone($shop['timezone']); $utc = new DateTimeZone('UTC');
        $hasAppts = (int)DB::val('SELECT COUNT(*) FROM appointments WHERE shop_id = ?', [$shopId]) > 0;
        mt_srand(20261022);
        $svcDefs = [['Corte degradê', 45, 4500, 'Máquina e tesoura, acabamento na navalha'], ['Corte social', 40, 4000, 'Clássico, na tesoura ou máquina'], ['Corte navalhado', 50, 5000, 'Acabamento total na navalha'],
            ['Corte infantil', 30, 3500, 'Até 10 anos'], ['Barba', 30, 3000, 'Barba com toalha quente'], ['Corte + barba', 70, 7000, 'Combo completo'], ['Sobrancelha', 15, 1500, ''], ['Pigmentação', 40, 5000, ''], ['Hidratação', 30, 3000, '']];
        $have = array_map('mb_strtolower', array_column(DB::all('SELECT name FROM services WHERE shop_id = ?', [$shopId]), 'name'));
        $newServices = 0;
        foreach ($svcDefs as [$n, $dur, $price, $desc]) {
            if (in_array(mb_strtolower($n), $have, true)) continue;
            DB::insert('services', ['shop_id' => $shopId, 'name' => $n, 'description' => $desc, 'price_cents' => $price, 'duration_min' => $dur, 'buffer_before_min' => 0, 'buffer_after_min' => 0, 'active' => 1, 'bookable_online' => 1, 'sort_order' => 0]); $newServices++;
        }
        $svc = DB::all('SELECT id, name, price_cents, duration_min FROM services WHERE shop_id = ? AND active = 1', [$shopId]);
        $pros = DB::all('SELECT id, name, color, commission_percent FROM professionals WHERE shop_id = ? AND active = 1 ORDER BY id', [$shopId]);
        $newPros = 0;
        $plan = Billing::state($shopId)['plan'] ?? null; $limit = $plan['max_professionals'] ?? null;
        if (!$pros) { self::createProfessional($shopId, $userId, ['name' => 'Barbeiro', 'color' => '#b68a4b', 'commission' => 0, 'active' => 1]); $newPros++; }
        // equipe de exemplo só se o plano permitir mais de um profissional
        if (!$hasAppts && ($limit === null || (int)$limit > 1)) {
            foreach ([['Diego', '#335f78', 40], ['Marcos', '#247653', 35]] as [$n, $c, $cm]) {
                $cnt = (int)DB::val('SELECT COUNT(*) FROM professionals WHERE shop_id = ? AND active = 1', [$shopId]);
                if ($cnt >= 3 || ($limit !== null && $cnt >= (int)$limit)) break;
                self::createProfessional($shopId, $userId, ['name' => $n, 'color' => $c, 'commission' => $cm, 'active' => 1]); $newPros++;
            }
        }
        $pros = DB::all('SELECT id, name, color, commission_percent FROM professionals WHERE shop_id = ? AND active = 1 ORDER BY id', [$shopId]);
        $svcIds = array_map(fn($x) => (int)$x['id'], $svc);
        foreach ($pros as $p) {
            $linked = array_map('intval', array_column(DB::all('SELECT service_id FROM professional_services WHERE professional_id = ?', [$p['id']]), 'service_id'));
            if (array_diff($svcIds, $linked)) self::setProfessionalServices($shopId, (int)$p['id'], array_unique(array_merge($linked, $svcIds)));
            if ((int)DB::val('SELECT COUNT(*) FROM working_hours WHERE professional_id = ?', [$p['id']]) === 0) {
                $rows = []; for ($wd = 1; $wd <= 6; $wd++) { $rows[] = ['weekday' => $wd, 'start' => '09:00', 'end' => $wd === 6 ? '16:00' : '12:00']; if ($wd < 6) $rows[] = ['weekday' => $wd, 'start' => '13:00', 'end' => '19:00']; }
                self::setHours($shopId, (int)$p['id'], $rows);
            }
        }
        if ($hasAppts) return ['services' => $newServices, 'professionals' => $newPros, 'customers' => 0, 'appointments' => 0];
        $first = ['Carlos', 'João', 'Pedro', 'Lucas', 'Mateus', 'Gabriel', 'Rafael', 'Bruno', 'Felipe', 'André', 'Thiago', 'Vinícius', 'Eduardo', 'Rodrigo', 'Leandro', 'Fábio', 'Gustavo', 'Henrique'];
        $last = ['Silva', 'Souza', 'Oliveira', 'Santos', 'Lima', 'Pereira', 'Costa', 'Ribeiro', 'Almeida', 'Carvalho', 'Gomes', 'Martins'];
        $cust = [];
        for ($i = 0; $i < 18; $i++) {
            $cust[] = (int)DB::insert('customers', ['shop_id' => $shopId, 'name' => $first[$i] . ' ' . $last[($i * 5) % count($last)], 'phone' => '5511999990' . sprintf('%03d', $i + 1), 'notes' => '', 'preferences' => '', 'whatsapp_optin' => 0, 'created_at' => dt_db(now_utc()->modify('-' . mt_rand(5, 40) . ' days'))]);
        }
        $now = now_utc(); $today = $now->setTimezone($tz)->setTime(0, 0);
        $methods = ['pix', 'pix', 'pix', 'cash', 'debit', 'credit']; $count = 0;
        DB::tx(function () use ($shopId, $userId, $pros, $svc, $cust, $tz, $utc, $now, $today, $methods, &$count) {
            for ($off = -35; $off <= 10; $off++) {
                $day = $today->modify(($off >= 0 ? '+' : '') . $off . ' day');
                $wd = (int)$day->format('N'); if ($wd === 7) continue;
                foreach ($pros as $p) {
                    $cursor = $day->setTime(9, 0); $end = $day->setTime($wd === 6 ? 16 : 19, 0); $busy = mt_rand(55, 85);
                    while ($cursor < $end) {
                        $h = (int)$cursor->format('G');
                        if ($wd !== 6 && $h >= 12 && $h < 13) { $cursor = $day->setTime(13, 0); continue; }
                        if (mt_rand(1, 100) > $busy) { $cursor = $cursor->modify('+30 minutes'); continue; }
                        $sv = $svc[mt_rand(0, count($svc) - 1)]; $dur = (int)$sv['duration_min'];
                        $fin = $cursor->modify("+$dur minutes");
                        if ($fin > $end) { $cursor = $cursor->modify('+30 minutes'); continue; }
                        if ($wd !== 6 && $h < 12 && $fin > $day->setTime(12, 0)) { $cursor = $day->setTime(13, 0); continue; }
                        $startUtc = $cursor->setTimezone($utc); $endUtc = $fin->setTimezone($utc);
                        $past = $endUtc <= $now; $r = mt_rand(1, 100);
                        $status = !$past ? ($r <= 8 ? 'canceled' : 'confirmed') : ($r <= 6 ? 'canceled' : ($r <= 12 ? 'no_show' : 'completed'));
                        $apptId = (int)DB::insert('appointments', ['shop_id' => $shopId, 'professional_id' => $p['id'], 'customer_id' => $cust[mt_rand(0, count($cust) - 1)], 'starts_at' => dt_db($startUtc), 'ends_at' => dt_db($endUtc),
                            'occupied_start' => dt_db($startUtc), 'occupied_end' => dt_db($endUtc), 'status' => $status, 'source' => mt_rand(0, 1) ? 'link' : 'manual', 'total_price_cents' => $sv['price_cents'], 'notes' => '',
                            'manage_token_hash' => hash('sha256', 'demo-' . bin2hex(random_bytes(8))), 'created_by' => $userId, 'created_at' => dt_db($startUtc->modify('-2 days')), 'completed_at' => $status === 'completed' ? dt_db($endUtc) : null]);
                        DB::insert('appointment_services', ['appointment_id' => $apptId, 'position' => 0, 'service_id' => $sv['id'], 'name' => $sv['name'], 'price_cents' => $sv['price_cents'], 'duration_min' => $dur]);
                        if ($status === 'completed' && mt_rand(1, 100) <= 90) {
                            $cid = (int)DB::val('SELECT customer_id FROM appointments WHERE id = ?', [$apptId]);
                            DB::insert('shop_payments', ['shop_id' => $shopId, 'appointment_id' => $apptId, 'professional_id' => $p['id'], 'customer_id' => $cid, 'kind' => 'payment', 'amount_cents' => $sv['price_cents'],
                                'method' => $methods[mt_rand(0, count($methods) - 1)], 'status' => 'received', 'commission_percent' => $p['commission_percent'], 'received_at' => dt_db($endUtc), 'notes' => '', 'created_by' => $userId, 'created_at' => dt_db($endUtc)]);
                        }
                        $count++; $cursor = $fin->modify('+' . (mt_rand(0, 1) * 15) . ' minutes');
                    }
                }
            }
        });
        foreach ([['Aluguel do ponto', 'aluguel', 180000, 5], ['Produtos (pomadas e lâminas)', 'produtos', 42000, 12], ['Energia e água', 'contas', 31000, 20], ['Marketing (impulsionamento)', 'marketing', 15000, 27]] as [$d, $c, $v, $ago]) {
            Finance::addExpense($shopId, $userId, $d, $c, $v, 'pix', $today->modify("-$ago day")->format('Y-m-d'));
        }
        audit($shopId, $userId, 'shop.demo_seeded', 'shop', $shopId, "appointments=$count");
        return ['services' => $newServices, 'professionals' => $newPros, 'customers' => count($cust), 'appointments' => $count];
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
        // próximos 7 dias (agendamentos confirmados) e últimos 7 dias (recebido), por dia local
        $wd = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
        $ahead = []; $back = [];
        for ($i = 0; $i < 7; $i++) {
            $a = $d0->modify("+$i day"); $ahead[$a->format('Y-m-d')] = ['date' => $a->format('Y-m-d'), 'label' => $i === 0 ? 'hoje' : $wd[(int)$a->format('w')], 'day' => $a->format('d'), 'count' => 0];
            $b = $d0->modify('-' . (6 - $i) . ' day'); $back[$b->format('Y-m-d')] = ['date' => $b->format('Y-m-d'), 'label' => $i === 6 ? 'hoje' : $wd[(int)$b->format('w')], 'day' => $b->format('d'), 'cents' => 0];
        }
        foreach (DB::all("SELECT starts_at FROM appointments WHERE shop_id = ? AND status = 'confirmed' AND starts_at >= ? AND starts_at < ?", [$shopId, dt_db($d0), dt_db($d0->modify('+7 day'))]) as $r) {
            $k = dt_from_db($r['starts_at'])->setTimezone($z)->format('Y-m-d'); if (isset($ahead[$k])) $ahead[$k]['count']++;
        }
        foreach (DB::all("SELECT received_at, amount_cents, kind FROM shop_payments WHERE shop_id = ? AND status = 'received' AND received_at >= ? AND received_at < ?", [$shopId, dt_db($d0->modify('-6 day')), dt_db($d1)]) as $r) {
            $k = dt_from_db($r['received_at'])->setTimezone($z)->format('Y-m-d'); if (isset($back[$k])) $back[$k]['cents'] += $r['kind'] === 'refund' ? -(int)$r['amount_cents'] : (int)$r['amount_cents'];
        }
        $setup = [
            'service' => (int)DB::val('SELECT COUNT(*) FROM services WHERE shop_id = ? AND active = 1', [$shopId]) > 0,
            'professional' => (int)DB::val('SELECT COUNT(DISTINCT ps.professional_id) FROM professional_services ps JOIN professionals p ON p.id = ps.professional_id WHERE ps.shop_id = ? AND p.active = 1', [$shopId]) > 0,
            'hours' => (int)DB::val('SELECT COUNT(*) FROM working_hours WHERE shop_id = ?', [$shopId]) > 0,
            'booking' => (int)DB::val('SELECT COUNT(*) FROM appointments WHERE shop_id = ?', [$shopId]) > 0,
        ];
        return ['week_ahead' => array_values($ahead), 'week_received' => array_values($back), 'setup' => $setup, 'today' => array_map('intval', $today), 'received' => $recv, 'expected' => $exp, 'new_customers' => $newc, 'no_show_rate' => $rate, 'next' => $next];
    }
}
