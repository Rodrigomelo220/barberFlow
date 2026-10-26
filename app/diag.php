<?php
declare(strict_types=1);

/**
 * Diagnóstico do servidor: o que está pronto para vender e o que ainda precisa de atenção.
 * Cada item: [nível ok|warn|fail, título, explicação do que fazer]. Nunca mostra segredos.
 */
final class Diag
{
    public static function run(bool $probeHttp = true): array
    {
        $r = [];
        $add = function (string $group, string $lvl, string $title, string $hint = '') use (&$r) { $r[$group][] = ['lvl' => $lvl, 'title' => $title, 'hint' => $hint]; };

        // Servidor
        $add('Servidor', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION, 'É preciso PHP 8.0 ou mais novo.');
        foreach (['pdo_mysql' => 'acesso ao banco', 'openssl' => 'criptografia e e-mail seguro', 'mbstring' => 'textos com acento', 'json' => 'dados', 'curl' => 'integrações (Asaas, WhatsApp)'] as $ext => $why) {
            $has = extension_loaded($ext);
            $add('Servidor', $has ? 'ok' : ($ext === 'curl' ? 'warn' : 'fail'), "Extensão $ext" . ($has ? '' : ' ausente'), $has ? '' : "Peça à hospedagem para ativar ($why).");
        }
        $add('Servidor', 'ok', 'Fuso do servidor: ' . date_default_timezone_get(), '');
        $https = is_https();
        $add('Servidor', $https ? 'ok' : 'fail', $https ? 'Site em HTTPS' : 'Site sem HTTPS', 'Sem HTTPS as senhas e a sessão trafegam abertas. Ative o certificado (Let\'s Encrypt) antes de vender.');
        $url = (string)cfg('app.url', '');
        if ($url !== '' && $https && !str_starts_with($url, 'https://')) $add('Servidor', 'warn', 'app.url do config.php usa http://', 'Corrija para https:// (links de e-mail e webhooks).');

        // Banco
        try {
            $v = (string)DB::val('SELECT VERSION()');
            $add('Banco de dados', 'ok', 'Conectado (' . $v . ')', '');
            $tables = (int)DB::val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
            $add('Banco de dados', $tables >= 20 ? 'ok' : 'warn', "$tables tabelas", 'Poucas tabelas: rode o atualizar.php.');
        } catch (Throwable $e) {
            $add('Banco de dados', 'fail', 'Sem conexão com o banco', 'Confira usuário, senha e servidor no config.php.');
        }

        // Pastas
        foreach (['storage', 'storage/sessions', 'uploads'] as $d) {
            $p = BF_ROOT . '/' . $d; if (!is_dir($p)) @mkdir($p, 0775, true);
            $w = is_dir($p) && is_writable($p);
            $add('Pastas', $w ? 'ok' : 'fail', "$d/ " . ($w ? 'com permissão de escrita' : 'sem permissão de escrita'), $w ? '' : 'Dê permissão de escrita (chmod 775) a esta pasta.');
        }
        $cfgPath = BF_ROOT . '/config.php';
        if (is_file($cfgPath)) { $perm = @fileperms($cfgPath); $world = $perm !== false && ($perm & 0004) && stripos(PHP_OS, 'WIN') !== 0; $add('Pastas', $world ? 'warn' : 'ok', $world ? 'config.php legível por qualquer usuário do servidor' : 'config.php com permissão restrita', $world ? 'Em servidor compartilhado use chmod 640 (ou 600) no config.php.' : ''); }

        // Segurança de arquivos (testa de fora, pelo próprio site)
        if ($probeHttp && function_exists('curl_init') && $url !== '' && !preg_match('#^https?://(localhost|127\.|\[::1\])#', $url)) {
            foreach (['/config.php' => 'config.php', '/storage/mail.log' => 'storage/mail.log', '/sql/schema.sql' => 'sql/schema.sql'] as $path => $label) {
                $code = self::head(rtrim($url, '/') . $path);
                if ($code === null) { $add('Segurança', 'warn', "Não consegui testar $label", 'Abra o endereço no navegador: deve dar erro 403 ou 404.'); continue; }
                $exposed = $code === 200;
                $add('Segurança', $exposed ? 'fail' : 'ok', $exposed ? "$label está acessível pela internet!" : "$label protegido (HTTP $code)", $exposed ? 'Seu servidor ignora o .htaccess. No Nginx, bloqueie estes caminhos (veja DEPLOY.md). Troque senhas após corrigir.' : '');
            }
        }

        // Rotina automática
        $last = DB::val("SELECT v FROM meta WHERE k = 'last_tick'");
        $age = $last === null ? null : time() - (int)$last;
        $add('Rotinas', $age === null ? 'warn' : ($age < 900 ? 'ok' : 'warn'), $age === null ? 'Rotina automática ainda não rodou' : 'Última rotina há ' . ($age < 120 ? 'instantes' : (int)round($age / 60) . ' min'), $age !== null && $age < 900 ? '' : 'Lembretes e a cobrança dependem dela. Configure o cron (DEPLOY.md) ou aguarde alguma visita ao site.');
        $add('Rotinas', (string)cfg('app.cron_key', '') !== '' ? 'ok' : 'warn', (string)cfg('app.cron_key', '') !== '' ? 'Chave do cron configurada' : 'Sem chave do cron', 'Sem ela só roda quando alguém visita o site.');

        // Operação
        $live = cfg('app.mode', 'demo') === 'live';
        $add('Operação', $live ? 'ok' : 'warn', $live ? 'Modo produção' : 'Modo demonstração', $live ? '' : 'No modo demonstração a cobrança e as mensagens são simuladas. Para vender, use o modo produção (app.mode no config.php).');
        $add('Operação', Mailer::configured() ? 'ok' : 'warn', Mailer::configured() ? 'E-mail (SMTP) configurado' : 'E-mail (SMTP) não configurado', 'Sem ele ninguém recupera a senha nem recebe convite por e-mail. Configure em Sistema.');
        $add('Operação', Asaas::configured() ? 'ok' : 'warn', Asaas::configured() ? 'Asaas configurado (' . (Asaas::env() === 'production' ? 'produção' : 'teste') . ')' : 'Cobrança automática (Asaas) não configurada', 'Sem ela você registra pagamentos manualmente (Pix).');
        if (Asaas::configured() && Asaas::env() === 'sandbox' && $live) $add('Operação', 'warn', 'Asaas em ambiente de teste com o sistema em produção', 'Cobranças de teste não geram dinheiro real. Troque a chave quando for vender.');
        $add('Operação', WhatsApp::configured() ? 'ok' : 'warn', WhatsApp::configured() ? 'WhatsApp Cloud API configurado' : 'WhatsApp automático não configurado', 'Sem ele, o painel mostra o botão de envio com um toque (funciona de verdade).');
        $tok = (string)cfg('app.webhook_token', '');
        $add('Operação', strlen($tok) >= 24 ? 'ok' : 'fail', strlen($tok) >= 24 ? 'Token do webhook forte' : 'Token do webhook fraco ou ausente', 'Gere um token longo no config.php.');
        $sec = (string)cfg('app.secret', '');
        $add('Operação', strlen($sec) >= 24 ? 'ok' : 'warn', strlen($sec) >= 24 ? 'Chave de criptografia dedicada' : 'Usando o token do webhook como chave de criptografia', 'Adicione app.secret (32+ caracteres aleatórios) ao config.php ANTES de salvar senhas/chaves; trocar depois exige salvar de novo.');
        return $r;
    }

    private static function head(string $url): ?int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'SaaS-diagnostico']);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_errno($ch); curl_close($ch);
        return $err || $code === 0 ? null : $code;
    }

    public static function summary(array $r): array
    {
        $c = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($r as $items) foreach ($items as $i) $c[$i['lvl']]++;
        return $c;
    }
}
