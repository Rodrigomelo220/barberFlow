<?php
declare(strict_types=1);

/**
 * Segredos de integração (senha SMTP, chave da API do Asaas, token do WhatsApp) ficam no banco, criptografados com
 * AES-256-GCM. A chave deriva do config.php: quem só tem o banco (backup, SQL injection) não lê os segredos.
 * Nunca vão para telas, logs ou mensagens de erro.
 */
final class Secrets
{
    private static function key(): string
    {
        $base = (string)cfg('app.secret', '') ?: ((string)cfg('app.webhook_token', '') . '|' . (string)cfg('app.cron_key', ''));
        if (strlen($base) < 16) throw new RuntimeException('config.php sem chaves para proteger segredos.');
        return hash('sha256', 'bf-secrets|' . $base, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException('Falha ao criptografar.');
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): ?string
    {
        if (!str_starts_with($blob, 'v1:')) return null;
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }

    /** Lê um grupo de ajustes (array) guardado em meta[$name], já descriptografado. Vazio se não existir ou ilegível. */
    public static function get(string $name): array
    {
        try {
            $v = DB::val('SELECT v FROM meta WHERE k = ?', ['secret_' . $name]);
            if (!is_string($v) || $v === '') return [];
            $j = self::decrypt($v);
            $a = $j === null ? null : json_decode($j, true);
            return is_array($a) ? $a : [];
        } catch (Throwable $e) { return []; }
    }

    public static function put(string $name, array $data): void
    {
        DB::q('REPLACE INTO meta (k, v) VALUES (?, ?)', ['secret_' . $name, self::encrypt(json_encode($data, JSON_UNESCAPED_UNICODE))]);
    }

    /** Mostra só o final de um segredo ("••••abcd") para a tela de administração. */
    public static function mask(string $s): string { return $s === '' ? '' : str_repeat('•', 6) . substr($s, -4); }
}
