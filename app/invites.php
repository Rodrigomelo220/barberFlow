<?php
declare(strict_types=1);

/** Convite de funcionário: o dono gera um link (e, com SMTP, ele vai por e-mail); a pessoa cria a conta e passa a ver só o que o papel permite. */
final class Invites
{
    public const ROLES = ['barber' => 'Atendente (vê e gerencia só a própria agenda)', 'manager' => 'Gerente (vê toda a agenda, clientes e financeiro)'];

    /** @return string link do convite (mostrado uma única vez ao dono) */
    public static function create(int $shopId, int $actorId, string $email, string $role, ?int $professionalId): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new BusinessError('E-mail inválido.');
        if (!isset(self::ROLES[$role])) throw new BusinessError('Papel inválido.');
        $plan = Billing::state($shopId)['plan'];
        if (!$plan || ($plan['max_professionals'] !== null && (int)$plan['max_professionals'] <= 1)) throw new BusinessError('Acesso para funcionários está disponível no plano com equipe.', 'feature_not_in_plan', 403);
        if ($professionalId !== null && !DB::one('SELECT id FROM professionals WHERE id = ? AND shop_id = ?', [$professionalId, $shopId])) throw new BusinessError('Profissional não encontrado.', 'not_found', 404);
        if (DB::one('SELECT 1 FROM shop_members m JOIN users u ON u.id = m.user_id WHERE m.shop_id = ? AND u.email = ?', [$shopId, $email])) throw new BusinessError('Esta pessoa já tem acesso ao negócio.');
        if ((int)DB::val('SELECT COUNT(*) FROM staff_invites WHERE shop_id = ? AND accepted_at IS NULL AND expires_at > ?', [$shopId, dt_db(now_utc())]) >= 20) throw new BusinessError('Há convites demais em aberto. Cancele alguns.');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::tx(function () use ($shopId, $actorId, $email, $role, $professionalId, $token) {
            DB::exec('DELETE FROM staff_invites WHERE shop_id = ? AND email = ? AND accepted_at IS NULL', [$shopId, $email]);   // novo convite substitui o anterior
            DB::insert('staff_invites', ['shop_id' => $shopId, 'email' => $email, 'role' => $role, 'professional_id' => $professionalId, 'token_hash' => hash('sha256', $token),
                'expires_at' => dt_db(now_utc()->modify('+7 days')), 'created_by' => $actorId, 'created_at' => dt_db(now_utc())]);
            audit($shopId, $actorId, 'staff.invited', 'staff_invite', null, $role . ($professionalId ? ' · prof ' . $professionalId : ''));
        });
        $link = abs_url('/convite/' . $token);
        $shopName = (string)DB::val('SELECT name FROM shops WHERE id = ?', [$shopId]);
        Mailer::send($email, 'Convite para ' . $shopName . ' — ' . brand(), "Você foi convidado(a) para acessar a agenda de $shopName.\nCrie seu acesso (válido por 7 dias):\n$link\n");
        return $link;
    }

    public static function find(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{30,60}$/', $token)) return null;
        return DB::one('SELECT i.*, s.name AS shop_name FROM staff_invites i JOIN shops s ON s.id = i.shop_id WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.expires_at > ?', [hash('sha256', $token), dt_db(now_utc())]);
    }

    /** Vincula o usuário (já autenticado e com o e-mail do convite) ao negócio. */
    public static function accept(string $token, array $user): int
    {
        $inv = self::find($token) ?? throw new BusinessError('Convite inválido ou expirado. Peça um novo ao responsável.', 'bad_token');
        if (mb_strtolower((string)$user['email']) !== mb_strtolower((string)$inv['email'])) throw new BusinessError('Este convite é para ' . $inv['email'] . '. Entre com esse e-mail.', 'wrong_account', 403);
        DB::tx(function () use ($inv, $user) {
            $fresh = DB::one('SELECT id FROM staff_invites WHERE id = ? AND accepted_at IS NULL FOR UPDATE', [$inv['id']]);
            if (!$fresh) throw new BusinessError('Convite já utilizado.', 'bad_token');
            DB::q('INSERT IGNORE INTO shop_members (shop_id, user_id, role) VALUES (?, ?, ?)', [$inv['shop_id'], $user['id'], $inv['role']]);
            if ($inv['professional_id']) DB::exec('UPDATE professionals SET user_id = ? WHERE id = ? AND shop_id = ? AND user_id IS NULL', [$user['id'], $inv['professional_id'], $inv['shop_id']]);
            DB::exec('UPDATE staff_invites SET accepted_at = ? WHERE id = ?', [dt_db(now_utc()), $inv['id']]);
            audit((int)$inv['shop_id'], (int)$user['id'], 'staff.accepted', 'user', $user['id'], $inv['role']);
        });
        return (int)$inv['shop_id'];
    }

    public static function pending(int $shopId): array
    {
        return DB::all('SELECT i.id, i.email, i.role, i.expires_at, p.name AS professional FROM staff_invites i LEFT JOIN professionals p ON p.id = i.professional_id WHERE i.shop_id = ? AND i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC', [$shopId, dt_db(now_utc())]);
    }
    public static function members(int $shopId): array
    {
        return DB::all("SELECT u.id, u.name, u.email, m.role, (SELECT GROUP_CONCAT(p.name SEPARATOR ', ') FROM professionals p WHERE p.shop_id = m.shop_id AND p.user_id = u.id) AS professional FROM shop_members m JOIN users u ON u.id = m.user_id WHERE m.shop_id = ? ORDER BY (m.role = 'owner') DESC, u.name", [$shopId]);
    }
    public static function cancel(int $shopId, int $actorId, int $inviteId): void
    {
        if (!DB::exec('DELETE FROM staff_invites WHERE id = ? AND shop_id = ? AND accepted_at IS NULL', [$inviteId, $shopId])) throw new BusinessError('Convite não encontrado.', 'not_found', 404);
        audit($shopId, $actorId, 'staff.invite_canceled', 'staff_invite', $inviteId);
    }
    /** Remove o acesso (nunca o do dono, nunca o próprio). O histórico de atendimentos continua. */
    public static function revoke(int $shopId, int $actorId, int $userId): void
    {
        if ($userId === $actorId) throw new BusinessError('Você não pode remover o próprio acesso.', 'forbidden', 403);
        $m = DB::one('SELECT role FROM shop_members WHERE shop_id = ? AND user_id = ?', [$shopId, $userId]);
        if (!$m) throw new BusinessError('Acesso não encontrado.', 'not_found', 404);
        if ($m['role'] === 'owner') throw new BusinessError('O acesso do dono não pode ser removido.', 'forbidden', 403);
        DB::tx(function () use ($shopId, $userId, $actorId) {
            DB::exec('DELETE FROM shop_members WHERE shop_id = ? AND user_id = ?', [$shopId, $userId]);
            DB::exec('UPDATE professionals SET user_id = NULL WHERE shop_id = ? AND user_id = ?', [$shopId, $userId]);
            audit($shopId, $actorId, 'staff.revoked', 'user', $userId);
        });
    }
}
