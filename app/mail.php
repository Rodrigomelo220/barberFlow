<?php
declare(strict_types=1);

/** Cliente SMTP mínimo (sem Composer): SSL direto (465), STARTTLS (587) ou sem criptografia (só para testes locais). */
final class Smtp
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(private array $c) {}

    private function read(): string
    {
        $all = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $all;
    }
    private function expect(string $resp, array $codes, string $ctx): void
    {
        if (!in_array((int)substr($resp, 0, 3), $codes, true)) throw new RuntimeException("SMTP: resposta inesperada em $ctx (" . trim(substr($resp, 0, 120)) . ')');
    }
    private function cmd(string $line, array $codes, string $ctx): string
    {
        fwrite($this->fp, $line . "\r\n");
        $r = $this->read(); $this->expect($r, $codes, $ctx); return $r;
    }

    public static function encodeHeader(string $s): string { return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?='; }

    /** Monta a mensagem (texto puro UTF-8). Remove quebras de linha de cabeçalhos para impedir injeção. */
    public static function build(string $fromName, string $fromEmail, string $to, string $subject, string $body, string $host): string
    {
        $clean = fn(string $v) => trim(preg_replace('/[\r\n]+/', ' ', $v));
        $fromName = $clean($fromName); $fromEmail = $clean($fromEmail); $to = $clean($to); $subject = $clean($subject);
        $h = ['Date: ' . date('r'), 'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . " <$fromEmail>" : $fromEmail), "To: <$to>",
            'Subject: ' . self::encodeHeader($subject), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($host ?: 'localhost') . '>', 'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        return implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }

    public function send(string $to, string $subject, string $body): void
    {
        $host = (string)$this->c['host']; $port = (int)$this->c['port']; $sec = (string)($this->c['security'] ?? 'tls');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("SMTP: não consegui conectar em $host:$port ($errstr)");
        $this->fp = $fp; stream_set_timeout($fp, 15);
        try {
            $this->expect($this->read(), [220], 'conexão');
            $ehlo = $this->cmd('EHLO ' . (parse_url((string)cfg('app.url', ''), PHP_URL_HOST) ?: 'localhost'), [250], 'EHLO');
            if ($sec === 'tls') {
                $this->cmd('STARTTLS', [220], 'STARTTLS');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: falha ao iniciar TLS');
                $ehlo = $this->cmd('EHLO ' . (parse_url((string)cfg('app.url', ''), PHP_URL_HOST) ?: 'localhost'), [250], 'EHLO');
            }
            if (($this->c['user'] ?? '') !== '') {
                $this->cmd('AUTH LOGIN', [334], 'AUTH');
                $this->cmd(base64_encode((string)$this->c['user']), [334], 'usuário');
                $this->cmd(base64_encode((string)$this->c['pass']), [235], 'senha (confira usuário e senha)');
            }
            $from = (string)$this->c['from_email'];
            $this->cmd("MAIL FROM:<$from>", [250], 'MAIL FROM');
            $this->cmd("RCPT TO:<$to>", [250, 251], 'RCPT TO');
            $this->cmd('DATA', [354], 'DATA');
            $msg = self::build((string)($this->c['from_name'] ?? ''), $from, $to, $subject, $body, (string)parse_url((string)cfg('app.url', ''), PHP_URL_HOST));
            $msg = preg_replace('/^\./m', '..', $msg);
            fwrite($fp, $msg . "\r\n.\r\n");
            $this->expect($this->read(), [250], 'envio');
            @fwrite($fp, "QUIT\r\n");
        } finally { @fclose($fp); $this->fp = null; }
    }
}

final class Mailer
{
    public static function settings(): array { return Secrets::get('smtp'); }
    public static function configured(): bool { $s = self::settings(); return !empty($s['host']) && !empty($s['port']) && !empty($s['from_email']); }

    /** Envia (SMTP se configurado; senão mail() quando liberado). Sempre registra no log local, sem a senha. Devolve null se ok, ou a mensagem de erro. */
    public static function send(string $to, string $subject, string $body): ?string
    {
        $err = null;
        if (self::configured()) {
            try { (new Smtp(self::settings()))->send($to, $subject, $body); }
            catch (Throwable $e) { $err = $e->getMessage(); }
        } elseif (cfg('app.send_mail', false)) {
            $from = cfg('app.mail_from', brand() . ' <nao-responda@localhost>');
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "From: $from\r\nContent-Type: text/plain; charset=UTF-8");
            if (!$ok) $err = 'mail() recusou o envio';
        }
        $sent = $err === null && (self::configured() || cfg('app.send_mail', false));   // enviado de verdade: não guarda o corpo (pode ter link de senha)
        $dir = BF_ROOT . '/storage';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/mail.log', '[' . date('c') . "] Para: $to | $subject" . ($err ? " | ERRO: $err" : (self::configured() || cfg('app.send_mail', false) ? ' | enviado' : ' | não enviado (sem SMTP)')) . "\n" . ($sent ? '' : $body . "\n") . "---\n", FILE_APPEND | LOCK_EX);
        }
        return $err;
    }
}
