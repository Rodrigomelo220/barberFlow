<?php
declare(strict_types=1);

/**
 * Cobrança recorrente pelo Asaas (https://docs.asaas.com). Endpoints usados: POST /customers, POST /subscriptions,
 * GET /subscriptions/{id}/payments, POST /subscriptions/{id} (valor), DELETE /subscriptions/{id}.
 * Autenticação: cabeçalho access_token (+ User-Agent). A ATIVAÇÃO continua só pelo webhook autenticado (Billing::ingest).
 * A chave da API fica criptografada no banco e nunca é mostrada nem registrada.
 */
final class Asaas
{
    public const SANDBOX = 'https://api-sandbox.asaas.com/v3';
    public const PRODUCTION = 'https://api.asaas.com/v3';

    public static function settings(): array { return Secrets::get('asaas'); }
    public static function configured(): bool { $s = self::settings(); return !empty($s['api_key']); }
    public static function env(): string { return (self::settings()['env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox'; }
    public static function baseUrl(): string { return (string)cfg('app.asaas_base_url', '') ?: (self::env() === 'production' ? self::PRODUCTION : self::SANDBOX); }

    /** @return array{code:int,json:?array} */
    public static function call(string $method, string $path, ?array $body = null): array
    {
        $key = (string)(self::settings()['api_key'] ?? '');
        if ($key === '') throw new BusinessError('Cobrança automática não configurada.', 'asaas_off', 503);
        $ch = curl_init(self::baseUrl() . $path);
        $h = ['access_token: ' . $key, 'User-Agent: ' . preg_replace('/[^A-Za-z0-9 ._-]/', '', brand()) . '-SaaS/1.0', 'Accept: application/json'];
        if ($body !== null) $h[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($out === false) { error_log('asaas: falha de rede: ' . $err); throw new BusinessError('Não consegui falar com o serviço de cobrança. Tente de novo em instantes.', 'asaas_net', 502); }
        $j = json_decode((string)$out, true);
        if ($code >= 400) {
            $msg = is_array($j) && !empty($j['errors'][0]['description']) ? (string)$j['errors'][0]['description'] : '';
            error_log("asaas: HTTP $code em $method $path" . ($msg !== '' ? " — $msg" : ''));
            if ($code === 401) throw new BusinessError('A chave do Asaas foi recusada. Confira a chave e o ambiente (teste ou produção).', 'asaas_auth', 502);
            throw new BusinessError($msg !== '' ? 'Cobrança recusada: ' . $msg : 'O serviço de cobrança recusou a solicitação.', 'asaas_rejected', 422);
        }
        return ['code' => $code, 'json' => is_array($j) ? $j : null];
    }

    /** Valida CPF ou CNPJ (dígitos verificadores). */
    public static function validDoc(string $doc): bool
    {
        $d = preg_replace('/\D/', '', $doc);
        if (strlen($d) === 11) {
            if (preg_match('/^(\d)\1{10}$/', $d)) return false;
            for ($t = 9; $t < 11; $t++) { $s = 0; for ($c = 0; $c < $t; $c++) $s += (int)$d[$c] * (($t + 1) - $c); $s = ((10 * $s) % 11) % 10; if ((int)$d[$c] !== $s) return false; }
            return true;
        }
        if (strlen($d) === 14) {
            if (preg_match('/^(\d)\1{13}$/', $d)) return false;
            foreach ([[12, [5,4,3,2,9,8,7,6,5,4,3,2]], [13, [6,5,4,3,2,9,8,7,6,5,4,3,2]]] as [$n, $w]) {
                $s = 0; foreach ($w as $i => $x) $s += (int)$d[$i] * $x; $r = $s % 11; $dv = $r < 2 ? 0 : 11 - $r; if ((int)$d[$n] !== $dv) return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Cria cliente + assinatura mensal no Asaas e guarda os identificadores. NÃO ativa nada: a assinatura só fica ativa
     * quando o webhook PAYMENT_RECEIVED chegar. Devolve a URL da fatura (se disponível).
     */
    public static function subscribe(int $shopId, int $userId, string $planCode, string $name, string $email, string $doc): ?string
    {
        if (!self::validDoc($doc)) throw new BusinessError('CPF ou CNPJ inválido.');
        $name = trim($name); if (mb_strlen($name) < 3) throw new BusinessError('Informe o nome completo ou a razão social.');
        $doc = preg_replace('/\D/', '', $doc);
        $sub = DB::one('SELECT * FROM subscriptions WHERE shop_id = ?', [$shopId]);
        if (!$sub) throw new BusinessError('Negócio sem assinatura base.');
        if (in_array($sub['status'], ['active', 'past_due'], true)) throw new BusinessError('Já existe uma assinatura em andamento.', 'already_subscribed');
        $plan = DB::one('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$planCode]);
        if (!$plan) throw new BusinessError('Plano inválido.');

        // 1) cliente (reaproveita o já criado)
        $custId = (string)($sub['provider_customer'] ?? '');
        if ($custId === '') {
            $r = self::call('POST', '/customers', ['name' => $name, 'email' => $email, 'cpfCnpj' => $doc, 'externalReference' => 'shop:' . $shopId, 'notificationDisabled' => false])['json'];
            $custId = (string)($r['id'] ?? '');
            if ($custId === '') throw new BusinessError('Resposta inesperada do serviço de cobrança.', 'asaas_bad', 502);
        }
        // 2) assinatura mensal; 1ª cobrança hoje (ou ao fim do teste, se ainda estiver em teste válido)
        $due = now_utc();
        if ($sub['status'] === 'trialing' && $sub['trial_ends_at']) { $te = dt_from_db($sub['trial_ends_at']); if ($te > $due) $due = $te; }
        $r = self::call('POST', '/subscriptions', ['customer' => $custId, 'billingType' => 'UNDEFINED', 'value' => round($plan['price_cents'] / 100, 2),
            'nextDueDate' => $due->format('Y-m-d'), 'cycle' => 'MONTHLY', 'description' => brand() . ' — plano ' . $plan['name'], 'externalReference' => 'shop:' . $shopId])['json'];
        $subId = (string)($r['id'] ?? '');
        if ($subId === '') throw new BusinessError('Resposta inesperada do serviço de cobrança.', 'asaas_bad', 502);

        DB::tx(function () use ($sub, $plan, $subId, $custId, $shopId, $userId, $planCode) {
            $keepStatus = $sub['status'] === 'trialing' ? 'trialing' : 'incomplete';
            DB::exec('UPDATE subscriptions SET plan_id=?, price_cents=?, status=?, provider_ref=?, provider_customer=?, grace_until=NULL, cancel_at_period_end=0, canceled_at=NULL WHERE id=?',
                [$plan['id'], $plan['price_cents'], $keepStatus, $subId, $custId, $sub['id']]);
            audit($shopId, $userId, 'billing.asaas_subscribed', 'subscription', $sub['id'], $planCode);
        });

        // 3) link da primeira cobrança (a cobrança em si chega também pelo webhook PAYMENT_CREATED)
        $url = null;
        try {
            $pays = self::call('GET', '/subscriptions/' . rawurlencode($subId) . '/payments')['json']['data'] ?? [];
            if ($pays && !empty($pays[0]['id'])) {
                $p = $pays[0]; $url = isset($p['invoiceUrl']) && is_string($p['invoiceUrl']) ? $p['invoiceUrl'] : null;
                if (!DB::one('SELECT id FROM billing_charges WHERE provider_ref = ?', [(string)$p['id']])) {
                    DB::insert('billing_charges', ['shop_id' => $shopId, 'subscription_id' => $sub['id'], 'provider_ref' => (string)$p['id'], 'status' => 'pending',
                        'amount_cents' => (int)round(((float)($p['value'] ?? 0)) * 100), 'due_date' => $p['dueDate'] ?? $due->format('Y-m-d'), 'invoice_url' => $url, 'created_at' => dt_db(now_utc())]);
                }
            }
        } catch (BusinessError $e) { /* o link também chega pelo webhook; segue sem ele */ }
        return $url;
    }

    /** Atualiza o valor da assinatura no Asaas (troca de plano). Falha de rede não desfaz a troca local, mas é registrada. */
    public static function updateValue(string $subId, int $cents): void
    {
        if (!self::configured() || !str_starts_with($subId, 'sub_') || str_starts_with($subId, 'sim_')) return;
        self::call('POST', '/subscriptions/' . rawurlencode($subId), ['value' => round($cents / 100, 2), 'updatePendingPayments' => true]);
    }

    /** Encerra a recorrência no Asaas (cancelamento). O acesso já pago continua pela regra local. */
    public static function cancel(string $subId): void
    {
        if (!self::configured() || !str_starts_with($subId, 'sub_') || str_starts_with($subId, 'sim_')) return;
        self::call('DELETE', '/subscriptions/' . rawurlencode($subId));
    }
}
