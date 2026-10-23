<?php
declare(strict_types=1);

/** Núcleo: configuração, banco, sessão, CSRF, helpers de visão e roteador. Sem dependências externas (PHP 8.0+). */

define('BF_ROOT', dirname(__DIR__));

function cfg(?string $path = null, $default = null)
{
    static $c = null;
    if ($c === null) {
        $file = getenv('BF_CONFIG') ?: BF_ROOT . '/config.php';
        $c = is_file($file) ? (require $file) : [];
    }
    if ($path === null) return $c;
    $v = $c;
    foreach (explode('.', $path) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}

final class BusinessError extends Exception
{
    public function __construct(string $message, public string $errorCode = 'business', public int $status = 422)
    {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------- banco

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $d = cfg('db');
            if (!$d) throw new RuntimeException('Sistema não instalado. Abra /instalar.php');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
            self::$pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            ]);
        }
        return self::$pdo;
    }

    public static function reset(): void { self::$pdo = null; }

    public static function q(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }
    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public static function val(string $sql, array $p = [])
    {
        $r = self::q($sql, $p)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }
    public static function exec(string $sql, array $p = []): int { return self::q($sql, $p)->rowCount(); }
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        self::q('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    private static int $depth = 0;
    /** Transação com repetição em caso de deadlock. Aninhada = reaproveita a externa. */
    public static function tx(callable $fn)
    {
        if (self::$depth > 0) return $fn();
        $attempt = 0;
        while (true) {
            $attempt++;
            self::$depth = 1;
            self::pdo()->beginTransaction();
            try {
                $r = $fn();
                self::pdo()->commit();
                self::$depth = 0;
                return $r;
            } catch (Throwable $e) {
                if (self::pdo()->inTransaction()) self::pdo()->rollBack();
                self::$depth = 0;
                $code = $e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0;
                if (($code === 1213 || $code === 1205) && $attempt < 4) { usleep(50000 * $attempt); continue; }
                throw $e;
            }
        }
    }
}

// ---------------------------------------------------------------- tempo

