<?php
declare(strict_types=1);

/**
 * Assinatura do SaaS. NESTA VERSÃO (hospedagem gratuita) o provedor é SIMULADO: o checkout de demonstração
 * gera um webhook no formato do Asaas e o entrega ao MESMO endpoint/handler que um provedor real usaria
 * (token + idempotência + máquina de estados). A assinatura só fica ativa por esse caminho.
 * Não há chamadas de saída a nenhum gateway.
 */
final class Billing
{
    public const GRACE_DAYS = 5;
    public const RESTRICTED_DAYS = 10;

    private const RANK = ['pending' => 0, 'overdue' => 1, 'confirmed' => 2, 'received' => 3, 'refunded' => 4, 'chargeback' => 4];

    /** Decisão pura (testável): full / restricted / blocked. Nada aqui apaga dados. */
    public static function decide(?array $sub, string $shopStatus, DateTimeImmutable $now): array
    {
        $r = fn(string $level, string $reason, ?string $msg, ?DateTimeImmutable $until = null) => ['level' => $level, 'reason' => $reason, 'message' => $msg, 'until' => $until];
        if ($shopStatus === 'suspended') return $r('blocked', 'shop_suspended', 'Esta conta foi suspensa. Fale com o suporte.');
        if (!$sub) return $r('blocked', 'no_subscription', 'Escolha um plano para começar a usar.');
        $ts = fn($k) => !empty($sub[$k]) ? dt_from_db($sub[$k]) : null;
        $trialEnd = $ts('trial_ends_at'); $periodEnd = $ts('current_period_end'); $grace = $ts('grace_until');
        switch ($sub['status']) {
            case 'incomplete':
                return $r('blocked', 'awaiting_payment', 'Falta concluir o pagamento para liberar o painel.');
            case 'trialing':
                if ($trialEnd && $now < $trialEnd) return $r('full', 'trial', 'Você está no período de teste.', $trialEnd);
                return $r('blocked', 'trial_expired', 'O período de teste terminou. Conclua o pagamento para continuar.');
            case 'active':
                if ((int)$sub['cancel_at_period_end'] === 1 && $periodEnd && $now >= $periodEnd) return $r('blocked', 'canceled', 'Assinatura encerrada. Seus dados continuam guardados; reative quando quiser.');
                return $r('full', 'ok', null, $periodEnd);
            case 'past_due':
                $g = $grace ?? $now;
                if ($now <= $g) return $r('full', 'past_due_grace', 'Pagamento em atraso. Regularize para evitar restrições.', $g);
                $rEnd = $g->modify('+' . self::RESTRICTED_DAYS . ' days');
                if ($now <= $rEnd) return $r('restricted', 'past_due_restricted', 'Pagamento em atraso: o painel está em modo leitura e a página pública pausada.', $rEnd);
                return $r('blocked', 'suspended', 'Acesso bloqueado por falta de pagamento. Seus dados estão guardados; regularize para reativar.');
            case 'suspended':
                return $r('blocked', 'suspended', 'Acesso bloqueado por falta de pagamento. Seus dados estão guardados; regularize para reativar.');
            case 'canceled':
                return $r('blocked', 'canceled', 'Assinatura cancelada. Seus dados continuam guardados; reative quando quiser.');
        }
        return $r('blocked', 'no_subscription', 'Escolha um plano.');
    }

    public static function state(int $shopId): array
    {
        $shop = DB::one('SELECT status FROM shops WHERE id = ?', [$shopId]);
        $sub = DB::one('SELECT s.*, p.code AS plan_code, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.shop_id = ?', [$shopId]);
        $plan = $sub ? DB::one('SELECT * FROM plans WHERE id = ?', [$sub['plan_id']]) : null;
        return ['sub' => $sub, 'plan' => $plan, 'access' => self::decide($sub, $shop['status'] ?? 'active', now_utc())];
    }

    public static function publicPlans(): array
    {
        return DB::all('SELECT * FROM plans WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order, price_cents');
    }

