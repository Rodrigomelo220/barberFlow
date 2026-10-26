<?php
declare(strict_types=1);

/**
 * Mensagens ao cliente. Entram na caixa de saída (tabela outbox) e, na hora marcada:
 *  - modo teste (demo): ficam "simulada", nada é enviado;
 *  - modo produção com a API do WhatsApp configurada: são enviadas por modelo aprovado (WhatsApp::send);
 *  - modo produção sem API: ficam "manual" para o dono enviar com um toque (link wa.me) e marcar como enviada.
 */
final class Messages
{
    private static function apptInfo(int $apptId): ?array
    {
        return DB::one("SELECT a.id, a.shop_id, a.customer_id, a.starts_at, a.status, c.name AS customer, c.phone, c.whatsapp_optin, c.anonymized_at,
              (SELECT GROUP_CONCAT(s.name ORDER BY s.position SEPARATOR ' + ') FROM appointment_services s WHERE s.appointment_id = a.id) AS services
            FROM appointments a JOIN customers c ON c.id = a.customer_id WHERE a.id = ?", [$apptId]);
    }

    /** Variáveis dos modelos do WhatsApp: [primeiro nome, negócio, data e hora, serviços]. */
    private static function params(array $shop, array $a): array
    {
        $tz = $shop['timezone'];
        return [explode(' ', trim($a['customer']))[0] ?? '', (string)$shop['name'], dt_local($a['starts_at'], $tz, 'd/m') . ' às ' . dt_local($a['starts_at'], $tz, 'H:i'), (string)($a['services'] ?? '')];
    }

    private static function text(string $kind, array $shop, array $a): string
    {
        [$first, , $when] = self::params($shop, $a);
        return match ($kind) {
            'confirmacao' => "Olá, $first! Seu horário na {$shop['name']} está confirmado para $when ({$a['services']}).",
            'lembrete' => "Olá, $first! Lembrete: você tem horário na {$shop['name']} em $when ({$a['services']}).",
            'cancelamento' => "Olá, $first. Seu horário na {$shop['name']} em $when foi cancelado.",
            'remarcado' => "Olá, $first! Seu horário na {$shop['name']} foi remarcado para $when.",
            default => '',
        };
    }

    private static function queue(array $shop, array $a, string $kind, DateTimeImmutable $sendAt, DateTimeImmutable $now): void
    {
        if (!(int)$a['whatsapp_optin'] || $a['anonymized_at']) return;       // só com consentimento
        DB::insert('outbox', ['shop_id' => $shop['id'], 'appointment_id' => $a['id'], 'customer_id' => $a['customer_id'], 'kind' => $kind, 'phone' => $a['phone'],
            'body' => self::text($kind, $shop, $a), 'params' => json_encode(self::params($shop, $a), JSON_UNESCAPED_UNICODE), 'send_at' => dt_db($sendAt), 'status' => 'queued', 'created_at' => dt_db($now)]);
    }

    public static function onBooked(array $shop, int $apptId, DateTimeImmutable $now): void
    {
        $a = self::apptInfo($apptId);
        self::queue($shop, $a, 'confirmacao', $now, $now);
        $plan = Billing::state((int)$shop['id'])['plan'];
        if ((int)$shop['reminders_enabled'] && $plan && (int)$plan['feat_reminders']) {
            $start = dt_from_db($a['starts_at']);
            foreach (array_filter(array_map('intval', explode(',', (string)$shop['reminder_hours']))) as $h) {
                $at = $start->modify("-$h hours");
                if ($at > $now) self::queue($shop, $a, 'lembrete', $at, $now);
            }
        }
    }

    public static function onCanceled(array $shop, int $apptId, DateTimeImmutable $now): void
    {
        DB::exec("UPDATE outbox SET status = 'canceled', detail = 'agendamento cancelado' WHERE appointment_id = ? AND status = 'queued'", [$apptId]);
        $a = self::apptInfo($apptId);
        self::queue($shop, $a, 'cancelamento', $now, $now);
    }

    public static function onRescheduled(array $shop, int $apptId, DateTimeImmutable $now): void
    {
        DB::exec("UPDATE outbox SET status = 'canceled', detail = 'remarcado' WHERE appointment_id = ? AND status = 'queued'", [$apptId]);
        $a = self::apptInfo($apptId);
        self::queue($shop, $a, 'remarcado', $now, $now);
        $plan = Billing::state((int)$shop['id'])['plan'];
        if ((int)$shop['reminders_enabled'] && $plan && (int)$plan['feat_reminders']) {
            $start = dt_from_db($a['starts_at']);
            foreach (array_filter(array_map('intval', explode(',', (string)$shop['reminder_hours']))) as $h) {
                $at = $start->modify("-$h hours");
                if ($at > $now) self::queue($shop, $a, 'lembrete', $at, $now);
            }
        }
    }

    /** "Entrega" as mensagens vencidas ao simulador, respeitando plano, limite mensal e estado do agendamento. */
    public static function deliverDue(int $limit = 50, ?DateTimeImmutable $now = null): int
    {
        $now ??= now_utc();
        $rows = DB::all("SELECT id FROM outbox WHERE status = 'queued' AND send_at <= ? ORDER BY send_at LIMIT " . (int)$limit, [dt_db($now)]);
        $n = 0; $send = [];
        foreach ($rows as $r) {
            DB::tx(function () use ($r, $now, &$n, &$send) {
                $m = DB::one("SELECT * FROM outbox WHERE id = ? AND status = 'queued' FOR UPDATE", [$r['id']]);   // outro processo pode ter pegado
                if (!$m) return;
                $skip = function (string $why) use ($m) { DB::exec("UPDATE outbox SET status = 'skipped', detail = ? WHERE id = ?", [$why, $m['id']]); };
                if ($m['kind'] === 'lembrete' && $m['appointment_id']) {
                    $st = DB::val('SELECT status FROM appointments WHERE id = ?', [$m['appointment_id']]);
                    if ($st !== 'confirmed') { $skip('agendamento não está mais confirmado'); return; }
                }
                $c = $m['customer_id'] ? DB::one('SELECT whatsapp_optin, anonymized_at FROM customers WHERE id = ?', [$m['customer_id']]) : null;
                if (!$c || !(int)$c['whatsapp_optin'] || $c['anonymized_at']) { $skip('cliente sem consentimento'); return; }
                $shop = Scheduling::shop((int)$m['shop_id']);
                $plan = Billing::state((int)$shop['id'])['plan'];
                if (!$plan || (!(int)$plan['feat_reminders'] && $m['kind'] === 'lembrete')) { $skip('recurso fora do plano'); return; }
                $limitM = (int)$plan['max_messages_month'];     // 0 = sem mensagens; nunca ilimitado
                $period = $now->setTimezone(new DateTimeZone($shop['timezone']))->format('Y-m');
                $cnt = (int)DB::val("SELECT cnt FROM usage_counters WHERE shop_id = ? AND period = ? AND metric = 'messages' FOR UPDATE", [$shop['id'], $period]);
                if ($cnt >= $limitM) { $skip('limite mensal de mensagens do plano atingido'); return; }
                DB::q("INSERT INTO usage_counters (shop_id, period, metric, cnt) VALUES (?, ?, 'messages', 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1", [$shop['id'], $period]);
                if (cfg('app.mode', 'demo') === 'demo') {
                    DB::exec("UPDATE outbox SET status = 'simulated', sent_at = ?, detail = 'simulada: nada foi enviado ao WhatsApp' WHERE id = ?", [dt_db($now), $m['id']]);
                } elseif (WhatsApp::configured()) {
                    DB::exec("UPDATE outbox SET status = 'sending' WHERE id = ?", [$m['id']]);   // a chamada à API acontece fora da transação
                    $send[] = $m;
                } else {
                    DB::exec("UPDATE outbox SET status = 'manual', detail = 'envie pelo WhatsApp (botão ao lado)' WHERE id = ?", [$m['id']]);
                }
                $n++;
            });
        }
        foreach ($send as $m) {
            $err = WhatsApp::send((string)$m['phone'], (string)$m['kind'], (array)json_decode((string)$m['params'], true));
            if ($err === null) { DB::exec("UPDATE outbox SET status = 'sent', sent_at = ?, detail = NULL WHERE id = ?", [dt_db($now), $m['id']]); continue; }
            $shop = Scheduling::shop((int)$m['shop_id']);
            $period = $now->setTimezone(new DateTimeZone($shop['timezone']))->format('Y-m');
            DB::exec("UPDATE usage_counters SET cnt = GREATEST(cnt - 1, 0) WHERE shop_id = ? AND period = ? AND metric = 'messages'", [$m['shop_id'], $period]);   // falha não consome o limite
            DB::exec("UPDATE outbox SET status = 'failed', detail = ? WHERE id = ?", [mb_substr($err, 0, 190), $m['id']]);
            $n--;
        }
        return $n;
    }

    /** Roda tarefas periódicas sem cron: no máximo a cada 2 min, protegido por trava no banco. */
    public static function lazyTick(): void
    {
        $now = now_utc();
        $last = DB::val("SELECT v FROM meta WHERE k = 'last_tick'");
        if ($last !== null && (int)$last > $now->getTimestamp() - 120) return;
        DB::q("REPLACE INTO meta (k, v) VALUES ('last_tick', ?)", [(string)$now->getTimestamp()]);
        self::runPeriodic();
    }
    public static function runPeriodic(): array
    {
        Billing::sweep();
        return ['messages' => self::deliverDue()];
    }
}
