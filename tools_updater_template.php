<?php
/* BarberFlow — atualizador de arquivo único.
 * Envie este arquivo para a pasta do site (htdocs), abra  atualizar.php?k=CHAVE  e clique em "Atualizar agora".
 * Substitui os arquivos do sistema, ajusta o banco (sem apagar dados) e se apaga no final.
 * Mantém: config.php, uploads (logo/capa), storage. */
declare(strict_types=1);
const UPD_KEY = '__KEY__';
header('X-Robots-Tag: noindex'); header('Content-Type: text/html; charset=utf-8');
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function page(string $body): never { echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Atualizar BarberFlow</title><body style="font:16px system-ui;max-width:640px;margin:30px auto;padding:0 16px;color:#202522;background:#f5f3ef"><h1>Atualizar BarberFlow</h1>' . $body; exit; }
if (!class_exists('ZipArchive')) page('<p>Este servidor não tem a extensão ZipArchive. Avise quem te enviou o arquivo.</p>');
$root = __DIR__;
if (!is_file($root . '/config.php')) page('<p>Não achei o <code>config.php</code> aqui. Este arquivo deve ficar na mesma pasta do sistema já instalado (se ainda não instalou, use o <code>instalar.php</code>).</p>');
// A chave é digitada no formulário (hospedagens como a InfinityFree perdem o "?k=" na primeira visita).
$given = (string)($_POST['k'] ?? $_GET['k'] ?? '');
$formTop = '<form method="post"><p><label>Chave de atualização<br><input name="k" value="' . h($given) . '" autocomplete="off" style="padding:10px;font-size:16px;width:100%;max-width:320px;border:1px solid #bbb;border-radius:8px"></label></p><button style="padding:12px 20px;font-size:16px;border:0;border-radius:10px;background:#202522;color:#fff">Atualizar agora</button></form>';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    page('<p>Isto vai atualizar os arquivos do sistema e ajustar o banco. Seus dados, <code>config.php</code> e imagens enviadas não são apagados.</p>' . $formTop);
}
if (!hash_equals(UPD_KEY, trim($given))) { http_response_code(403); page('<p style="color:#b7443d">Chave incorreta.</p>' . $formTop); }
$log = [];
try {
    $tmp = tempnam(sys_get_temp_dir(), 'bfu'); file_put_contents($tmp, base64_decode(preg_replace('/\s+/', '', (string)file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__))));
    $z = new ZipArchive(); if ($z->open($tmp) !== true) throw new RuntimeException('Pacote interno corrompido (reenvie o arquivo).');
    $n = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        if ($name === '' || str_ends_with($name, '/')) continue;
        if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^(config\.php|instalar\.php)$#', $name)) continue;
        $dest = $root . '/' . $name; $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException("Não consegui criar a pasta $dir");
        $data = $z->getFromIndex($i); if ($data === false || @file_put_contents($dest, $data) === false) throw new RuntimeException("Não consegui gravar $name (permissão?)");
        $n++;
    }
    $z->close(); @unlink($tmp); $log[] = "$n arquivos atualizados.";
    // ---- banco: ajustes sem apagar dados
    $c = require $root . '/config.php'; $d = $c['db'];
    $pdo = new PDO("mysql:host={$d['host']};port=" . ($d['port'] ?? 3306) . ";dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $has = fn(string $t, string $col) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t) . " AND COLUMN_NAME = " . $pdo->quote($col))->fetchColumn() > 0;
    foreach (['logo_path', 'cover_path'] as $col) if (!$has('shops', $col)) { $pdo->exec("ALTER TABLE shops ADD $col VARCHAR(120) NULL"); $log[] = "Banco: coluna shops.$col criada."; }
    $log[] = 'Banco conferido.';
    if (function_exists('opcache_reset')) @opcache_reset();
    @unlink(__FILE__); $log[] = 'Este arquivo de atualização foi apagado.';
    page('<p style="color:#247653"><b>Pronto, atualizado.</b></p><ul><li>' . implode('</li><li>', array_map('h', $log)) . '</li></ul><p><a href="./">Abrir o sistema</a></p>');
} catch (Throwable $e) {
    page('<p style="color:#b7443d"><b>Não foi possível concluir.</b></p><p>' . h($e->getMessage()) . '</p><ul><li>' . implode('</li><li>', array_map('h', $log)) . '</li></ul><p>Nada foi apagado. Mande esta mensagem para o suporte.</p>');
}
__halt_compiler();
__PAYLOAD__