    public static function createInitial(int $shopId, string $planCode): void
    {
        $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$planCode]);
        if (!$plan) throw new BusinessError('Plano inválido.');
        DB::insert('subscriptions', ['shop_id' => $shopId, 'plan_id' => $plan['id'], 'status' => 'incomplete', 'price_cents' => $plan['price_cents'], 'created_at' => dt_db(now_utc())]);
    }

    /** Contrata/recontrata. Não ativa: sem teste fica "incomplete" até o webhook; com teste fica "trialing". */
    public static function start(int $shopId, int $userId, string $planCode, bool $withTrial): array
    {
        return DB::tx(function () use ($shopId, $userId, $planCode, $withTrial) {
            $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ? FOR UPDATE', [$shopId]);
            if (!$sub) throw new BusinessError('Negócio sem assinatura base.');
            if (in_array($sub['status'], ['active', 'past_due'], true)) throw new BusinessError('Já existe uma assinatura em andamento. Use trocar de plano.', 'already_subscribed');
            $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$planCode]);
            if (!$plan) throw new BusinessError('Plano inválido.');
            $trialOk = $withTrial && (int)$plan['trial_days'] > 0 && $sub['trial_ends_at'] === null;   // um teste por barbearia
            if ($withTrial && !$trialOk) throw new BusinessError('O período de teste não está disponível para esta conta.', 'trial_unavailable');
            $now = now_utc();
            $due = $trialOk ? $now->modify('+' . (int)$plan['trial_days'] . ' days') : $now;
            $ref = 'sim_sub_' . bin2hex(random_bytes(8));
            DB::exec('UPDATE subscriptions SET plan_id=?, price_cents=?, status=?, provider_ref=?, trial_ends_at=?, grace_until=NULL, cancel_at_period_end=0, canceled_at=NULL WHERE id=?',
                [$plan['id'], $plan['price_cents'], $trialOk ? 'trialing' : 'incomplete', $ref, $trialOk ? dt_db($due) : $sub['trial_ends_at'], $sub['id']]);
            $charge = 'sim_pay_' . bin2hex(random_bytes(8));
            DB::insert('billing_charges', ['shop_id' => $shopId, 'subscription_id' => $sub['id'], 'provider_ref' => $charge, 'status' => 'pending', 'amount_cents' => $plan['price_cents'], 'due_date' => $due->format('Y-m-d'), 'created_at' => dt_db($now)]);
            audit($shopId, $userId, $trialOk ? 'billing.trial_started' : 'billing.checkout_started', 'subscription', $sub['id'], $planCode);
            return ['status' => $trialOk ? 'trialing' : 'incomplete', 'charge_ref' => $charge, 'sub_ref' => $ref, 'amount_cents' => (int)$plan['price_cents'], 'due' => $due->format('Y-m-d')];
        });
    }

    public static function cancel(int $shopId, int $userId): void
    {
        DB::tx(function () use ($shopId, $userId) {
            $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ? FOR UPDATE', [$shopId]);
            if (!$sub || !in_array($sub['status'], ['active', 'trialing', 'past_due'], true)) throw new BusinessError('Não há assinatura para cancelar.');
            if ($sub['status'] === 'trialing') {
                DB::exec("UPDATE subscriptions SET status='canceled', canceled_at=? WHERE id=?", [dt_db(now_utc()), $sub['id']]);
            } else {
                DB::exec('UPDATE subscriptions SET cancel_at_period_end = 1, canceled_at = ? WHERE id = ?', [dt_db(now_utc()), $sub['id']]);
            }
            audit($shopId, $userId, 'billing.canceled', 'subscription', $sub['id']);
        });
        self::remote(fn() => Asaas::cancel((string)DB::val('SELECT provider_ref FROM subscriptions WHERE shop_id = ?', [$shopId])), 'cancelar recorrência');
    }

    /** Chamada ao provedor depois que a mudança local já foi gravada: falha de rede não desfaz o que o cliente pediu; fica no log. */
    private static function remote(callable $fn, string $what): void
    {
        try { $fn(); } catch (BusinessError $e) { error_log("billing: falha ao $what no provedor: " . $e->getMessage()); }
    }

    public static function resume(int $shopId, int $userId): void
    {
        $ref = (string)DB::val('SELECT provider_ref FROM subscriptions WHERE shop_id = ?', [$shopId]);
        if (Asaas::configured() && str_starts_with($ref, 'sub_')) throw new BusinessError('A recorrência já foi encerrada no provedor de cobrança. Depois do fim do período pago, assine de novo em Planos.', 'resume_unavailable');
        $n = DB::exec("UPDATE subscriptions SET cancel_at_period_end = 0, canceled_at = NULL WHERE shop_id = ? AND status = 'active' AND cancel_at_period_end = 1", [$shopId]);
        if (!$n) throw new BusinessError('Não há cancelamento agendado para desfazer.');
        audit($shopId, $userId, 'billing.resumed', 'subscription');
    }

    public static function changePlan(int $shopId, int $userId, string $planCode): void
    {
        DB::tx(function () use ($shopId, $userId, $planCode) {
            $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ? FOR UPDATE', [$shopId]);
            if (!$sub || !in_array($sub['status'], ['active', 'trialing'], true)) throw new BusinessError('Só é possível trocar de plano com a assinatura ativa ou em teste.', 'not_active');
            $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$planCode]);
            if (!$plan) throw new BusinessError('Plano inválido.');
            if ($plan['max_professionals'] !== null) {
                $n = (int)DB::val('SELECT COUNT(*) FROM professionals WHERE shop_id = ? AND active = 1', [$shopId]);
                if ($n > (int)$plan['max_professionals']) throw new BusinessError("O plano {$plan['name']} permite {$plan['max_professionals']} profissional(is) ativo(s); você tem $n. Desative alguns antes.", 'plan_limit');
            }
            DB::exec('UPDATE subscriptions SET plan_id = ?, price_cents = ? WHERE id = ?', [$plan['id'], $plan['price_cents'], $sub['id']]);
            audit($shopId, $userId, 'billing.plan_changed', 'subscription', $sub['id'], $planCode);
        });
        $row = DB::one('SELECT provider_ref, price_cents FROM subscriptions WHERE shop_id = ?', [$shopId]);
        if ($row && !empty($row['provider_ref'])) self::remote(fn() => Asaas::updateValue((string)$row['provider_ref'], (int)$row['price_cents']), 'atualizar valor');
    }

    // ------------------------------------------------------------ webhook (provedor simulado ou real no formato Asaas)

    public static function webhookToken(): string { return (string)cfg('app.webhook_token', ''); }

    public static function verifyWebhook(?string $got): bool
    {
        $t = self::webhookToken();
        return $t !== '' && is_string($got) && $got !== '' && hash_equals($t, $got);
    }

    private const EVENT_MAP = [
        'PAYMENT_CREATED' => 'payment.created', 'PAYMENT_CONFIRMED' => 'payment.confirmed', 'PAYMENT_RECEIVED' => 'payment.received',
        'PAYMENT_OVERDUE' => 'payment.overdue', 'PAYMENT_REFUNDED' => 'payment.refunded', 'PAYMENT_CHARGEBACK_REQUESTED' => 'payment.chargeback',
        'SUBSCRIPTION_INACTIVATED' => 'subscription.canceled', 'SUBSCRIPTION_DELETED' => 'subscription.canceled',
    ];

    /** @return array{status:int,accepted:int,duplicates:int,error?:string} — só devolve 200 depois de gravar o evento. */
    public static function ingest(?string $tokenHeader, string $raw): array
    {
        if (!self::verifyWebhook($tokenHeader)) return ['status' => 401, 'accepted' => 0, 'duplicates' => 0, 'error' => 'não autenticado'];
        if (strlen($raw) > 1_000_000) return ['status' => 413, 'accepted' => 0, 'duplicates' => 0, 'error' => 'corpo grande demais'];
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['event']) || !is_string($j['event'])) return ['status' => 400, 'accepted' => 0, 'duplicates' => 0, 'error' => 'payload inválido'];
        $key = isset($j['id']) && is_string($j['id']) && $j['id'] !== '' ? substr($j['id'], 0, 120) : 'sha256:' . hash('sha256', $raw);
        try {
            $id = DB::insert('billing_events', ['event_key' => $key, 'event_type' => substr($j['event'], 0, 60), 'payload' => $raw, 'received_at' => dt_db(now_utc())]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) return ['status' => 200, 'accepted' => 0, 'duplicates' => 1];
            throw $e;
        }
        try { self::process($id); } catch (Throwable $e) { /* fica gravado como failed; o painel admin permite reprocessar */ }
        return ['status' => 200, 'accepted' => 1, 'duplicates' => 0];
    }

    public static function process(int $eventId): string
    {
        return DB::tx(function () use ($eventId) {
            $ev = DB::one('SELECT * FROM billing_events WHERE id = ? FOR UPDATE', [$eventId]);
            if (!$ev || $ev['status'] === 'processed') return 'skipped';
            $j = json_decode($ev['payload'], true);
            $type = self::EVENT_MAP[$j['event'] ?? ''] ?? 'ignored';
            $res = 'ignored';
            if ($type !== 'ignored') {
                $res = self::apply($type, $j);
                if ($res === 'unmatched') {
                    DB::exec("UPDATE billing_events SET status='failed', error='assinatura não encontrada' WHERE id = ?", [$eventId]);
                    return 'failed';
                }
            }
            DB::exec("UPDATE billing_events SET status = 'processed', error = NULL WHERE id = ?", [$eventId]);
            return $res;
        });
    }

    public static function retry(int $eventId): string
    {
        DB::exec("UPDATE billing_events SET status='received' WHERE id = ? AND status = 'failed'", [$eventId]);
        return self::process($eventId);
    }

    /** Só aceita link https da fatura (nunca javascript: etc.). */
    private static function invoiceUrl(array $p): ?string
    {
        $u = $p['invoiceUrl'] ?? null;
        return is_string($u) && strlen($u) <= 250 && preg_match('#^https://#i', $u) ? $u : null;
    }

    private static function apply(string $type, array $j): string
    {
        $now = now_utc();
        if ($type === 'subscription.canceled') {
            $ref = $j['subscription']['id'] ?? null;
            $sub = $ref ? DB::one('SELECT * FROM subscriptions WHERE provider_ref = ? FOR UPDATE', [$ref]) : null;
            if (!$sub) return 'unmatched';
            $pe = $sub['current_period_end'] ? dt_from_db($sub['current_period_end']) : null;
            if ($sub['status'] === 'active' && $pe && $pe > $now) {
                // recorrência encerrada, mas o período já pago continua: cancela só no fim dele
                DB::exec('UPDATE subscriptions SET cancel_at_period_end = 1, canceled_at = COALESCE(canceled_at, ?) WHERE id = ?', [dt_db($now), $sub['id']]);
            } else {
                DB::exec("UPDATE subscriptions SET status='canceled', canceled_at=COALESCE(canceled_at, ?) WHERE id = ?", [dt_db($now), $sub['id']]);
            }
            audit((int)$sub['shop_id'], null, 'billing.provider_canceled', 'subscription', $sub['id'], null, 'provider');
            return 'applied';
        }
        $p = $j['payment'] ?? null;
        if (!is_array($p) || empty($p['id'])) return 'ignored';
        $subRef = $p['subscription'] ?? null;
        $sub = $subRef ? DB::one('SELECT * FROM subscriptions WHERE provider_ref = ? FOR UPDATE', [$subRef]) : null;
        if (!$sub) return 'unmatched';
        $target = ['payment.created' => 'pending', 'payment.confirmed' => 'confirmed', 'payment.received' => 'received', 'payment.overdue' => 'overdue',
            'payment.refunded' => 'refunded', 'payment.chargeback' => 'chargeback'][$type];
        $amount = (int)round(((float)($p['value'] ?? 0)) * 100);
        $due = isset($p['dueDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['dueDate']) ? $p['dueDate'] : null;

        $ch = DB::one('SELECT * FROM billing_charges WHERE provider_ref = ? FOR UPDATE', [(string)$p['id']]);
        if (!$ch) {
            DB::insert('billing_charges', ['shop_id' => $sub['shop_id'], 'subscription_id' => $sub['id'], 'provider_ref' => (string)$p['id'], 'status' => $target, 'amount_cents' => $amount, 'due_date' => $due, 'invoice_url' => self::invoiceUrl($p), 'received_at' => $target === 'received' ? dt_db($now) : null, 'created_at' => dt_db($now)]);
            $changed = true;
        } else {
            if (empty($ch['invoice_url']) && ($u = self::invoiceUrl($p))) DB::exec('UPDATE billing_charges SET invoice_url = ? WHERE id = ?', [$u, $ch['id']]);
            // evento atrasado/fora de ordem não regride o estado
            $changed = self::RANK[$target] > self::RANK[$ch['status']];
            if ($changed) DB::exec('UPDATE billing_charges SET status = ?, received_at = IF(? = \'received\', ?, received_at) WHERE id = ?', [$target, $target, dt_db($now), $ch['id']]);
        }
        if (!$changed) return 'stale';

        $shopId = (int)$sub['shop_id'];
        if ($target === 'confirmed') {
            return 'applied';   // confirmado ≠ recebido: só "received" ativa ou renova
        }
        if ($target === 'received') {
            $start = $now;
            if ($sub['status'] === 'trialing' && $sub['trial_ends_at']) { $te = dt_from_db($sub['trial_ends_at']); if ($te > $start) $start = $te; }
            if ($sub['status'] === 'active' && $sub['current_period_end']) { $pe = dt_from_db($sub['current_period_end']); if ($pe > $start) $start = $pe; }
            DB::exec("UPDATE subscriptions SET status='active', current_period_end=?, grace_until=NULL WHERE id = ?", [dt_db($start->modify('+1 month')), $sub['id']]);
            audit($shopId, null, 'billing.payment_received', 'subscription', $sub['id'], null, 'provider');
        } elseif ($target === 'overdue') {
            if (in_array($sub['status'], ['active', 'trialing'], true)) {
                DB::exec("UPDATE subscriptions SET status='past_due', grace_until=COALESCE(grace_until, ?) WHERE id = ?", [dt_db($now->modify('+' . self::GRACE_DAYS . ' days')), $sub['id']]);
                audit($shopId, null, 'billing.past_due', 'subscription', $sub['id'], null, 'provider');
            }
        } elseif ($target === 'chargeback') {
            DB::exec("UPDATE subscriptions SET status='suspended' WHERE id = ?", [$sub['id']]);
            audit($shopId, null, 'billing.chargeback', 'subscription', $sub['id'], null, 'provider');
        }
        return 'applied';
    }

    /** Simulador: monta um webhook no formato do Asaas e o entrega ao mesmo handler de produção. */
    public static function simulate(string $event, string $subRef, string $chargeRef, int $amountCents, string $due): array
    {
        $body = json_encode(['id' => 'evt_sim_' . bin2hex(random_bytes(8)), 'event' => $event,
            'payment' => ['id' => $chargeRef, 'subscription' => $subRef, 'value' => $amountCents / 100, 'dueDate' => $due, 'status' => 'RECEIVED', 'billingType' => 'PIX']]);
        return self::ingest(self::webhookToken(), $body);
    }

    /** Varredura periódica (sem cron: chamada preguiçosamente e por cron.php). */
    public static function sweep(): void
    {
        $now = now_utc();
        // período encerrado com cancelamento agendado → cancelada
        DB::exec("UPDATE subscriptions SET status='canceled' WHERE status='active' AND cancel_at_period_end = 1 AND current_period_end IS NOT NULL AND current_period_end < ?", [dt_db($now)]);
        // teste terminado sem pagamento → incomplete (acesso já está bloqueado por decide(); isto só deixa o estado coerente)
        DB::exec("UPDATE subscriptions SET status='incomplete' WHERE status='trialing' AND trial_ends_at IS NOT NULL AND trial_ends_at < ?", [dt_db($now)]);
        // renovação vencida sem pagamento: active passado do fim do período → past_due
        $due = DB::all("SELECT id, shop_id FROM subscriptions WHERE status='active' AND cancel_at_period_end = 0 AND current_period_end IS NOT NULL AND current_period_end < ?", [dt_db($now)]);
        foreach ($due as $s) {
            DB::exec("UPDATE subscriptions SET status='past_due', grace_until=COALESCE(grace_until, ?) WHERE id = ?", [dt_db($now->modify('+' . self::GRACE_DAYS . ' days')), $s['id']]);
            audit((int)$s['shop_id'], null, 'billing.renewal_overdue', 'subscription', $s['id'], null, 'system');
        }
    }
}
