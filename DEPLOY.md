# Colocando o sistema no ar (guia de implantação)

Este guia leva um servidor novo até "pronto para vender". Tempo estimado: 1 hora. Se algo der errado, a página **Admin → Diagnóstico** mostra o que falta.

## 1. O que você precisa
- Um servidor com **PHP 8.0+** (extensões `pdo_mysql`, `openssl`, `mbstring`, `curl`) e **MySQL/MariaDB**. Pode ser hospedagem compartilhada com cPanel ou uma VPS.
- Um **domínio** apontando para o servidor e **HTTPS** ativo (certificado gratuito Let's Encrypt). Sem HTTPS não venda: senhas e sessões ficam expostas.
- Um banco de dados vazio (utf8mb4) com usuário e senha.

## 2. Instalação
1. Envie o conteúdo do `agendo-instalacao.zip` para a pasta pública do domínio (`public_html`, `/var/www/agendo`...).
2. Abra `https://SEU-DOMINIO/instalar.php`, preencha os dados do banco, o nome do sistema e escolha **Modo produção**.
3. O instalador cria o banco, o administrador e o `config.php`. **Apague `instalar.php`** quando terminar.
4. Dê permissão de escrita (775) às pastas `storage/` e `uploads/`. Em servidor compartilhado, `chmod 640 config.php`.
5. Entre em **Admin → Diagnóstico** e resolva tudo que estiver vermelho.

### Apache
Já funciona: o `.htaccess` do pacote reescreve as rotas e bloqueia `config.php`, `*.sql` e `*.log`. O módulo `mod_rewrite` e `AllowOverride All` precisam estar ativos (padrão em cPanel).

### Nginx (VPS)
```nginx
server {
    server_name SEU-DOMINIO;
    root /var/www/agendo;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ ^/(config\.php|app/|sql/|views/|storage/|tests/|backup\.php) { deny all; return 404; }
    location ~ \.(sql|log)$ { deny all; }
    location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location /uploads/ { location ~ \.php$ { deny all; } }
}
```
Depois de configurar, abra `https://SEU-DOMINIO/config.php` e `https://SEU-DOMINIO/storage/mail.log`: os dois precisam dar erro 403/404. O Diagnóstico (botão "Testar de novo") faz essa checagem por você.

## 3. Rotina automática (lembretes e cobrança)
Sem cron o sistema roda suas tarefas quando alguém acessa o site (no máximo a cada 2 min). Para pontualidade, chame a cada 5 minutos:
- **cPanel/VPS**: `*/5 * * * * curl -fsS "https://SEU-DOMINIO/cron.php?key=CHAVE" >/dev/null`
- **Sem cron** (hospedagem gratuita): crie uma tarefa em cron-job.org apontando para a mesma URL.
A `CHAVE` está no `config.php` (`app.cron_key`).

## 4. E-mail (recuperação de senha e convites)
**Admin → Sistema → E-mail (SMTP)**: use o SMTP do seu provedor (Brevo, Mailgun, Amazon SES, ou a própria hospedagem). Salve e clique em **Enviar e-mail de teste**. Configure SPF/DKIM no domínio para não cair no spam.

## 5. Cobrança automática (Asaas)
1. Crie conta no Asaas. Comece pelo **sandbox** (conta de testes) e faça uma assinatura de ponta a ponta antes de usar a chave de produção.
2. **Admin → Sistema → Asaas**: cole a chave (`$aact_hmlg_...` no sandbox, `$aact_prod_...` em produção) e escolha o ambiente. Clique em **Testar conexão**.
3. No Asaas, em **Integrações → Webhooks**, cadastre a URL e o token que aparecem em Admin → Sistema (o token vai no cabeçalho `asaas-access-token`). Marque os eventos de cobrança e de assinatura.
4. Teste: assine um plano com uma conta de teste, pague a cobrança no sandbox e confirme que a assinatura ficou ativa **pelo webhook**. A assinatura só ativa quando o Asaas avisa que recebeu.
Sem Asaas, você pode registrar pagamentos manualmente (Pix) em Admin → Negócios → o negócio → Registrar pagamento.

## 6. WhatsApp
- **Sem configurar nada**: o painel mostra um botão que abre o WhatsApp com a mensagem pronta (envio manual, um toque). Funciona de verdade.
- **Automático (Cloud API da Meta)**: precisa de conta Meta Business verificada, número dedicado e **4 modelos de mensagem aprovados** (textos exibidos em Admin → Sistema). Cole o token e o ID do número lá. Aprovação de modelos pode levar dias e a Meta cobra por conversa.

## 7. Backup (faça antes de vender)
```
php backup.php            # grava em ../backups-agendo (fora da pasta pública)
```
Agende diariamente: `30 3 * * * cd /var/www/agendo && php backup.php`. Copie os arquivos para **fora do servidor** (Drive, outro servidor). Guarde também o `config.php`: sem ele os segredos salvos (chaves do Asaas, senha do SMTP) não podem ser lidos.
Restaurar: `gunzip -c backup-AAAAMMDD-HHMMSS.sql.gz | mysql -u USUARIO -p BANCO`.

## 8. Atualizações
Envie o `atualizar.php` gerado, abra no navegador com a chave exibida e siga as instruções. Ele aplica as mudanças de banco sem apagar dados. Faça backup antes.

## 9. Lista final antes de vender
- [ ] HTTPS ativo e Diagnóstico sem itens vermelhos
- [ ] `instalar.php` apagado
- [ ] Modo produção
- [ ] E-mail de teste recebido
- [ ] Cobrança de teste no Asaas sandbox ativando a assinatura via webhook
- [ ] Backup rodando e restauração testada uma vez
- [ ] Termos e Privacidade revisados por advogado (os textos do pacote são um ponto de partida)
- [ ] Nome do sistema pesquisado no INPI (busca de marca) e domínio registrado
