<?php
declare(strict_types=1);

$cfgFile = sys_get_temp_dir() . '/bf_test_config.php';
file_put_contents($cfgFile, "<?php return " . var_export(['db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'bf_test', 'user' => 'bf', 'pass' => 'bf'],
    'app' => ['url' => 'http://localhost', 'mode' => 'demo', 'send_mail' => false, 'webhook_token' => 'tok_test_123', 'cron_key' => 'k']], true) . ";\n");
putenv('BF_CONFIG=' . $cfgFile);
require_once __DIR__ . '/../app/bootstrap.php';
mb_internal_encoding('UTF-8');

const SP = 'America/Sao_Paulo';
$GLOBALS['T_PASS'] = 0; $GLOBALS['T_FAIL'] = []; $GLOBALS['T_CUR'] = '';

function set_now(string $iso): void { $GLOBALS['BF_NOW'] = new DateTimeImmutable($iso, new DateTimeZone('UTC')); }
function local(string $dt, string $tz = SP): DateTimeImmutable { return new DateTimeImmutable($dt, new DateTimeZone($tz)); }

function t(string $name, callable $fn): void
{
    $GLOBALS['T_CUR'] = $name;
    try { $fn(); echo "  ok   $name\n"; }
    catch (Throwable $e) { $GLOBALS['T_FAIL'][] = "$name: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); echo "  FAIL $name\n       " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
}
function ok($c, string $m = 'condição falsa'): void { if (!$c) throw new RuntimeException($m); $GLOBALS['T_PASS']++; }
function eq($a, $b, string $m = ''): void { if ($a !== $b) throw new RuntimeException(($m ? "$m: " : '') . 'esperado ' . var_export($b, true) . ', veio ' . var_export($a, true)); $GLOBALS['T_PASS']++; }
function throws(callable $fn, ?string $code = null, string $m = ''): BusinessError
{
    try { $fn(); } catch (BusinessError $e) {
        if ($code !== null && $e->errorCode !== $code) throw new RuntimeException(($m ? "$m: " : '') . "código esperado '$code', veio '{$e->errorCode}' ({$e->getMessage()})");
        $GLOBALS['T_PASS']++; return $e;
    }
    throw new RuntimeException(($m ? "$m: " : '') . 'deveria lançar BusinessError' . ($code ? " ($code)" : ''));
}

function reset_db(): void
{
    $pdo = DB::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (explode("\n-- #\n", file_get_contents(__DIR__ . '/../sql/schema.sql')) as $s) { $s = trim($s); if ($s !== '') $pdo->exec($s); }
    $plans = [['basico', 'Básico', 3990, 7, 1, 150, 0, 0, 0, 1], ['profissional', 'Profissional', 7990, 7, 3, 600, 300, 1, 1, 2], ['equipe', 'Equipe', 14990, 7, 10, null, 1000, 1, 1, 3]];
    $i = $pdo->prepare('INSERT INTO plans (code,name,description,price_cents,trial_days,max_professionals,max_appointments_month,max_messages_month,feat_reminders,feat_commissions,highlights,is_active,is_public,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,1,?)');
    foreach ($plans as $p) $i->execute([$p[0], $p[1], '', $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], '[]', $p[9]]);
    DB::reset();
}

function mk_user(string $email, string $name = 'Fulano', bool $admin = false): int
{
    $id = Auth::signup($name, $email, 'SenhaBoa12345');
    if ($admin) DB::exec('UPDATE users SET is_admin = 1 WHERE id = ?', [$id]);
    return $id;
}
/** Barbearia com assinatura ATIVA pelo caminho legítimo (checkout → webhook). */
function mk_shop(int $owner, string $slug, string $plan = 'profissional', bool $activate = true): int
{
    $sid = Shops::create($owner, strtoupper($slug), $slug, '11999990000', $plan);
    if ($activate) activate($sid, $owner, $plan);
    return $sid;
}
function activate(int $sid, int $owner, string $plan = 'profissional'): void
{
    $r = Billing::start($sid, $owner, $plan, false);
    $res = Billing::simulate('PAYMENT_RECEIVED', $r['sub_ref'], $r['charge_ref'], $r['amount_cents'], now_utc()->format('Y-m-d'));
    if ($res['status'] !== 200) throw new RuntimeException('ativação falhou: ' . json_encode($res));
}
function mk_service(int $sid, string $name = 'Corte', int $price = 5000, int $dur = 30, int $before = 0, int $after = 0): int
{
    return Shops::createService($sid, 0, ['name' => $name, 'price_cents' => $price, 'duration_min' => $dur, 'buffer_before_min' => $before, 'buffer_after_min' => $after, 'active' => 1, 'bookable_online' => 1]);
}
function pro_of(int $sid): int { return (int)DB::val('SELECT id FROM professionals WHERE shop_id = ? ORDER BY id LIMIT 1', [$sid]); }
function book(int $sid, array $svc, $pro, string $when, string $name = 'Cliente', string $phone = '11988887777', array $extra = []): array
{
    return Scheduling::book(['shop_id' => $sid, 'service_ids' => $svc, 'professional_id' => $pro, 'starts_at' => local($when), 'name' => $name, 'phone' => $phone, 'source' => 'link'] + $extra);
}
function done(): never
{
    echo "\n" . $GLOBALS['T_PASS'] . " verificações ok; " . count($GLOBALS['T_FAIL']) . " teste(s) com falha\n";
    foreach ($GLOBALS['T_FAIL'] as $f) echo " - $f\n";
    exit($GLOBALS['T_FAIL'] ? 1 : 0);
}
