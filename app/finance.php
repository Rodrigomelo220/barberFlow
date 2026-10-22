<?php
declare(strict_types=1);

/**
 * Financeiro da barbearia. Três coisas diferentes:
 *  - previsto  = agendamentos confirmados;  realizado = concluídos;
 *  - recebido  = dinheiro efetivamente lançado como recebido (menos estornos).
 * Concluir um atendimento NÃO lança pagamento.
 */
final class Finance
{
    public const METHODS = ['cash' => 'Dinheiro', 'pix' => 'Pix', 'debit' => 'Débito', 'credit' => 'Crédito', 'other' => 'Outro'];

    /** $own: ids de profissionais que o ator pode operar (null = todos). */
    private static function appt(int $shopId, int $id, ?array $own): array
    {
        $a = DB::one('SELECT id, status, total_price_cents, professional_id, customer_id FROM appointments WHERE id = ? AND shop_id = ? FOR UPDATE', [$id, $shopId]);
        if (!$a) throw new BusinessError('Atendimento não encontrado.', 'not_found', 404);
        if ($own !== null && !in_array((int)$a['professional_id'], $own, true)) throw new BusinessError('Você só pode lançar pagamentos dos seus atendimentos.', 'forbidden', 403);
        return $a;
    }

    public static function paidSoFar(int $apptId): int
    {
        return (int)DB::val("SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount_cents ELSE amount_cents END),0) FROM shop_payments WHERE appointment_id = ? AND status = 'received'", [$apptId]);
    }

    /** @return array{id:int,amount_cents:int,remaining_cents:int} */
    public static function recordPayment(int $shopId, int $userId, ?array $own, int $apptId, string $method, string $status = 'received', ?int $amountCents = null, string $notes = ''): array
    {
        if (!isset(self::METHODS[$method])) throw new BusinessError('Forma de pagamento inválida.');
        if (!in_array($status, ['received', 'pending'], true)) throw new BusinessError('Situação inválida.');
        return DB::tx(function () use ($shopId, $userId, $own, $apptId, $method, $status, $amountCents, $notes) {
            $a = self::appt($shopId, $apptId, $own);
            if ($a['status'] === 'canceled') throw new BusinessError('Atendimento cancelado não recebe pagamento.', 'canceled');
            // pendentes também contam como "já combinado" para não lançar duas vezes o mesmo valor
            $committed = (int)DB::val("SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount_cents ELSE amount_cents END),0) FROM shop_payments WHERE appointment_id = ? AND (status = 'received' OR status = 'pending')", [$apptId]);
            $remaining = (int)$a['total_price_cents'] - $committed;
            $amount = $amountCents ?? $remaining;
            if ($amount <= 0) throw new BusinessError('Este atendimento já está totalmente pago.', $amountCents === null ? 'already_paid' : 'invalid_amount');
            if ($amount > $remaining) throw new BusinessError('O valor excede o que falta receber (' . money($remaining) . ').', 'payment_exceeds');
            $pct = (float)DB::val('SELECT commission_percent FROM professionals WHERE id = ?', [$a['professional_id']]);
            $id = DB::insert('shop_payments', ['shop_id' => $shopId, 'appointment_id' => $apptId, 'professional_id' => $a['professional_id'], 'customer_id' => $a['customer_id'], 'kind' => 'payment',
                'amount_cents' => $amount, 'method' => $method, 'status' => $status, 'commission_percent' => $pct, 'received_at' => $status === 'received' ? dt_db(now_utc()) : null,
                'notes' => mb_substr($notes, 0, 300), 'created_by' => $userId, 'created_at' => dt_db(now_utc())]);
            audit($shopId, $userId, 'payment.recorded', 'shop_payment', $id, "$amount $method $status");
            return ['id' => $id, 'amount_cents' => $amount, 'remaining_cents' => $remaining - $amount];
        });
    }