function now_utc(): DateTimeImmutable
{
    $o = $GLOBALS['BF_NOW'] ?? null;   // relógio simulado nos testes
    return $o instanceof DateTimeImmutable ? $o : new DateTimeImmutable('now', new DateTimeZone('UTC'));
}
function dt_db(DateTimeInterface $d): string { return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function dt_from_db(?string $s): ?DateTimeImmutable { return $s ? new DateTimeImmutable($s, new DateTimeZone('UTC')) : null; }
function dt_local(?string $utc, string $tz, string $fmt = 'd/m/Y H:i'): string
{
    return $utc ? dt_from_db($utc)->setTimezone(new DateTimeZone($tz))->format($fmt) : '—';
}

// ---------------------------------------------------------------- helpers de visão

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_path(): string
{
    static $b = null;
    if ($b === null) {
        $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $b = $d === '/' || $d === '.' ? '' : rtrim($d, '/');
    }
    return $b;
}
function url(string $path = '/'): string { return base_path() . $path; }
/** Plano de 1 profissional (Solo): telas de equipe/comparação com outros planos não aparecem. */
function solo_plan(array $ctx): bool { $p = $ctx['billing']['plan'] ?? null; return $p && $p['max_professionals'] !== null && (int)$p['max_professionals'] === 1; }
/** URL de arquivo estático com versão (data de modificação): o navegador nunca reaproveita CSS/JS antigo após uma atualização. */
function asset(string $path): string { $f = BF_ROOT . $path; $v = is_file($f) ? (string)filemtime($f) : '1'; return url($path) . '?v=' . $v; }
/** HTTPS direto ou atrás de proxy/CDN (hospedagens compartilhadas). Em domínio público sem sinal nenhum, assume https; só localhost/IP ficam em http. */
function is_https(bool $assumePublic = true): bool
{
    $s = $_SERVER;
    if (!empty($s['HTTPS']) && strtolower((string)$s['HTTPS']) !== 'off') return true;
    if ((string)($s['SERVER_PORT'] ?? '') === '443') return true;
    if (str_contains(strtolower((string)($s['HTTP_X_FORWARDED_PROTO'] ?? '')), 'https')) return true;
    if (strtolower((string)($s['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on' || strtolower((string)($s['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on') return true;
    if (str_contains((string)($s['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"')) return true;
    if (!$assumePublic) return false;
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($s['HTTP_HOST'] ?? 'localhost')));
    $local = $host === 'localhost' || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) || str_ends_with($host, '.local') || str_ends_with($host, '.test') || !str_contains($host, '.');
    return !$local;
}
function abs_url(string $path = '/'): string
{
    $u = cfg('app.url');
    if (!$u) {
        $u = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
    }
    return rtrim($u, '/') . $path;
}
function money(int $cents): string
{
    $neg = $cents < 0; $cents = abs($cents);
    return ($neg ? '−' : '') . 'R$ ' . number_format($cents / 100, 2, ',', '.');
}
function reais_to_cents(string $v): ?int
{
    $s = preg_replace('/[R$\s]/', '', $v);
    if ($s === '' || $s === null) return null;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    return is_numeric($s) ? (int)round(((float)$s) * 100) : null;
}
function minutes_label(int $m): string
{
    if ($m < 60) return $m . ' min';
    return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
}
function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function flash(?string $type = null, ?string $msg = null)
{
    if ($type !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(419);
        render_error(419, 'Sessão expirada', 'Volte, atualize a página e tente de novo.');
    }
}

function old(string $k, $default = '') { return $_SESSION['old'][$k] ?? $default; }

function render(string $view, array $data = [], string $layout = 'layout'): never
{
    extract($data, EXTR_SKIP);
    ob_start();
    require BF_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    if ($layout === '') { echo $content; exit; }
    require BF_ROOT . '/views/' . $layout . '.php';
    exit;
}
function render_error(int $code, string $title, string $msg): never
{
    http_response_code($code);
    render('error', ['code' => $code, 'title' => $title, 'msg' => $msg], 'layout_plain');
}
function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function input_int(string $k, int $default = 0): int { $v = input($k); return $v !== '' && preg_match('/^-?\d+$/', $v) ? (int)$v : $default; }
function input_bool(string $k): bool { return !empty($_POST[$k]); }

// ---------------------------------------------------------------- limites de taxa e auditoria

function rate_limit(string $key, int $max, int $windowSec): bool
{
    $k = substr(hash('sha256', $key), 0, 40);
    return (bool)DB::tx(function () use ($k, $max, $windowSec) {
        $now = now_utc();
        $r = DB::one('SELECT window_start, cnt FROM rate_limits WHERE k = ? FOR UPDATE', [$k]);
        if (!$r || dt_from_db($r['window_start'])->getTimestamp() + $windowSec <= $now->getTimestamp()) {
            DB::q('REPLACE INTO rate_limits (k, window_start, cnt) VALUES (?,?,1)', [$k, dt_db($now)]);
            return true;
        }
        if ((int)$r['cnt'] >= $max) return false;
        DB::exec('UPDATE rate_limits SET cnt = cnt + 1 WHERE k = ?', [$k]);
        return true;
    });
}
function client_ip(): string
{
    // Em hospedagem compartilhada o IP real costuma vir em X-Forwarded-For; só serve para limitar taxa, nunca para autorizar.
    $x = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = trim(explode(',', $x)[0] ?? '') ?: ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr($ip, 0, 45);
}

function audit(?int $shopId, ?int $userId, string $action, ?string $entity = null, $entityId = null, ?string $detail = null, string $kind = 'user'): void
{
    DB::insert('audit_logs', ['shop_id' => $shopId, 'actor_user_id' => $userId, 'actor_kind' => $kind, 'action' => $action, 'entity' => $entity,
        'entity_id' => $entityId === null ? null : (string)$entityId, 'detail' => $detail === null ? null : mb_substr($detail, 0, 300), 'created_at' => dt_db(now_utc())]);
}

// ---------------------------------------------------------------- roteador

final class Router
{
    private array $routes = [];
    public function add(string $method, string $pattern, callable $h): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $h];
    }
    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $h]) {
            if (preg_match($re, $path, $mm)) {
                if ($m !== $method) { $allowed = true; continue; }
                $args = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                $h(...array_values($args));
                return;
            }
        }
        if ($allowed) render_error(405, 'Método não permitido', 'Esta página não aceita essa operação.');
        render_error(404, 'Página não encontrada', 'O endereço não existe ou foi alterado.');
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = is_https(false);   // cookie Secure só com HTTPS comprovado (senão o login some em http)
    session_name('bfsess');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    $dir = BF_ROOT . '/storage/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_start();
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
