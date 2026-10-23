<?php
// Planos oficiais (fonte única: instalador e atualizador usam este arquivo).
// [code, nome, descrição, preço em centavos, dias de teste, máx. profissionais, máx. agendamentos/mês (null = sem limite), mensagens/mês (0 = nenhuma), lembretes, comissões, destaques, ordem]
return [
    ['basico', 'Solo', 'Para quem atende sozinho.', 9990, 7, 1, 300, 100, 1, 0,
        ['1 profissional', 'Página de agendamento online com logo e capa', 'Até 300 agendamentos por mês', 'Lembretes automáticos (até 100 mensagens/mês)'], 1],
    ['profissional', 'Profissional', 'Para a barbearia com mais de um barbeiro.', 16990, 7, 5, null, 600, 1, 1,
        ['Até 5 profissionais', 'Agendamentos sem limite mensal', 'Lembretes automáticos (até 600 mensagens/mês)', 'Comissões por profissional'], 2],
];
