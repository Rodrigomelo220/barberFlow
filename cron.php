<?php
declare(strict_types=1);
/**
 * Opcional. Sem cron (como na InfinityFree) o sistema roda as tarefas "preguiçosamente" a cada requisição (no máx. a cada 2 min).
 * Para ter pontualidade, chame periodicamente (ex.: cron-job.org, a cada 5 min):  https://SEU-SITE/cron.php?key=CHAVE
 * A chave fica em config.php (app.cron_key).
 */
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$key = (string)cfg('app.cron_key', '');
$got = $_GET['key'] ?? ($argv[1] ?? '');
if ($key === '' || !is_string($got) || !hash_equals($key, $got)) { http_response_code(403); exit("proibido\n"); }
echo json_encode(Messages::runPeriodic()) . "\n";