    public static function markReceived(int $shopId, int $userId, ?array $own, int $paymentId): void
    {
        DB::tx(function () use ($shopId, $userId, $own, $paymentId) {
            $p = DB::one('SELECT * FROM shop_payments WHERE id = ? AND shop_id = ? FOR UPDATE', [$paymentId, $shopId]);
            if (!$p) throw new BusinessError('Pagamento não encontrado.', 'not_found', 404);
            if ($own !== null && (!$p['professional_id'] || !in_array((int)$p['professional_id'], $own, true))) throw new BusinessError('Sem permissão.', 'forbidden', 403);
            if ($p['kind'] !== 'payment' || $p['status'] !== 'pending') throw new BusinessError('Este lançamento não está pendente.', 'not_pending');
            DB::exec("UPDATE shop_payments SET status = 'received', received_at = ? WHERE id = ?", [dt_db(now_utc()), $paymentId]);
            audit($shopId, $userId, 'payment.received', 'shop_payment', $paymentId);
        });
    }

    public static function discardPending(int $shopId, int $userId, int $paymentId): void
    {
        if (!DB::exec("DELETE FROM shop_payments WHERE id = ? AND shop_id = ? AND kind = 'payment' AND status = 'pending'", [$paymentId, $shopId])) {
            throw new BusinessError('Só é possível descartar pagamentos pendentes. Para corrigir um recebimento, faça um estorno.', 'not_pending');
        }
        audit($shopId, $userId, 'payment.discarded', 'shop_payment', $paymentId);
    }

    public static function refund(int $shopId, int $userId, int $paymentId, ?int $amountCents = null, string $notes = ''): int
    {
        return DB::tx(function () use ($shopId, $userId, $paymentId, $amountCents, $notes) {
            $p = DB::one('SELECT * FROM shop_payments WHERE id = ? AND shop_id = ? FOR UPDATE', [$paymentId, $shopId]);
            if (!$p || $p['kind'] !== 'payment') throw new BusinessError('Pagamento não encontrado.', 'not_found', 404);
            if ($p['status'] !== 'received') throw new BusinessError('Só pagamentos recebidos podem ser estornados.', 'not_received');
            $already = (int)DB::val("SELECT COALESCE(SUM(amount_cents),0) FROM shop_payments WHERE refund_of = ? AND kind = 'refund'", [$paymentId]);
            $available = (int)$p['amount_cents'] - $already;
            if ($available <= 0) throw new BusinessError('Este pagamento já foi estornado por completo.', 'refund_exceeds');
            $amount = $amountCents ?? $available;
            if ($amount <= 0) throw new BusinessError('Valor de estorno inválido.', 'invalid_amount');
            if ($amount > $available) throw new BusinessError('O estorno excede o valor ainda disponível.', 'refund_exceeds');
            $id = DB::insert('shop_payments', ['shop_id' => $shopId, 'appointment_id' => $p['appointment_id'], 'professional_id' => $p['professional_id'], 'customer_id' => $p['customer_id'], 'kind' => 'refund',
                'refund_of' => $paymentId, 'amount_cents' => $amount, 'method' => $p['method'], 'status' => 'received', 'commission_percent' => $p['commission_percent'], 'received_at' => dt_db(now_utc()),
                'notes' => mb_substr($notes, 0, 300), 'created_by' => $userId, 'created_at' => dt_db(now_utc())]);
            audit($shopId, $userId, 'payment.refunded', 'shop_payment', $id, (string)$amount);
            return $id;
        });
    }

