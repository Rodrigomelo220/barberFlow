<?php
// Servidor SMTP falso (um cliente por vez) para os testes. Grava a mensagem recebida em /tmp/bf_fake_smtp.txt. Senha esperada: s3nha.
$port = (int)($argv[1] ?? 8092);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $s); if (!$srv) exit(1);
$out = '/tmp/bf_fake_smtp.txt';
while ($c = @stream_socket_accept($srv, 600)) {
    stream_set_timeout($c, 10);
    $w = fn($t) => fwrite($c, $t . "\r\n");
    $w('220 fake ESMTP'); $rec = ''; $data = false; $auth = 0; $user = ''; $ok = false;
    while (($l = fgets($c)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($data) { if ($l === '.') { $data = false; file_put_contents($out, $rec); $w('250 queued'); continue; } $rec .= (str_starts_with($l, '..') ? substr($l, 1) : $l) . "\n"; continue; }
        if ($auth === 1) { $user = base64_decode($l); $auth = 2; $w('334 UGFzc3dvcmQ6'); continue; }
        if ($auth === 2) { $auth = 0; $ok = base64_decode($l) === 's3nha' && $user === 'usuario'; $w($ok ? '235 ok' : '535 bad credentials'); continue; }
        $u = strtoupper($l);
        if (str_starts_with($u, 'EHLO')) { $w('250-fake'); $w('250 AUTH LOGIN'); }
        elseif ($u === 'AUTH LOGIN') { $auth = 1; $w('334 VXNlcm5hbWU6'); }
        elseif (str_starts_with($u, 'MAIL FROM')) { $rec = "ENVELOPE-FROM: $l\n"; $w('250 ok'); }
        elseif (str_starts_with($u, 'RCPT TO')) { $rec .= "ENVELOPE-TO: $l\n"; $w('250 ok'); }
        elseif ($u === 'DATA') { $data = true; $w('354 go'); }
        elseif ($u === 'QUIT') { $w('221 bye'); break; }
        else $w('250 ok');
    }
    fclose($c);
}
