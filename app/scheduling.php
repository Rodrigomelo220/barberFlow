<?php
declare(strict_types=1);

/**
 * Motor de agenda. Regras:
 *  - ocupação = [início − folga_antes, fim + folga_depois]; folgas = maior valor entre os serviços; tudo precisa caber num mesmo bloco de expediente;
 *  - nenhuma ocupação pode se sobrepor a outro agendamento confirmado do mesmo profissional nem a bloqueios;
 *  - reserva atômica: trava a linha do profissional (SELECT … FOR UPDATE) → revalida → grava. Rede de segurança: UNIQUE(professional_id, active_start).
 */
final class Scheduling
{
    public static function normalizePhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
        if (!preg_match('/^\d{2}9?\d{8}$/', $d) || (int)substr($d, 0, 2) < 11) throw new BusinessError('Telefone inválido. Use DDD + número.', 'invalid_phone');
        return '55' . $d;
    }
    public static function formatPhone(string $p): string
    {
        $d = substr($p, 2);
        return strlen($d) === 11 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)) : sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    }

    public static function shop(int $shopId): array
    {
        $s = DB::one('SELECT * FROM shops WHERE id = ?', [$shopId]);
        if (!$s) throw new BusinessError('Barbearia não encontrada.', 'not_found', 404);
        return $s;
    }

    /** @return array{items:array,duration:int,before:int,after:int,price:int} */
    public static function specs(int $shopId, array $ids, bool $onlineOnly): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || count($ids) > 5) throw new BusinessError('Escolha de 1 a 5 serviços.');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::all("SELECT * FROM services WHERE shop_id = ? AND active = 1 AND id IN ($in)" . ($onlineOnly ? ' AND bookable_online = 1' : ''), array_merge([$shopId], $ids));
        if (count($rows) !== count($ids)) throw new BusinessError('Um dos serviços não está disponível.', 'service_unavailable');
        $by = []; foreach ($rows as $r) $by[(int)$r['id']] = $r;
        $items = []; $dur = 0; $b = 0; $a = 0; $price = 0;
        foreach ($ids as $id) {
            $r = $by[$id]; $items[] = $r;
            $dur += (int)$r['duration_min']; $price += (int)$r['price_cents'];
            $b = max($b, (int)$r['buffer_before_min']); $a = max($a, (int)$r['buffer_after_min']);
        }
        return ['items' => $items, 'duration' => $dur, 'before' => $b, 'after' => $a, 'price' => $price];
    }

    public static function eligiblePros(int $shopId, array $serviceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        return DB::all("SELECT p.* FROM professionals p WHERE p.shop_id = ? AND p.active = 1
            AND (SELECT COUNT(DISTINCT ps.service_id) FROM professional_services ps WHERE ps.professional_id = p.id AND ps.service_id IN ($in)) = ?
            ORDER BY p.sort_order, p.id", array_merge([$shopId], $ids, [count($ids)]));
    }

    private static function tzObj(array $shop): DateTimeZone { return new DateTimeZone($shop['timezone']); }

    /** Início dos slots (timestamps UTC) de um profissional num dia local. */
    public static function proSlots(array $shop, array $specs, int $proId, string $date, ?int $excludeAppt = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= now_utc();
        $tz = self::tzObj($shop);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$day || $day->format('Y-m-d') !== $date) return [];
        $today = $now->setTimezone($tz)->setTime(0, 0);
        if ($day < $today || $day > $today->modify('+' . (int)$shop['max_days_ahead'] . ' days')) return [];

        $weekday = (int)$day->format('N');
        $ranges = DB::all('SELECT start_time, end_time FROM working_hours WHERE professional_id = ? AND weekday = ? ORDER BY start_time', [$proId, $weekday]);
        if (!$ranges) return [];

        $dayStart = $day->getTimestamp();
        $dayEnd = $day->modify('+1 day')->getTimestamp();
        $lo = dt_db((new DateTimeImmutable('@' . ($dayStart - 86400))));
        $hi = dt_db((new DateTimeImmutable('@' . ($dayEnd + 86400))));
        $busy = [];
        $sql = "SELECT occupied_start AS s, occupied_end AS e FROM appointments WHERE professional_id = ? AND status = 'confirmed' AND occupied_end > ? AND occupied_start < ?";
        $args = [$proId, $lo, $hi];
        if ($excludeAppt) { $sql .= ' AND id <> ?'; $args[] = $excludeAppt; }
        foreach (DB::all($sql, $args) as $r) $busy[] = [dt_from_db($r['s'])->getTimestamp(), dt_from_db($r['e'])->getTimestamp()];
        foreach (DB::all('SELECT starts_at AS s, ends_at AS e FROM schedule_blocks WHERE shop_id = ? AND (professional_id IS NULL OR professional_id = ?) AND ends_at > ? AND starts_at < ?', [$shop['id'], $proId, $lo, $hi]) as $r) {
            $busy[] = [dt_from_db($r['s'])->getTimestamp(), dt_from_db($r['e'])->getTimestamp()];
        }

        $grid = max(1, (int)$shop['slot_grid_minutes']) * 60;
        $minStart = $now->getTimestamp() + (int)$shop['min_notice_minutes'] * 60;
        $dur = $specs['duration'] * 60; $before = $specs['before'] * 60; $after = $specs['after'] * 60;
        $out = [];
        foreach ($ranges as $rg) {
            [$sh, $sm] = array_map('intval', explode(':', $rg['start_time']));
            [$eh, $em] = array_map('intval', explode(':', $rg['end_time']));
            $rs = $day->setTime($sh, $sm)->getTimestamp();
            $re = $day->setTime($eh, $em)->getTimestamp();
            for ($s = $rs; $s + $dur + $after <= $re; $s += $grid) {
                if ($s - $before < $rs || $s < $minStart) continue;
                $os = $s - $before; $oe = $s + $dur + $after;
                $ok = true;
                foreach ($busy as [$bs, $be]) if ($bs < $oe && $be > $os) { $ok = false; break; }
                if ($ok) $out[] = $s;
            }
        }
        return $out;
    }

    /** Slots para a página: 'any' junta os profissionais habilitados. @return array<int,array{ts:int,label:string}> */
    public static function slots(int $shopId, array $serviceIds, $professional, string $date, bool $onlineOnly, ?int $excludeAppt = null, ?DateTimeImmutable $now = null): array
    {
        $shop = self::shop($shopId);
        $specs = self::specs($shopId, $serviceIds, $onlineOnly);
        $pros = self::eligiblePros($shopId, $serviceIds);
        if ($professional !== 'any') {
            $pros = array_values(array_filter($pros, fn($p) => (int)$p['id'] === (int)$professional));
        } elseif (!(int)$shop['allow_any_professional'] && $onlineOnly) {
            return [];
        }
        $all = [];
        foreach ($pros as $p) foreach (self::proSlots($shop, $specs, (int)$p['id'], $date, $excludeAppt, $now) as $ts) $all[$ts] = true;
        ksort($all);
        $tz = self::tzObj($shop);
        return array_map(fn($ts) => ['ts' => $ts, 'label' => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i')], array_keys($all));
    }

    private static function isFree(array $shop, int $proId, DateTimeImmutable $os, DateTimeImmutable $oe, ?int $exclude): bool
    {
        $sql = "SELECT COUNT(*) FROM appointments WHERE professional_id = ? AND status = 'confirmed' AND occupied_start < ? AND occupied_end > ?";
        $a = [$proId, dt_db($oe), dt_db($os)];
        if ($exclude) { $sql .= ' AND id <> ?'; $a[] = $exclude; }
        if ((int)DB::val($sql, $a) > 0) return false;
        return (int)DB::val('SELECT COUNT(*) FROM schedule_blocks WHERE shop_id = ? AND (professional_id IS NULL OR professional_id = ?) AND starts_at < ? AND ends_at > ?', [$shop['id'], $proId, dt_db($oe), dt_db($os)]) === 0;
    }

    private static function slotError(): BusinessError
    {
        return new BusinessError('Esse horário acabou de ser ocupado. Escolha outro.', 'slot_unavailable', 409);
    }

    /** @return array{appointment_id:int,professional_id:int,customer_id:int,starts_at:DateTimeImmutable,ends_at:DateTimeImmutable,total_price_cents:int,manage_token:string} */
    public static function book(array $in): array
    {
        $now = $in['now'] ?? now_utc();
        $shopId = (int)$in['shop_id'];
        $shop = self::shop($shopId);
        $online = ($in['source'] ?? 'link') === 'link';
        $specs = self::specs($shopId, $in['service_ids'], $online);
        $name = trim((string)$in['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new BusinessError('Informe o nome do cliente.');
        $phone = self::normalizePhone((string)$in['phone']);
        /** @var DateTimeImmutable $start */
        $start = $in['starts_at']->setTimezone(new DateTimeZone('UTC'));
        if ($start <= $now) throw new BusinessError('Esse horário já passou.', 'in_the_past');
        $end = $start->modify('+' . $specs['duration'] . ' minutes');
        $os = $start->modify('-' . $specs['before'] . ' minutes');
        $oe = $end->modify('+' . $specs['after'] . ' minutes');
        $outside = !empty($in['allow_outside_hours']) && !$online;

        $pros = self::eligiblePros($shopId, $in['service_ids']);
        $want = $in['professional_id'] ?? 'any';
        if ($want === 'any') {
            if ($online && !(int)$shop['allow_any_professional']) throw new BusinessError('Escolha um profissional.');
        } else {
            $pros = array_values(array_filter($pros, fn($p) => (int)$p['id'] === (int)$want));
        }
        if (!$pros) throw new BusinessError('Nenhum profissional disponível para esses serviços.', 'no_professional');
        usort($pros, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);   // ordem fixa de travas: sem deadlock
        $date = $start->setTimezone(self::tzObj($shop))->format('Y-m-d');

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $res = DB::tx(function () use ($shop, $shopId, $pros, $want, $specs, $start, $end, $os, $oe, $outside, $date, $name, $phone, $in, $now, $token) {
            foreach ($pros as $p) DB::one('SELECT id FROM professionals WHERE id = ? AND shop_id = ? FOR UPDATE', [$p['id'], $shopId]);

            // escolhe o profissional (balanceando pelo menor movimento do dia quando "qualquer")
            $cands = $pros;
            if ($want === 'any' && count($cands) > 1) {
                $tz = self::tzObj($shop);
                $d0 = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
                $load = [];
                foreach ($cands as $p) {
                    $load[(int)$p['id']] = (int)DB::val("SELECT COUNT(*) FROM appointments WHERE professional_id = ? AND status = 'confirmed' AND starts_at >= ? AND starts_at < ?", [$p['id'], dt_db($d0), dt_db($d0->modify('+1 day'))]);
                }
                usort($cands, fn($a, $b) => [$load[(int)$a['id']], (int)$a['id']] <=> [$load[(int)$b['id']], (int)$b['id']]);
            }
            $chosen = null;
            foreach ($cands as $p) {
                $okSlot = $outside
                    ? self::isFree($shop, (int)$p['id'], $os, $oe, null)
                    : in_array($start->getTimestamp(), self::proSlots($shop, $specs, (int)$p['id'], $date, null, $now), true);
                if ($okSlot) { $chosen = $p; break; }
            }
            if (!$chosen) throw self::slotError();

            self::consumeAppointmentQuota($shop, $shopId, $now);

            $c = DB::one('SELECT id, whatsapp_optin FROM customers WHERE shop_id = ? AND phone = ? FOR UPDATE', [$shopId, $phone]);
            if ($c) {
                $cid = (int)$c['id'];
                if (!empty($in['whatsapp_consent']) && !(int)$c['whatsapp_optin']) DB::exec('UPDATE customers SET whatsapp_optin = 1 WHERE id = ?', [$cid]);
            } else {
                $cid = DB::insert('customers', ['shop_id' => $shopId, 'name' => $name, 'phone' => $phone, 'whatsapp_optin' => !empty($in['whatsapp_consent']) ? 1 : 0, 'created_at' => dt_db($now)]);
            }
            try {
                $aid = DB::insert('appointments', [
                    'shop_id' => $shopId, 'professional_id' => $chosen['id'], 'customer_id' => $cid, 'starts_at' => dt_db($start), 'ends_at' => dt_db($end),
                    'occupied_start' => dt_db($os), 'occupied_end' => dt_db($oe), 'status' => 'confirmed', 'source' => $in['source'] ?? 'link',
                    'total_price_cents' => $specs['price'], 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 500), 'manage_token_hash' => hash('sha256', $token),
                    'created_by' => $in['created_by'] ?? null, 'created_at' => dt_db($now),
                ]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw self::slotError();
                throw $e;
            }
            foreach ($specs['items'] as $i => $svc) {
                DB::insert('appointment_services', ['appointment_id' => $aid, 'position' => $i, 'service_id' => $svc['id'], 'name' => $svc['name'], 'price_cents' => $svc['price_cents'], 'duration_min' => $svc['duration_min']]);
            }
            Messages::onBooked($shop, $aid, $now);
            audit($shopId, $in['created_by'] ?? null, 'appointment.created', 'appointment', $aid, $in['source'] ?? 'link', ($in['created_by'] ?? null) ? 'user' : 'customer');
            return ['appointment_id' => $aid, 'professional_id' => (int)$chosen['id'], 'customer_id' => $cid];
        });

        return $res + ['starts_at' => $start, 'ends_at' => $end, 'total_price_cents' => $specs['price'], 'manage_token' => $token];
    }

    private static function consumeAppointmentQuota(array $shop, int $shopId, DateTimeImmutable $now): void
    {
        $plan = Billing::state($shopId)['plan'];
        $limit = $plan['max_appointments_month'] ?? null;
        $period = $now->setTimezone(self::tzObj($shop))->format('Y-m');
        $row = DB::one("SELECT cnt FROM usage_counters WHERE shop_id = ? AND period = ? AND metric = 'appointments' FOR UPDATE", [$shopId, $period]);
        $cnt = (int)($row['cnt'] ?? 0);
        if ($limit !== null && $cnt >= (int)$limit) throw new BusinessError('Esta barbearia atingiu o limite de agendamentos do plano neste mês.', 'plan_limit', 409);
        if ($row) DB::exec("UPDATE usage_counters SET cnt = cnt + 1 WHERE shop_id = ? AND period = ? AND metric = 'appointments'", [$shopId, $period]);
        else DB::insert('usage_counters', ['shop_id' => $shopId, 'period' => $period, 'metric' => 'appointments', 'cnt' => 1]);
    }

    private static function lockAppt(int $shopId, int $id): array
    {
        $a = DB::one('SELECT * FROM appointments WHERE id = ? AND shop_id = ? FOR UPDATE', [$id, $shopId]);
        if (!$a) throw new BusinessError('Agendamento não encontrado.', 'not_found', 404);
        return $a;
    }

    private static function assertCustomerMayChange(array $shop, array $a, DateTimeImmutable $now): void
    {
        if (!(int)$shop['allow_online_cancel']) throw new BusinessError('Esta barbearia não permite alterações online. Fale com ela pelo telefone.', 'policy_no_online_change');
        $min = (dt_from_db($a['starts_at'])->getTimestamp() - $now->getTimestamp()) / 60;
        if ($min < (int)$shop['cancel_min_notice_minutes']) throw new BusinessError('Alterações online só até ' . (int)$shop['cancel_min_notice_minutes'] . ' minutos antes do horário. Fale com a barbearia.', 'policy_too_late');
    }

    /** $actor: 'customer' (segue a política da barbearia) ou 'staff'. */
    public static function cancel(int $shopId, int $apptId, string $actor, ?int $userId = null, string $reason = '', ?DateTimeImmutable $now = null): void
    {
        $now ??= now_utc();
        DB::tx(function () use ($shopId, $apptId, $actor, $userId, $reason, $now) {
            $a = self::lockAppt($shopId, $apptId);
            if ($a['status'] === 'canceled') return;                                  // idempotente
            if ($a['status'] !== 'confirmed') throw new BusinessError('Este atendimento já foi encerrado e não pode ser cancelado.', 'not_confirmed');
            $shop = self::shop($shopId);
            if ($actor === 'customer') self::assertCustomerMayChange($shop, $a, $now);
            DB::exec("UPDATE appointments SET status = 'canceled' WHERE id = ?", [$apptId]);
            Messages::onCanceled($shop, $apptId, $now);
            audit($shopId, $userId, 'appointment.canceled', 'appointment', $apptId, $reason, $actor === 'customer' ? 'customer' : 'user');
        });
    }

    public static function reschedule(int $shopId, int $apptId, DateTimeImmutable $newStart, string $actor, ?int $userId = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= now_utc();
        return DB::tx(function () use ($shopId, $apptId, $newStart, $actor, $userId, $now) {
            $a = self::lockAppt($shopId, $apptId);
            if ($a['status'] !== 'confirmed') throw new BusinessError('Este agendamento não pode ser remarcado.', 'not_confirmed');
            $shop = self::shop($shopId);
            if ($actor === 'customer') self::assertCustomerMayChange($shop, $a, $now);
            DB::one('SELECT id FROM professionals WHERE id = ? FOR UPDATE', [$a['professional_id']]);
            $svc = DB::all('SELECT service_id FROM appointment_services WHERE appointment_id = ? ORDER BY position', [$apptId]);
            foreach ($svc as $s) if ($s['service_id'] === null) throw new BusinessError('Um serviço deste agendamento foi removido. Fale com a barbearia.', 'service_removed');
            $specs = self::specs($shopId, array_column($svc, 'service_id'), false);
            $newStart = $newStart->setTimezone(new DateTimeZone('UTC'));
            if ($newStart <= $now) throw new BusinessError('Esse horário já passou.', 'in_the_past');
            $date = $newStart->setTimezone(self::tzObj($shop))->format('Y-m-d');
            if (!in_array($newStart->getTimestamp(), self::proSlots($shop, $specs, (int)$a['professional_id'], $date, $apptId, $now), true)) throw self::slotError();
            $end = $newStart->modify('+' . $specs['duration'] . ' minutes');
            try {
                DB::exec('UPDATE appointments SET starts_at = ?, ends_at = ?, occupied_start = ?, occupied_end = ? WHERE id = ?',
                    [dt_db($newStart), dt_db($end), dt_db($newStart->modify('-' . $specs['before'] . ' minutes')), dt_db($end->modify('+' . $specs['after'] . ' minutes')), $apptId]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw self::slotError();
                throw $e;
            }
            Messages::onRescheduled($shop, $apptId, $now);
            audit($shopId, $userId, 'appointment.rescheduled', 'appointment', $apptId, null, $actor === 'customer' ? 'customer' : 'user');
            return ['starts_at' => $newStart, 'ends_at' => $end];
        });
    }

    public static function setOutcome(int $shopId, int $apptId, string $outcome, ?int $userId = null): void
    {
        if (!in_array($outcome, ['completed', 'no_show'], true)) throw new BusinessError('Resultado inválido.');
        DB::tx(function () use ($shopId, $apptId, $outcome, $userId) {
            $a = self::lockAppt($shopId, $apptId);
            if ($a['status'] === $outcome) return;
            if ($a['status'] === 'canceled') throw new BusinessError('Atendimento cancelado não pode ser concluído.', 'canceled');
            DB::exec('UPDATE appointments SET status = ?, completed_at = ? WHERE id = ?', [$outcome, $outcome === 'completed' ? dt_db(now_utc()) : null, $apptId]);
            DB::exec("UPDATE outbox SET status = 'canceled', detail = 'atendimento encerrado' WHERE appointment_id = ? AND status = 'queued'", [$apptId]);
            audit($shopId, $userId, $outcome === 'completed' ? 'appointment.completed' : 'appointment.no_show', 'appointment', $apptId);
            // NÃO registra pagamento: previsto ≠ recebido.
        });
    }
}
