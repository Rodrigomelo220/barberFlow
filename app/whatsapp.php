<?php
declare(strict_types=1);

/**
 * WhatsApp Business Cloud API (Meta). Mensagens iniciadas pelo negócio fora da janela de 24 h só podem ser enviadas por
 * MODELO (template) aprovado — por isso cada tipo de mensagem tem um modelo com 4 variáveis: {{1}} primeiro nome,
 * {{2}} nome do negócio, {{3}} data e hora, {{4}} serviços. O token fica criptografado no banco.
 * Sem esta integração, as mensagens ficam na lista "Para enviar" com botão de envio por wa.me (funciona sem API).
 */
final class WhatsApp
{
    public const KINDS = ['confirmacao', 'lembrete', 'cancelamento', 'remarcado'];
    public const TEMPLATE_TEXT = [
        'confirmacao' => 'Olá, {{1}}! Seu horário na {{2}} está confirmado para {{3}} ({{4}}).',
        'lembrete' => 'Olá, {{1}}! Lembrete: você tem horário na {{2}} em {{3}} ({{4}}).',
        'cancelamento' => 'Olá, {{1}}. Seu horário na {{2}} em {{3}} foi cancelado.',
        'remarcado' => 'Olá, {{1}}! Seu horário na {{2}} foi remarcado para {{3}}.',
    ];

    public static function settings(): array { return Secrets::get('whatsapp'); }
    public static function configured(): bool { $s = self::settings(); return !empty($s['token']) && !empty($s['phone_number_id']); }
    public static function baseUrl(): string { return (string)cfg('app.whatsapp_base_url', '') ?: 'https://graph.facebook.com'; }

    /** @return string|null null = enviado; senão o motivo da falha (sem dados sensíveis). */
    public static function send(string $phone, string $kind, array $params): ?string
    {
        $s = self::settings();
        if (empty($s['token']) || empty($s['phone_number_id'])) return 'WhatsApp não configurado';
        $tpl = (string)($s['templates'][$kind] ?? '');
        if ($tpl === '') return "sem modelo configurado para '$kind'";
        $body = ['messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $phone), 'type' => 'template',
            'template' => ['name' => $tpl, 'language' => ['code' => (string)($s['lang'] ?? 'pt_BR')],
                'components' => [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$p)), 0, 120) ?: '-'], array_slice($params, 0, self::paramCount($kind)))]]]];
        $url = self::baseUrl() . '/' . rawurlencode((string)($s['api_version'] ?? 'v21.0')) . '/' . rawurlencode((string)$s['phone_number_id']) . '/messages';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s['token'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($out === false) return 'falha de rede: ' . mb_substr($err, 0, 80);
        if ($code >= 200 && $code < 300) return null;
        $j = json_decode((string)$out, true);
        $m = is_array($j) && !empty($j['error']['message']) ? (string)$j['error']['message'] : '';
        return "HTTP $code" . ($m !== '' ? ': ' . mb_substr($m, 0, 140) : '');
    }

    /** Quantas variáveis o modelo de cada tipo usa. */
    public static function paramCount(string $kind): int { return preg_match_all('/\{\{\d\}\}/', self::TEMPLATE_TEXT[$kind] ?? '') ?: 0; }

    public static function waLink(string $phone, string $text): string { return 'https://wa.me/' . preg_replace('/\D/', '', $phone) . '?text=' . rawurlencode($text); }
}