    public static function addExpense(int $shopId, int $userId, string $description, string $category, int $amountCents, ?string $method, string $paidOn): int
    {
        $description = trim($description);
        if (mb_strlen($description) < 2 || mb_strlen($description) > 120) throw new BusinessError('Informe a descrição da despesa.');
        if ($amountCents <= 0) throw new BusinessError('Valor inválido.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || !checkdate((int)substr($paidOn, 5, 2), (int)substr($paidOn, 8, 2), (int)substr($paidOn, 0, 4))) throw new BusinessError('Data inválida.');
        if ($method !== null && $method !== '' && !isset(self::METHODS[$method])) throw new BusinessError('Forma inválida.');
        $id = DB::insert('expenses', ['shop_id' => $shopId, 'description' => $description, 'category' => mb_substr(trim($category) ?: 'outros', 0, 40), 'amount_cents' => $amountCents, 'method' => $method ?: null, 'paid_on' => $paidOn, 'created_at' => dt_db(now_utc())]);
        audit($shopId, $userId, 'expense.created', 'expense', $id);
        return $id;
    }
    public static function deleteExpense(int $shopId, int $userId, int $id): void
    {
        if (!DB::exec('DELETE FROM expenses WHERE id = ? AND shop_id = ?', [$id, $shopId])) throw new BusinessError('Despesa não encontrada.', 'not_found', 404);
        audit($shopId, $userId, 'expense.deleted', 'expense', $id);
    }

    /** Período local → [from,to) em UTC e datas locais. */
    public static function period(string $tz, string $from, string $to): array
    {
        $z = new DateTimeZone($tz);
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $z) ?: null;
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $z) ?: null;
        if (!$f || !$t || $t < $f) {
            $n = now_utc()->setTimezone($z);
            $f = $n->modify('first day of this month')->setTime(0, 0);
            $t = $n->modify('last day of this month')->setTime(0, 0);
        }
        return ['from' => $f->setTimezone(new DateTimeZone('UTC')), 'to' => $t->modify('+1 day')->setTimezone(new DateTimeZone('UTC')), 'from_date' => $f->format('Y-m-d'), 'to_date' => $t->format('Y-m-d')];
    }

    public static function summary(int $shopId, array $p, string $tz): array
    {
        $fa = dt_db($p['from']); $ta = dt_db($p['to']);
        $ap = DB::one("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN total_price_cents END),0) AS scheduled, COALESCE(SUM(CASE WHEN status='completed' THEN total_price_cents END),0) AS realized,
              COALESCE(SUM(status='no_show'),0) AS no_show, COALESCE(SUM(status='canceled'),0) AS canceled FROM appointments WHERE shop_id = ? AND starts_at >= ? AND starts_at < ?", [$shopId, $fa, $ta]);
        $pay = DB::one("SELECT COALESCE(SUM(CASE WHEN kind='payment' AND status='received' THEN amount_cents END),0) AS received, COALESCE(SUM(CASE WHEN kind='refund' THEN amount_cents END),0) AS refunded
              FROM shop_payments WHERE shop_id = ? AND received_at >= ? AND received_at < ?", [$shopId, $fa, $ta]);
        $pending = (int)DB::val("SELECT COALESCE(SUM(amount_cents),0) FROM shop_payments WHERE shop_id = ? AND kind = 'payment' AND status = 'pending'", [$shopId]);
        $toReceive = (int)DB::val("SELECT COALESCE(SUM(a.total_price_cents - COALESCE(x.paid,0)),0) FROM appointments a
              LEFT JOIN (SELECT appointment_id, SUM(CASE WHEN kind='refund' THEN -amount_cents ELSE amount_cents END) AS paid FROM shop_payments WHERE status IN ('received','pending') GROUP BY appointment_id) x ON x.appointment_id = a.id
              WHERE a.shop_id = ? AND a.status = 'completed' AND a.starts_at >= ? AND a.starts_at < ? AND a.total_price_cents > COALESCE(x.paid,0)", [$shopId, $fa, $ta]);
        $exp = (int)DB::val('SELECT COALESCE(SUM(amount_cents),0) FROM expenses WHERE shop_id = ? AND paid_on >= ? AND paid_on <= ?', [$shopId, $p['from_date'], $p['to_date']]);
        $by = DB::all("SELECT method, SUM(CASE WHEN kind='refund' THEN -amount_cents ELSE amount_cents END) AS cents FROM shop_payments WHERE shop_id = ? AND status = 'received' AND received_at >= ? AND received_at < ? GROUP BY method ORDER BY method", [$shopId, $fa, $ta]);
        $net = (int)$pay['received'] - (int)$pay['refunded'];
        return ['scheduled' => (int)$ap['scheduled'], 'realized' => (int)$ap['realized'], 'to_receive' => $toReceive, 'pending' => $pending, 'received' => $net, 'refunded' => (int)$pay['refunded'],
            'expenses' => $exp, 'net' => $net - $exp, 'no_show' => (int)$ap['no_show'], 'canceled' => (int)$ap['canceled'], 'by_method' => $by];
    }

    /** Comissão = recebido líquido × % vigente NO DIA do lançamento (snapshot). */
    public static function commissions(int $shopId, array $p): array
    {
        return DB::all("SELECT pr.id AS professional_id, pr.name,
              COALESCE(SUM(CASE WHEN sp.kind='refund' THEN -sp.amount_cents ELSE sp.amount_cents END),0) AS net_cents,
              COALESCE(ROUND(SUM(CASE WHEN sp.kind='refund' THEN -sp.amount_cents ELSE sp.amount_cents END * sp.commission_percent / 100)),0) AS commission_cents,
              COUNT(DISTINCT CASE WHEN sp.kind='payment' THEN sp.appointment_id END) AS services
            FROM professionals pr LEFT JOIN shop_payments sp ON sp.professional_id = pr.id AND sp.status = 'received' AND sp.received_at >= ? AND sp.received_at < ?
            WHERE pr.shop_id = ? GROUP BY pr.id, pr.name ORDER BY net_cents DESC", [dt_db($p['from']), dt_db($p['to']), $shopId]);
    }

    public static function payments(int $shopId, array $p, int $limit = 100): array
    {
        return DB::all("SELECT sp.id, sp.kind, sp.amount_cents, sp.method, sp.status, sp.received_at, sp.created_at, sp.notes, sp.appointment_id, pr.name AS professional, c.name AS customer
            FROM shop_payments sp LEFT JOIN professionals pr ON pr.id = sp.professional_id LEFT JOIN customers c ON c.id = sp.customer_id
            WHERE sp.shop_id = ? AND ((sp.received_at >= ? AND sp.received_at < ?) OR (sp.status = 'pending' AND sp.created_at >= ? AND sp.created_at < ?))
            ORDER BY COALESCE(sp.received_at, sp.created_at) DESC LIMIT " . (int)$limit, [$shopId, dt_db($p['from']), dt_db($p['to']), dt_db($p['from']), dt_db($p['to'])]);
    }

    public static function expenses(int $shopId, array $p): array
    {
        return DB::all('SELECT id, description, category, amount_cents, method, paid_on FROM expenses WHERE shop_id = ? AND paid_on >= ? AND paid_on <= ? ORDER BY paid_on DESC, id DESC', [$shopId, $p['from_date'], $p['to_date']]);
    }

    /** Célula de CSV: neutraliza injeção de fórmula; números em pt-BR. */
    public static function csvCell($v): string
    {
        if ($v === null) return '';
        if (is_int($v) || is_float($v)) return str_replace('.', ',', (string)$v);
        $s = (string)$v;
        if ($s !== '' && str_contains('=+-@', $s[0]) || ($s !== '' && ($s[0] === "\t" || $s[0] === "\r"))) $s = "'" . $s;
        if (preg_match('/[;"\r\n]/', $s)) $s = '"' . str_replace('"', '""', $s) . '"';
        return $s;
    }
    public static function toCsv(array $headers, array $rows): string
    {
        $out = "\xEF\xBB\xBF" . implode(';', array_map([self::class, 'csvCell'], $headers)) . "\r\n";
        foreach ($rows as $r) $out .= implode(';', array_map([self::class, 'csvCell'], $r)) . "\r\n";
        return $out;
    }
    public static function paymentsCsv(int $shopId, array $p, string $tz): string
    {
        $rows = [];
        foreach (self::payments($shopId, $p, 1000) as $r) {
            $rows[] = [dt_local($r['received_at'] ?? $r['created_at'], $tz, 'Y-m-d H:i'), $r['kind'] === 'refund' ? 'Estorno' : 'Pagamento',
                ($r['kind'] === 'refund' ? -1 : 1) * (int)$r['amount_cents'] / 100, self::METHODS[$r['method']] ?? $r['method'], $r['status'] === 'received' ? 'Recebido' : 'Pendente', $r['professional'], $r['customer'], $r['notes']];
        }
        return self::toCsv(['Data', 'Tipo', 'Valor (R$)', 'Forma', 'Situação', 'Profissional', 'Cliente', 'Observação'], $rows);
    }
}
