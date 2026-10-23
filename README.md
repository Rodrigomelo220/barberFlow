# BarberFlow (PHP + MySQL)

Agenda online para barbearias, salões de beleza, design de sobrancelha, manicure/unhas e estética, feita para rodar em hospedagem compartilhada (InfinityFree). PHP 8.0+, MySQL/MariaDB, sem Composer.

## Instalar no InfinityFree
1. No painel da InfinityFree, crie um banco MySQL (anote host, banco, usuário e senha).
2. Pelo Gerenciador de Arquivos/FTP, envie **o conteúdo** do ZIP para `htdocs/`.
3. Abra `https://SEU-SITE/instalar.php`, preencha os dados do banco e crie a conta administradora.
4. Entre em `/entrar`, crie sua barbearia e configure serviços, equipe e horários.
5. Depois de instalar, apague `instalar.php`. As pastas `storage/` e `uploads/` precisam de permissão de escrita.

## O que está implementado
- Tipo de negócio (barbearia, salão, sobrancelha, unhas, estética, outro) escolhido ao cadastrar e alterável em Configurações; muda os serviços/equipe do "dados de exemplo". As regras do sistema são as mesmas para todos.
- Tema claro/escuro (botão no topo; lembra a escolha e segue o aparelho na primeira visita).
- Página pública de agendamento (capa, logo, etapas, dias, horários), cancelar/remarcar por link secreto.
- Reserva atômica (transação + travas): sem horário duplicado, testado com processos paralelos.
- Painel: início com gráficos, agenda em linha do tempo, clientes, serviços, equipe, bloqueios, financeiro, despesas, mensagens, configurações.
- Logo e capa enviadas pela própria barbearia (validadas e regravadas; `uploads/` não executa scripts).
- Dois planos (definidos em `sql/plans.php`; os destaques mostrados ao cliente são gerados dos mesmos números que o sistema aplica):
  | | Solo R$ 99,90 | Profissional R$ 169,90 |
  |---|---|---|
  | Profissionais ativos | 1 | até 5 |
  | Agendamentos/mês | até 300 | sem limite |
  | Mensagens/mês (confirmação + lembretes) | até 100 | até 600 |
  | Comissões por profissional | não | sim |
  | Página pública, logo/capa, agenda, financeiro, clientes | sim | sim |
- Administração da plataforma: aba "Administradores" (adicionar por e-mail de conta já cadastrada, remover; nunca fica sem administrador).
- Botão "Preencher com dados de exemplo" em Configurações (só modo demo, só barbearia sem atendimentos): clientes e atendimentos fictícios.
- Concluir atendimento **não** registra pagamento automaticamente.
- Assinatura, inadimplência gradual (nada é apagado), LGPD (exportar/anonimizar), painel de administrador.

## O que é simulado ou não foi validado
| Item | Situação |
|---|---|
| Cobrança (Asaas) | **Simulada.** O formato do webhook segue o do Asaas, mas não foi validado contra o serviço real. Falta: conta, chave de API e token de webhook. |
| WhatsApp | **Simulado.** Mensagens vão para uma fila (`simulado`); nada é enviado. Falta: provedor oficial e aprovação de templates. |
| E-mail | Usa `mail()`; se o servidor bloquear, vai para `storage/mail.log`. |
| InfinityFree real | **Não testado lá.** Testado em PHP 8 + MariaDB 10.11 local. |
| Lembretes | Sem cron na InfinityFree: rodam "preguiçosamente" a cada acesso (a cada 2 min). Opcional: `cron.php?key=...`. |
| Equipe | Sem convite de funcionários: só o dono usa o painel. |
| Termos/Privacidade | Textos-modelo; precisam de revisão jurídica. |
| Isolamento entre barbearias | Garantido por código (`shop_id` em toda consulta); MySQL não tem RLS. |

## Testes
`sh tests/run_all.sh` (agenda, concorrência, cobrança, negócio/LGPD, HTTP) e `node tests/e2e.mjs` (navegador, celular). Exigem MariaDB local com banco `bf_test` e usuário `bf`/`bf`.

## Visual
Bootstrap 5.3.3 e Bootstrap Icons 1.11.3 ficam **dentro do projeto** (`assets/vendor/`), sem CDN. Tema em `assets/style.css`.
