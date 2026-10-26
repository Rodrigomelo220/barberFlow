<?php
declare(strict_types=1);
/**
 * Backup do banco + imagens. SÓ roda pelo terminal/cron (recusa chamada pelo navegador).
 *   php backup.php [pasta-de-destino]        (padrão: ../backups-agendo, fora da pasta pública)
 * Gera backup-AAAAAMMDD-HHMMSS.sql.gz e, se houver, uploads-AAAAAMMDD-HHMMSS.zip. Mantém os 14 mais recentes.
 * Restaurar:  gunzip -c backup-....sql.gz | mysql -u USUARIO -p BANCO
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app/bootstrap.php';

$dest = $argv[1] ?? dirname(__DIR__) . '/backups-agendo';
if (!is_dir($dest) && !@mkdir($dest, 0700, true)) { fwrite(STDERR, "Não consegui criar $dest\n"); exit(1); }
$stamp = date('Ymd-His'); $sqlFile = "$dest/backup-$stamp.sql.gz";
$gz = gzopen($sqlFile, 'wb9'); if (!$gz) { fwrite(STDERR, "Não consegui gravar $sqlFile\n"); exit(1); }
$pdo = DB::pdo();
gzwrite($gz, "-- Backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
$rows = 0;
foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Extra', 'Field');
    $cols = array_keys(array_filter($cols, fn($x) => stripos((string)$x, 'GENERATED') === false));   // colunas geradas o banco recalcula
    $list = '`' . implode('`,`', $cols) . '`';
    $st = $pdo->query("SELECT $list FROM `$t`", PDO::FETCH_NUM); $buf = [];
    $flush = function () use (&$buf, $gz, $t, $list) { if ($buf) { gzwrite($gz, "INSERT INTO `$t` ($list) VALUES " . implode(",\n", $buf) . ";\n"); $buf = []; } };
    foreach ($st as $r) {
        $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')'; $rows++;
        if (count($buf) >= 200) $flush();
    }
    $flush(); gzwrite($gz, "\n");
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n"); gzclose($gz); @chmod($sqlFile, 0600);
echo "Banco: " . count($tables) . " tabelas, $rows linhas → $sqlFile\n";

$up = __DIR__ . '/uploads';
if (class_exists('ZipArchive') && is_dir($up) && glob($up . '/*-{logo,cover}-*', GLOB_BRACE)) {
    $z = new ZipArchive(); $zf = "$dest/uploads-$stamp.zip";
    if ($z->open($zf, ZipArchive::CREATE) === true) { foreach (glob($up . '/*-{logo,cover}-*', GLOB_BRACE) as $f) $z->addFile($f, basename($f)); $z->close(); @chmod($zf, 0600); echo "Imagens → $zf\n"; }
}
foreach (['backup-*.sql.gz', 'uploads-*.zip'] as $pat) { $all = glob("$dest/$pat") ?: []; rsort($all); foreach (array_slice($all, 14) as $old) @unlink($old); }
echo "Pronto. Guarde uma cópia FORA do servidor (e do config.php, que tem as chaves).\n";
