<?php
// Planos oficiais (fonte única: instalador e atualizador usam este arquivo).
// Os "destaques" exibidos ao cliente são GERADOS dos números abaixo, para nunca divergirem do que o sistema aplica.
// Campos: code, nome, descrição, preço (centavos), dias de teste, máx. profissionais, máx. agendamentos/mês (null = sem limite),
//         mensagens/mês (confirmações + lembretes; 0 = nenhuma — nunca ilimitado), lembretes, comissões, ordem.
$defs = [
    ['basico', 'Solo', 'Para quem atende sozinho.', 9990, 7, 1, 300, 100, 1, 0, 1],
    ['profissional', 'Profissional', 'Para quem atende com equipe, até 5 profissionais.', 16990, 7, 5, null, 600, 1, 1, 2],
];
$n = fn(int $v) => number_format($v, 0, ',', '.');
$out = [];
foreach ($defs as [$code, $name, $desc, $price, $trial, $maxPro, $maxAppt, $maxMsg, $rem, $com, $sort]) {
    $h = [$maxPro === 1 ? '1 profissional' : "Até $maxPro profissionais", 'Página de agendamento online com logo e capa',
        $maxAppt === null ? 'Agendamentos sem limite mensal' : "Até $maxAppt agendamentos por mês"];
    if ($rem && $maxMsg > 0) $h[] = "Confirmação e lembretes por WhatsApp (até {$n($maxMsg)} mensagens/mês)";
    if ($com) $h[] = 'Comissões por profissional';
    $out[] = [$code, $name, $desc, $price, $trial, $maxPro, $maxAppt, $maxMsg, $rem, $com, $h, $sort];
}
return $out;
