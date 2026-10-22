<?php
declare(strict_types=1);

/** Front controller. Todas as páginas passam por aqui (.htaccess). */
require __DIR__ . '/app/bootstrap.php';

if (!is_file(BF_ROOT . '/config.php') && !getenv('BF_CONFIG')) {
    header('Location: ' . (base_path() . '/instalar.php'));
    exit;
}

require __DIR__ . '/app/routes_public.php';
require __DIR__ . '/app/routes_panel.php';
require __DIR__ . '/app/routes_admin.php';

header_remove('X-Powered-By');
security_headers();
start_session();

function log_error(Throwable $e): void
{
    $dir = BF_ROOT . '/storage';
    if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/error.log', '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
}

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = '/' . trim($path, '/');
if ($path === '/index.php') $path = '/';

try {
    if (!str_starts_with($path, '/webhook') && !str_starts_with($path, '/api/')) { try { Messages::lazyTick(); } catch (Throwable $e) { log_error($e); } }
    $router = new Router();
    register_public_routes($router);
    register_panel_routes($router);
    register_admin_routes($router);
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (BusinessError $e) {
    if (str_starts_with($path, '/api/') || str_starts_with($path, '/webhook')) json_out(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
    render_error($e->status, 'Não foi possível concluir', $e->getMessage());
} catch (Throwable $e) {
    log_error($e);
    if (str_starts_with($path, '/api/') || str_starts_with($path, '/webhook')) json_out(['error' => 'server', 'message' => 'Erro interno. Tente novamente.'], 500);
    render_error(500, 'Algo deu errado', 'Tivemos um erro inesperado. Tente de novo em instantes.');
}
