<?php
declare(strict_types=1);

final class Perm
{
    private const MAP = [
        'owner' => ['*'],
        'manager' => ['agenda.view_all', 'agenda.manage', 'customers.view', 'customers.manage', 'services.manage', 'professionals.manage', 'payments.record', 'finance.view', 'finance.manage', 'settings.manage', 'messages.view'],
        'barber' => ['agenda.view_all', 'agenda.manage_own', 'customers.view', 'customers.manage', 'payments.record'],
    ];
    public static function can(string $role, string $perm): bool
    {
        $p = self::MAP[$role] ?? [];
        return in_array('*', $p, true) || in_array($perm, $p, true) || ($perm === 'agenda.manage_own' && in_array('agenda.manage', $p, true));
    }
}

final class Auth
{
    private static ?array $user = null;

    public static function validatePassword(string $pw): void
    {
        if (mb_strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            throw new BusinessError('A senha precisa ter ao menos 10 caracteres, com letras e números.', 'weak_password');
        }
    }

    public static function signup(string $name, string $email, string $pw, string $ip = ''): int
    {
        $name = trim($name); $email = mb_strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new BusinessError('Informe seu nome.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new BusinessError('E-mail inválido.');
        self::validatePassword($pw);
        if ($ip !== '' && !rate_limit("signup:ip:$ip", 10, 3600)) throw new BusinessError('Muitas tentativas. Tente mais tarde.', 'rate_limited', 429);
        try {
            $id = DB::insert('users', ['email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'name' => $name, 'created_at' => dt_db(now_utc())]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) throw new BusinessError('Este e-mail já está cadastrado. Tente entrar.', 'email_taken');
            throw $e;
        }
        audit(null, $id, 'user.signed_up', 'user', $id);
        return $id;
    }

    /** @return int id do usuário; lança BusinessError com mensagem genérica em qualquer falha. */
    public static function login(string $email, string $pw, string $ip = ''): int
    {
        $email = mb_strtolower(trim($email));
        $msg = 'E-mail ou senha incorretos.';
        if (!rate_limit("login:ip:$ip", 30, 600) || !rate_limit('login:email:' . $email, 10, 900)) {
            throw new BusinessError('Muitas tentativas. Aguarde alguns minutos.', 'rate_limited', 429);
        }
        $u = DB::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
        // custo constante: sempre confere um hash
        $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuMZk2m0A9PJ8x1d9wX3xUo3oV9mQz1yK';
        $ok = password_verify($pw, $hash);
        if (!$u || !$ok) throw new BusinessError($msg, 'bad_credentials', 401);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        }
        return (int)$u['id'];
    }

    public static function startSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        unset($_SESSION['shop_id']);
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) return null;
        $u = DB::one('SELECT id, email, name, is_admin FROM users WHERE id = ?', [(int)$id]);
        return self::$user = $u;
    }
    public static function forget(): void { self::$user = null; }

    public static function requestReset(string $email, string $ip = ''): void
    {
        $email = mb_strtolower(trim($email));
        if (!rate_limit("reset:email:$email", 3, 3600) || !rate_limit("reset:ip:$ip", 10, 3600)) return; // resposta igual: não revela nada
        $u = DB::one('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$u) return;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::exec('UPDATE users SET reset_token_hash = ?, reset_expires = ? WHERE id = ?', [hash('sha256', $token), dt_db(now_utc()->modify('+1 hour')), $u['id']]);
        Mailer::send($email, 'Redefinição de senha', "Para criar uma nova senha, abra (válido por 1 hora):\n" . abs_url('/redefinir-senha/' . $token) . "\nSe não foi você, ignore esta mensagem.");
    }

    public static function resetPassword(string $token, string $pw): void
    {
        self::validatePassword($pw);
        $u = DB::one('SELECT id FROM users WHERE reset_token_hash = ? AND reset_expires > ?', [hash('sha256', $token), dt_db(now_utc())]);
        if (!$u) throw new BusinessError('Link inválido ou expirado. Peça um novo.', 'bad_token');
        DB::exec('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        audit(null, (int)$u['id'], 'user.password_reset', 'user', $u['id']);
    }
}

/** Contexto da barbearia ativa. O shop_id da sessão só vale se o usuário for membro — conferido a cada requisição. */
final class Ctx
{
    public static function memberships(int $userId): array
    {
        return DB::all('SELECT s.id, s.name, s.slug, s.timezone, m.role FROM shop_members m JOIN shops s ON s.id = m.shop_id WHERE m.user_id = ? ORDER BY s.created_at', [$userId]);
    }

    public static function load(array $user, ?int $preferred = null): ?array
    {
        $ms = self::memberships((int)$user['id']);
        if (!$ms) return null;
        $m = null;
        foreach ($ms as $x) if ((int)$x['id'] === $preferred) $m = $x;
        $m ??= $ms[0];
        $shop = DB::one('SELECT * FROM shops WHERE id = ?', [$m['id']]);
        $billing = Billing::state((int)$m['id']);
        return ['user' => $user, 'shop' => $shop, 'shop_id' => (int)$m['id'], 'role' => $m['role'], 'tz' => $shop['timezone'], 'billing' => $billing, 'memberships' => $ms];
    }

    public static function can(array $ctx, string $perm): bool { return Perm::can($ctx['role'], $perm); }
    public static function need(array $ctx, string $perm): void
    {
        if (!self::can($ctx, $perm)) render_error(403, 'Sem permissão', 'Você não tem permissão para esta área.');
    }
    /** Escrita só com acesso completo. */
    public static function writable(array $ctx): bool { return $ctx['billing']['access']['level'] === 'full'; }
    public static function needWritable(array $ctx): void
    {
        if (!self::writable($ctx)) throw new BusinessError($ctx['billing']['access']['message'] ?? 'Assinatura pendente.', 'billing_restricted', 402);
    }
    /** Profissionais que o usuário pode operar; null = todos. */
    public static function ownProfessionalIds(array $ctx): ?array
    {
        if (Perm::can($ctx['role'], 'agenda.manage')) return null;
        return array_map('intval', array_column(DB::all('SELECT id FROM professionals WHERE shop_id = ? AND user_id = ?', [$ctx['shop_id'], $ctx['user']['id']]), 'id'));
    }
}
