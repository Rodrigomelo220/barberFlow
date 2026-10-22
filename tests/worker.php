<?php
// Processo-operário do teste de concorrência: espera a largada e tenta reservar.
require __DIR__ . '/lib.php';
[$_, $sid, $svc, $pro, $when, $i, $barrier] = $argv;
set_now('2026-11-02 10:00:00');
$t0 = microtime(true);
while (!file_exists($barrier)) usleep(500);
try {
    $b = Scheduling::book(['shop_id' => (int)$sid, 'service_ids' => [(int)$svc], 'professional_id' => $pro === 'any' ? 'any' : (int)$pro, 'starts_at' => local($when), 'name' => "Cliente $i", 'phone' => '1190000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), 'source' => 'link']);
    echo json_encode(['ok' => true, 'pro' => $b['professional_id']]);
} catch (BusinessError $e) { echo json_encode(['ok' => false, 'code' => $e->errorCode]); }
catch (Throwable $e) { echo json_encode(['ok' => false, 'code' => 'EXC', 'msg' => $e->getMessage()]); }
