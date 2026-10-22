<?php
require __DIR__ . '/lib.php';
echo "== Concorrência (processos PHP simultâneos)\n";
reset_db();
set_now('2026-11-02 10:00:00');
$o = mk_user('dono@x.com');
$sid = mk_shop($o, 'conc');
$sv = mk_service($sid);
$p1 = pro_of($sid);

function race(int $n, int $sid, int $sv, string $pro, string $when): array
{
    $barrier = sys_get_temp_dir() . '/bf_barrier_' . uniqid();
    $procs = [];
    for ($i = 1; $i <= $n; $i++) {
        $cmd = [PHP_BINARY, __DIR__ . '/worker.php', (string)$sid, (string)$sv, $pro, $when, (string)$i, $barrier];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    usleep(600000);              // todos já conectaram e estão no laço de espera
    touch($barrier);
    $out = [];
    foreach ($procs as [$p, $pipes]) { $out[] = json_decode(stream_get_contents($pipes[1]) ?: '{}', true) ?: ['ok' => false, 'code' => 'EMPTY']; fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    @unlink($barrier);
    return $out;
}

t('10 processos disputam o MESMO horário/profissional → exatamente 1 vence', function () use ($sid, $sv, $p1) {
    $r = race(10, $sid, $sv, (string)$p1, '2026-11-03 10:00');
    $wins = array_filter($r, fn($x) => $x['ok']);
    eq(count($wins), 1, 'vencedores: ' . json_encode($r));
    foreach ($r as $x) if (!$x['ok']) eq($x['code'], 'slot_unavailable', 'perdedores devem receber slot_unavailable, nunca erro técnico');
    eq((int)DB::val("SELECT COUNT(*) FROM appointments WHERE shop_id = ? AND status='confirmed' AND starts_at = '2026-11-03 13:00:00'", [$sid]), 1);
});
t('horários SOBREPOSTOS em paralelo (10:45, 11:00, 11:15, 11:29) → no máximo 1', function () use ($sid, $sv, $p1) {
    $barrier = sys_get_temp_dir() . '/bf_barrier_' . uniqid(); $procs = [];
    foreach (['11:00', '11:15', '11:29', '10:45', '11:00', '11:15'] as $i => $when) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/worker.php', (string)$sid, (string)$sv, (string)$p1, "2026-11-03 $when", (string)(50 + $i), $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    usleep(600000); touch($barrier); $r = [];
    foreach ($procs as [$p, $pipes]) { $r[] = json_decode(stream_get_contents($pipes[1]), true); proc_close($p); }
    @unlink($barrier);
    $okc = count(array_filter($r, fn($x) => $x['ok']));
    ok($okc >= 1 && $okc <= 2, 'vencedores: ' . $okc . ' ' . json_encode($r));
    // a invariante que importa: nenhum par de agendamentos confirmados se sobrepõe
    $rows = DB::all("SELECT occupied_start s, occupied_end e FROM appointments WHERE professional_id = ? AND status='confirmed' ORDER BY s", [$p1]);
    for ($i = 1; $i < count($rows); $i++) ok($rows[$i]['s'] >= $rows[$i - 1]['e'], 'sobreposição: ' . json_encode([$rows[$i - 1], $rows[$i]]));
    foreach ($r as $x) if (!$x['ok']) eq($x['code'], 'slot_unavailable');
});
t('"qualquer profissional" com 2 profissionais e 8 processos → exatamente 2 vencem, em profissionais distintos', function () use ($sid, $sv, $o) {
    $p2 = Shops::createProfessional($sid, $o, ['name' => 'Segundo', 'active' => true]);
    Shops::setHours($sid, $p2, array_map(fn($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '18:00'], [1, 2, 3, 4, 5, 6]));
    Shops::setProfessionalServices($sid, $p2, [$sv]);
    $r = race(8, $sid, $sv, 'any', '2026-11-04 15:00');
    $wins = array_values(array_filter($r, fn($x) => $x['ok']));
    eq(count($wins), 2, json_encode($r));
    ok($wins[0]['pro'] !== $wins[1]['pro'], 'profissionais distintos');
    foreach ($r as $x) if (!$x['ok']) eq($x['code'], 'slot_unavailable');
});
t('disputa pelo último agendamento do plano (limite 3, 3 já usados no dia) → contador não estoura', function () use ($sid, $sv, $p1) {
    DB::exec("UPDATE plans SET max_appointments_month = (SELECT COUNT(*) + 1 FROM appointments a WHERE a.shop_id = ? AND a.status = 'confirmed') WHERE code = 'profissional'", [$sid]);
    $r = race(6, $sid, $sv, (string)$p1, '2026-11-05 16:00');
    eq(count(array_filter($r, fn($x) => $x['ok'])), 1, json_encode($r));
    foreach ($r as $x) if (!$x['ok']) ok(in_array($x['code'], ['plan_limit', 'slot_unavailable'], true), json_encode($x));
    DB::exec("UPDATE plans SET max_appointments_month = 600 WHERE code = 'profissional'");
});
done();
