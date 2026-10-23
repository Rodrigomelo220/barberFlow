<?php
declare(strict_types=1);
/**
 * Instalador pelo navegador. Abra  https://SEU-SITE/instalar.php , preencha os dados do MySQL (painel da hospedagem) e do administrador.
 * Ele cria as tabelas, os planos e o administrador, e grava o config.php. DEPOIS APAGUE ESTE ARQUIVO.
 */
const BF_INSTALLER = true;
define('BF_ROOT', __DIR__);
session_name('bfinst');
session_start();
$configFile = __DIR__ . '/config.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors = []; $done = false;
$installed = is_file($configFile);

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$reqs = [
    'PHP 8.0 ou superior' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Extensão PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extensão mbstring' => extension_loaded('mbstring'),
    'Pasta storage/ gravável' => is_dir(__DIR__ . '/storage') ? is_writable(__DIR__ . '/storage') : @mkdir(__DIR__ . '/storage', 0775, true),
    'Pasta do site gravável (para criar config.php)' => is_writable(__DIR__),
];
$reqOk = !in_array(false, $reqs, true);

$v = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'pass' => '', 'admin_name' => '', 'admin_email' => '', 'app_url' => ''];
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $reqOk) {
    foreach ($v as $k => $_) if (isset($_POST[$k]) && is_string($_POST[$k])) $v[$k] = trim($_POST[$k]);
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['_csrf'] ?? ''))) $errors[] = 'Sessão expirada. Recarregue a página.';
    if ($v['host'] === '' || $v['name'] === '' || $v['user'] === '') $errors[] = 'Preencha servidor, nome do banco e usuário do MySQL.';
    if (mb_strlen($v['admin_name']) < 2) $errors[] = 'Informe o nome do administrador.';
    if (!filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail do administrador inválido.';
    if (mb_strlen($adminPass) < 10 || !preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/\d/', $adminPass)) $errors[] = 'A senha do administrador precisa ter ao menos 10 caracteres, com letras e números.';

    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $v['host'], (int)$v['port'], $v['name']), $v['user'], $v['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            $errors[] = 'Não consegui conectar ao MySQL. Confira servidor, banco, usuário e senha (copie do painel da hospedagem). Detalhe: ' . $h(preg_replace('/password=\S+/i', '', $e->getMessage()));
        }
    }
    if (!$errors) {
        $exists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        if ($exists) $errors[] = 'Este banco já tem tabelas do sistema. Use um banco vazio, ou apague as tabelas antigas pelo phpMyAdmin.';
    }
    if (!$errors) {
        try {
            $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
            foreach (explode("\n-- #\n", $sql) as $stmt) { $stmt = trim($stmt); if ($stmt !== '') $pdo->exec($stmt); }
            $now = gmdate('Y-m-d H:i:s');
            $plans = require __DIR__ . '/sql/plans.php';
            $ins = $pdo->prepare('INSERT INTO plans (code,name,description,price_cents,trial_days,max_professionals,max_appointments_month,max_messages_month,feat_reminders,feat_commissions,highlights,is_active,is_public,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,1,?)');
            foreach ($plans as $p) $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], json_encode($p[10], JSON_UNESCAPED_UNICODE), $p[11]]);
            $pdo->prepare('INSERT INTO users (email,password_hash,name,is_admin,created_at) VALUES (?,?,?,1,?)')->execute([mb_strtolower($v['admin_email']), password_hash($adminPass, PASSWORD_DEFAULT), $v['admin_name'], $now]);
            $pdo->prepare("REPLACE INTO meta (k,v) VALUES ('installed_at', ?)")->execute([$now]);

            $cfg = ['db' => ['host' => $v['host'], 'port' => (int)$v['port'], 'name' => $v['name'], 'user' => $v['user'], 'pass' => $v['pass']],
                'app' => ['url' => rtrim($v['app_url'], '/'), 'mode' => 'demo', 'send_mail' => false, 'mail_from' => 'BarberFlow <nao-responda@localhost>',
                    'webhook_token' => bin2hex(random_bytes(24)), 'cron_key' => bin2hex(random_bytes(16))]];
            $out = "<?php\n// Gerado pelo instalador. NÃO publique este arquivo.\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents($configFile, $out, LOCK_EX) === false) {
                $errors[] = 'Banco criado, mas não consegui gravar o config.php. Dê permissão de escrita na pasta do site e recarregue.';
            } else {
                @chmod($configFile, 0640);
                $done = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Falha ao criar as tabelas: ' . $h($e->getMessage()) . ' — apague as tabelas criadas pela metade (phpMyAdmin) e tente de novo.';
        }
    }
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Instalação — BarberFlow</title>
<style>
body{margin:0;background:#eef1f0;color:#0f1a24;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:560px;margin:0 auto;padding:24px 16px 48px}
h1{font-size:1.5rem;margin:.2em 0 .1em} .rail{height:8px;background:repeating-linear-gradient(135deg,#c9362b 0 12px,#fff 12px 24px,#1d4e89 24px 36px);margin-bottom:20px}
.card{background:#fff;border:1px solid #d3dadc;border-radius:10px;padding:18px;margin:14px 0}
label{display:block;font-weight:600;margin:12px 0 4px} input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #9aa7ad;border-radius:8px;font:inherit}
.hint{color:#55636d;font-size:.9rem;margin:2px 0 0} button{margin-top:18px;background:#0f1a24;color:#fff;border:0;border-radius:8px;padding:12px 18px;font:inherit;font-weight:600;width:100%}
.err{background:#fdecea;border:1px solid #c9362b;border-radius:8px;padding:10px 12px;margin:8px 0} .ok{color:#1b6b3a} .bad{color:#c9362b;font-weight:600} code{background:#eef1f0;padding:1px 5px;border-radius:4px}
</style><link rel="icon" href="data:,"></head><body><main>
<div class="rail"></div><h1>Instalação do BarberFlow</h1>
<?php if ($installed && !$done): ?>
  <div class="card"><b>O sistema já está instalado.</b><p>Por segurança, <b>apague o arquivo <code>instalar.php</code></b> do servidor. Para reinstalar, apague também o <code>config.php</code> e esvazie o banco.</p><p><a href="./">Abrir o sistema</a></p></div>
<?php elseif ($done): ?>
  <div class="card"><b class="ok">Instalado com sucesso.</b>
  <ol><li><b>Apague agora o arquivo <code>instalar.php</code></b> (pelo Gerenciador de Arquivos ou FTP).</li>
  <li>Entre em <a href="entrar">/entrar</a> com o e-mail e a senha do administrador (painel em <code>/admin</code>).</li>
  <li>Para testar como barbearia, crie outra conta em <a href="cadastro">/cadastro</a>.</li></ol>
  <p class="hint">Pagamento e WhatsApp são <b>simulados</b> nesta versão. Leia o README.</p></div>
<?php else: ?>
  <div class="card"><b>Requisitos</b><ul style="padding-left:18px;margin:6px 0">
  <?php foreach ($reqs as $k => $ok): ?><li><?= $h($k) ?>: <?= $ok ? '<span class="ok">ok</span>' : '<span class="bad">faltando</span>' ?></li><?php endforeach; ?></ul></div>
  <?php foreach ($errors as $e): ?><div class="err"><?= $e ?></div><?php endforeach; ?>
  <?php if ($reqOk): ?>
  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <b>1. Banco de dados MySQL</b><p class="hint">Crie o banco no painel da hospedagem (MySQL Databases) e copie os dados.</p>
    <label>Servidor (MySQL Host Name)</label><input name="host" value="<?= $h($v['host']) ?>" required><p class="hint">Na InfinityFree é algo como <code>sql123.infinityfree.com</code>, não "localhost".</p>
    <label>Porta</label><input name="port" value="<?= $h($v['port']) ?>" inputmode="numeric">
    <label>Nome do banco</label><input name="name" value="<?= $h($v['name']) ?>" required>
    <label>Usuário</label><input name="user" value="<?= $h($v['user']) ?>" required>
    <label>Senha do MySQL</label><input name="pass" type="password" value="">
    <hr style="border:0;border-top:1px solid #d3dadc;margin:20px 0">
    <b>2. Administrador da plataforma</b>
    <label>Nome</label><input name="admin_name" value="<?= $h($v['admin_name']) ?>" required>
    <label>E-mail</label><input name="admin_email" type="email" value="<?= $h($v['admin_email']) ?>" required>
    <label>Senha</label><input name="admin_pass" type="password" required minlength="10"><p class="hint">Mínimo de 10 caracteres, com letras e números.</p>
    <hr style="border:0;border-top:1px solid #d3dadc;margin:20px 0">
    <b>3. Endereço do site (opcional)</b>
    <label>URL pública</label><input name="app_url" value="<?= $h($v['app_url']) ?>" placeholder="https://seusite.infinityfreeapp.com"><p class="hint">Usada em links de e-mail. Se vazio, o sistema detecta sozinho.</p>
    <button type="submit">Instalar</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main></body></html>
