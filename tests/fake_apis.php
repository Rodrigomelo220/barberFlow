<?php
// Servidor falso (php -S) que imita as APIs do Asaas e do WhatsApp Cloud para os testes. Registra cada chamada em /tmp/bf_fake_api.log.
$log = '/tmp/bf_fake_api.log';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $m = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input'); $body = json_decode($raw, true);
$h = function (string $n) { return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? null; };
file_put_contents($log, json_encode(['m' => $m, 'uri' => $uri, 'body' => $body, 'token' => $h('access_token'), 'auth' => $h('authorization'), 'ua' => $h('user_agent')]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
$out = function ($code, $data) { http_response_code($code); echo json_encode($data); };
$state = '/tmp/bf_fake_api.state'; $st = is_file($state) ? json_decode(file_get_contents($state), true) : ['n' => 0, 'mode' => 'ok', 'val' => []];
$st += ['val' => []];
$save = function () use (&$st, $state) { file_put_contents($state, json_encode($st)); };
if ($uri === '/__mode') { $st['mode'] = $_GET['m'] ?? 'ok'; $save(); echo 'ok'; return; }
if (str_starts_with($uri, '/v3/')) {
    if (($h('access_token') ?? '') !== '$aact_hmlg_test_key_1234567890') { $out(401, ['errors' => [['code' => 'invalid_access_token', 'description' => 'A chave de API informada não pertence a esta conta']]]); return; }
    if ($st['mode'] === 'reject') { $out(400, ['errors' => [['code' => 'invalid_cpfCnpj', 'description' => 'O CPF/CNPJ informado é inválido.']]]); return; }
    if ($m === 'GET' && $uri === '/v3/customers') { $out(200, ['data' => []]); return; }
    if ($m === 'POST' && $uri === '/v3/customers') { $st['n']++; $save(); $out(200, ['id' => 'cus_fake' . $st['n'], 'name' => $body['name'] ?? '']); return; }
    if ($m === 'POST' && $uri === '/v3/subscriptions') { $st['n']++; $st['val']['sub_fake' . $st['n']] = $body['value'] ?? 0; $save(); $out(200, ['id' => 'sub_fake' . $st['n'], 'status' => 'ACTIVE', 'value' => $body['value'] ?? 0]); return; }
    if ($m === 'GET' && preg_match('#^/v3/subscriptions/(sub_\w+)/payments$#', $uri, $mm)) { $out(200, ['data' => [['id' => 'pay_' . $mm[1], 'value' => $st['val'][$mm[1]] ?? 99.9, 'dueDate' => date('Y-m-d'), 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_' . $mm[1]]]]); return; }
    if ($m === 'POST' && preg_match('#^/v3/subscriptions/(sub_\w+)$#', $uri)) { $out(200, ['id' => 'ok']); return; }
    if ($m === 'DELETE' && preg_match('#^/v3/subscriptions/(sub_\w+)$#', $uri)) { $out(200, ['deleted' => true]); return; }
    $out(404, ['errors' => [['description' => 'rota desconhecida']]]); return;
}
if ($m === 'POST' && preg_match('#^/v[\d.]+/(\d+)/messages$#', $uri)) {
    if (($h('authorization') ?? '') !== 'Bearer EAAG_test_token_abcdefghijklmnop') { $out(401, ['error' => ['message' => 'Invalid OAuth access token.']]); return; }
    if ($st['mode'] === 'wa_fail') { $out(400, ['error' => ['message' => 'Template name does not exist in the translation']]); return; }
    $out(200, ['messages' => [['id' => 'wamid.FAKE']]]); return;
}
$out(404, ['error' => 'not found']);
