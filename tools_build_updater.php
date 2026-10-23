<?php
// Gera atualizar.php (arquivo único com a versão embutida). Uso: php tools_build_updater.php saida.php
$root = __DIR__; $out = $argv[1] ?? 'atualizar.php';
$zipPath = sys_get_temp_dir() . '/bf_payload.zip'; @unlink($zipPath);
$z = new ZipArchive(); $z->open($zipPath, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match('#^(\.git/|tests/|config\.php$|instalar\.php$|README\.md$|tools_|storage/(?!\.htaccess|index)|uploads/(?!\.htaccess|index\.html))#', $rel)) continue;
    $z->addFile($f->getPathname(), $rel);
}
$z->close();
$key = bin2hex(random_bytes(8));
$b64 = chunk_split(base64_encode(file_get_contents($zipPath)), 120, "\n");
$tpl = file_get_contents($root . '/tools_updater_template.php');
file_put_contents($out, str_replace(['__KEY__', '__PAYLOAD__'], [$key, $b64], $tpl));
echo "chave=$key arquivos=" . $z->numFiles . "\n";
