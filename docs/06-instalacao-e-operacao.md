# 6. Instalação, atualização e operação

[← Arquitetura e API](05-arquitetura-e-api.md) · [Índice](../README.md#documentação)

Para quem **instala e mantém o servidor**. As seções mantêm a numeração original (1 a 11) para não quebrar referências antigas.

---

## 1. Pré-requisitos (o que o servidor já deve ter)

| Item | Detalhe |
|---|---|
| Apache **ou** Nginx | funcionando e acessível pela rede |
| PHP **8.0 ou superior** | com `pdo_mysql` (obrigatório). Recomendadas: `mbstring`, `zlib`, `zip` |
| MySQL 5.7+/8 ou MariaDB 10.3+ | um banco **vazio** + um usuário com todos os privilégios sobre ele |
| phpMyAdmin | só para criar o banco e, se quiser, importar/exportar backups |

Não precisa de: Docker, Node.js, Composer, `mod_rewrite`, `mysqldump`, acesso root ao MySQL, nem instalar pacotes.

## 2. Instalação (3 passos)

### Passo 1 — criar o banco no phpMyAdmin (1 minuto)
1. **Bases de dados** → nome `rotina` → collation `utf8mb4_unicode_ci` → **Criar**.
2. **Contas de usuário** → **Adicionar conta** → usuário `rotina`, host `localhost`, uma senha (anote-a).
   Em seguida abra o banco `rotina` → aba **Privilégios** → **Adicionar conta de usuário**/editar a conta `rotina` →
   marque **Todos os privilégios** → **Executar**. (Se o seu painel tiver a opção "Conceder todos os privilégios ao banco com o
   mesmo nome", ela faz tudo de uma vez.) O banco e o usuário podem ter qualquer nome — o instalador pergunta.

### Passo 2 — colocar os arquivos no servidor
Descompacte o pacote em `/var/www/rotina` (ou onde preferir):
```bash
sudo mkdir -p /var/www/rotina && sudo unzip sistema-rotina.zip -d /var/www/   # gera /var/www/rotina
cd /var/www/rotina
```

### Passo 3 — rodar o instalador
```bash
sudo ./install.sh
```
O script confere o PHP, acerta as permissões (dono = usuário do servidor web; `config/` e `storage/` privadas) e
pergunta se você quer concluir **pelo navegador** ou **pelo terminal**. Ele só mexe no Apache se você pedir (`--apache`).

* **Pelo navegador:** abra `http://IP-DO-SERVIDOR/install.php`, informe os dados do banco e do administrador. Pronto.
  O assistente testa a conexão, cria as tabelas, cria o administrador, **trava e apaga a si mesmo**.
* **Pelo terminal:** `./rotina install` (pergunta tudo). Para automação: `./rotina install --db-name=rotina --db-user=rotina --db-pass=... --admin-email=... --admin-pass=...`

> Sem acesso a terminal (hospedagem compartilhada)? Envie a pasta pelo FTP/gerenciador de arquivos, aponte o domínio
> para `public/` (ou use a raiz — veja 3.3) e abra `install.php`. O terminal só é necessário para *atualizar* e *restaurar*.

## 3. Acesso pelo IP do servidor

O sistema usa **endereços relativos**: funciona igual por IP, nome de máquina, domínio, porta diferente ou subpasta.
Não existe "URL do site" para configurar.

### 3.1 Apache — site dedicado (http://IP/)
```bash
sudo ./install.sh --apache         # gera /etc/apache2/sites-available/rotina.conf com o seu caminho e ativa
# se este servidor só serve o sistema:  sudo a2dissite 000-default && sudo systemctl reload apache2
```
Manual: copie `deploy/apache-ip.conf` (comentado) para `sites-available`, `a2enmod headers`, `a2ensite rotina`.

### 3.2 Apache — dentro de um site que já existe (http://IP/rotina/)
Use `deploy/apache-subpasta.conf` (um `Alias`; não altera o site atual).

### 3.3 Nginx
Use `deploy/nginx.conf` (PHP-FPM). No Nginx o `.htaccess` **não** é lido — os cabeçalhos de segurança já estão nesse arquivo.

### 3.4 Se o DocumentRoot não puder apontar para `public/`
Publicar o projeto inteiro em `/var/www/html/rotina/` também funciona: `index.php` redireciona para `public/` e os
`.htaccess` bloqueiam `app/`, `config/`, `storage/`, `bin/` e `migrations/` (Apache). **No Nginx isso não protege** — aponte
o `root` para `public/`. Confirme depois: `http://IP/config/config.php` deve dar **404/403**.

### 3.5 HTTPS
Funciona sem configurar nada (o cookie vira `Secure` sozinho quando a conexão é HTTPS). Em rede interna por IP, HTTP é suficiente,
mas **as senhas trafegam sem criptografia**: para acesso pela internet use HTTPS (ex.: Certbot) no Apache/Nginx.
Atrás de proxy reverso/balanceador, ligue `'trust_proxy' => true` em `config/config.php`.

## 4. Migrando os dados

### 4.1 Do sistema antigo (HTML com localStorage)
1. No navegador onde estão os dados: F12 → Console:
   ```js
   copy(JSON.stringify({
     users:   JSON.parse(localStorage.telecom_users   || '[]'),
     tasks:   JSON.parse(localStorage.telecom_tasks   || '[]'),
     actions: JSON.parse(localStorage.telecom_actions || '[]')
   }))
   ```
   Cole em um arquivo `legado.json` e envie ao servidor.
2. Simule e depois importe (seguro repetir; o que já existe é pulado):
   ```bash
   ./rotina import-legacy legado.json --dry-run
   ./rotina import-legacy legado.json
   ```
**Senhas:** as antigas estavam em texto puro e as de demonstração (`admin123`, `123456`) eram visíveis no código da página.
Senhas curtas ou conhecidas **não são migradas** (a conta fica sem senha utilizável até ser redefinida:
`./rotina create-admin "Nome" email senha` para administradores; um administrador redefine os técnicos pela tela).

### 4.2 Da versão Docker/Node (MySQL do contêiner)
O esquema do banco é **o mesmo**. Basta levar o banco:
1. No servidor antigo, exporte: `docker compose exec db mysqldump -uroot -p"$DB_ROOT_PASSWORD" --single-transaction rotina > rotina.sql`
   (ou phpMyAdmin → Exportar).
2. No phpMyAdmin do novo servidor: selecione o banco vazio → **Importar** → `rotina.sql`.
3. Rode o instalador (passo 3). Ele reconhece o banco com dados, aplica só a migration nova, **não cria outro administrador**
   e mantém todos os logins (as senhas `bcrypt` são compatíveis).

## 5. Atualização segura

> **Pela tela (mais fácil):** entre como administrador → aba **Sistema** → escolha o `.zip` → **Atualizar agora**.
> O sistema faz backup, aplica a nova versão e, se algo falhar, volta sozinho. Também há **Voltar versão anterior**.
> Requer a extensão PHP `zip` e permissão de escrita nas pastas do sistema (o instalador já ajusta). Se o `.zip` for maior que o
> limite do servidor (`upload_max_filesize`), use o terminal como abaixo.

Um novo pacote chega como `.zip` (ou pasta descompactada). Em uma linha:
```bash
./rotina update sistema-rotina-1.1.0.zip
```
Em ordem — qualquer falha **antes da troca** deixa o sistema atual intacto:

1. Pré-checagens (permissões, espaço em disco, banco acessível) e trava contra execução dupla.
2. Valida o pacote: estrutura, **sintaxe de todos os `.php`**, versão (recusa a mesma/mais antiga sem `--force`) e se alguma migration já aplicada foi alterada.
3. **Backup `pre-update`** + teste desse backup.
4. **Modo manutenção** (usuários veem "em atualização"; o navegador reenvia as alterações pendentes sozinho depois).
5. Guarda uma cópia do código atual em `storage/releases/`.
6. Aplica as migrations novas, troca o código e **confere** que a nova versão inicia.
7. Se a conferência falhar: **volta sozinho** ao código anterior e desliga a manutenção.

`./rotina rollback` volta o código manualmente (os dados do banco não mudam). `storage/logs/update.log` guarda o histórico.
`config/config.php` e `storage/` **nunca** são sobrescritos pela atualização.

**Regra de ouro para quem prepara pacotes:** migrations só **aditivas** (criar tabela/coluna anulável ou com padrão). Remover ou
renomear coluna é feito em duas versões. Assim o código antigo continua funcionando com o banco novo e o rollback nunca exige mexer nos dados.
Nunca edite uma migration já aplicada — o sistema recusa (checksum). Novas mudanças = novo arquivo `migrations/016_...sql`.

## 6. Backup e restauração

> **Pela tela:** aba **Sistema** → *Fazer backup agora*, ícone de download para guardar uma cópia, e ícone de relógio para **restaurar**
> (é preciso digitar RESTAURAR; antes disso o sistema guarda uma cópia do estado atual). A lixeira exclui um backup e **Limpar antigos** mantém só os mais recentes.

**Automático:** todo dia depois das 02:30 (configurável), disparado pelo uso normal do sistema, sem cron e sem `mysqldump`.
Arquivos em `storage/backups/` (`rotina_AAAAMMDD_HHMMSS.sql.gz`): instantâneo consistente (não trava o sistema), validado
ao final (gzip íntegro, todas as tabelas, marcador de conclusão). Rotação: 14 diários, 6 mensais, 10 com rótulo (`pre-update`, `pre-restore`, `manual`).

```bash
./rotina backup            # backup agora (opcional: ./rotina backup antes-da-mudanca)
./rotina backups           # lista
./rotina verify-backup     # testa o mais recente
./rotina restore storage/backups/rotina_20261004_023000.sql.gz
```
`restore` pede confirmação, faz um backup `pre-restore`, liga a manutenção, restaura, e se falhar no meio **volta sozinho** ao estado anterior.

**Pelo phpMyAdmin:** o `.sql.gz` é um dump comum — **Importar** funciona direto (ele já traz `DROP TABLE` + `CREATE TABLE`).
Para exportar manualmente: **Exportar → Rápido → SQL**. Antes de importar por cima de dados reais, faça `./rotina backup`.

> **Teste de restauração completo** (restaura num banco temporário e confere as contagens) exige que o usuário do MySQL possa
> *criar bancos*. Sem essa permissão (comum em hospedagem), o teste se limita à validação estrutural do arquivo e **avisa isso** — a
> restauração em si continua sendo feita normalmente.

**Cópia fora do servidor (importante):** backup no mesmo disco não protege contra perda do servidor. Exemplo de cron:
```
15 3 * * *  rsync -a /var/www/rotina/storage/backups/ usuario@outro-servidor:/backups/rotina/
```

## 7. Rotinas agendadas

Rodam **dentro do próprio sistema**, depois de responder ao usuário (ele não espera): **redistribuição de pendências** (a cada ~1 min, se ligada);
métricas do dia a cada 30 min; após 00:10 fecham o dia anterior e fazem a manutenção (retenção da auditoria, limpeza de tentativas antigas);
backup diário. Usam lock no banco (acessos simultâneos não duplicam trabalho).

*Limitação:* só disparam quando alguém usa o sistema. Se quiser horário exato mesmo sem acessos, use o cron do servidor e
desligue o automático (`'auto_jobs' => false` em `config/config.php`) e agende **a cada minuto** (a Redistribuição precisa desse ritmo):
```
* * * * *  /var/www/rotina/rotina cron
```

### 7.1 Redistribuição automática de pendências

A regra completa (quem recebe, como escolhe, limite de trocas, segurança) está em
[Regras e fluxos → Redistribuição automática](04-regras-e-fluxos.md#6-redistribuição-automática).
Do ponto de vista de operação, o que importa é: ela roda a cada ~1 minuto **enquanto alguém usa o sistema**. Se você usa o
`cron` em vez do automático (`'auto_jobs' => false`), agende **a cada minuto** (`* * * * *`) para ela funcionar bem.

## 8. Operação do dia a dia

```bash
./rotina status                 # versão, banco, migrations, último backup, manutenção
./rotina create-admin           # criar/redefinir administrador
./rotina migrate                # aplicar migrations pendentes (a atualização já faz isso)
curl -s http://IP/api.php?r=healthz      # {"status":"ok",...}  (monitoramento)
tail -f storage/logs/app.log    # erros da aplicação
```
Rode sempre pelo `./rotina` (ou `sudo -u www-data php bin/rotina.php`): ele executa como o dono dos arquivos, evitando que backups
e logs fiquem com dono errado (o que faria o site falhar ao gravá-los).
Opções avançadas: `config/config.sample.php` (todas comentadas).

## 9. Segurança

* Senhas com bcrypt (custo 12), mínimo de 4 caracteres (sistema interno: pode ser simples), tempo de resposta igual para e-mail inexistente. **Não há bloqueio de conta por senha errada** (errar nunca trava o usuário); o freio é o **limite por IP**, gravado no banco: 30 tentativas de login por 15 min e 10 pedidos de cadastro por hora.
* Permissões **checadas no servidor**; sessão em cookie `httpOnly` + `SameSite=Strict` assinado (HMAC-SHA256), relido do banco a cada requisição (bloquear/rebaixar/trocar senha vale na hora); cabeçalho anti-CSRF.
* Anti-XSS armazenado nos campos curtos; CSV exportado protegido contra injeção de fórmula; auditoria de quem fez o quê (com IP).
* `config.php` com permissão 640; `app/`, `config/`, `storage/`, `bin/`, `migrations/` fora da web; modo manutenção; erros PHP nunca exibidos ao visitante.
* Depois do primeiro login: **conclua a instalação guardando a senha do administrador** e faça um primeiro backup.

## 10. Limitações conhecidas (leia)

* **O que foi testado aqui:** PHP 8.3 + Apache 2.4 (`mod_php`) + MariaDB 10.11 — API (14 testes de integração do projeto original), instalação web e por terminal, instalação sobre banco existente da versão Docker, importação do sistema antigo, backup/restauração, atualização (sucesso, pacote com erro de sintaxe, migration adulterada, falha em execução com rollback automático), rollback manual, subpasta, modo manutenção e rotinas automáticas. O divisor de SQL foi submetido a teste de estresse com cortes aleatórios.
* **Não foi testado:** Nginx + PHP-FPM (o `deploy/nginx.conf` segue o padrão, mas valide com `nginx -t` e a lista da seção 3.4), MySQL 8.x/5.7 reais, PHP 8.0–8.2, e a **interface no navegador** (apenas a camada de rede da interface foi alterada; teste o login e uma importação de conjunto após instalar). Faça a primeira instalação num ambiente de teste.
* A interface carrega Tailwind, Chart.js e FontAwesome de CDNs: os navegadores precisam de internet e a CSP precisa permitir `unsafe-inline`. Para uso 100% offline, hospede esses arquivos em `public/`.
* O painel carrega pendentes + os últimos 120 dias (`bootstrap_days`); a tela confere mudanças a cada 5 s (só baixa dados quando algo mudou), não é em tempo real.
* Sem "esqueci minha senha" por e-mail: o administrador redefine.
* `exec()` desativado no PHP não impede o sistema, apenas pula a verificação de sintaxe do pacote na atualização.
* Backup sem criptografia (a versão Docker tinha criptografia opcional): proteja `storage/backups/` (já é bloqueada na web, permissão 600) e, ao copiar para outro servidor, use canal seguro/disco criptografado.

## 11. Estrutura

```
public/        ÚNICA pasta exposta na web: index.html, api-adapter.js, api.php, install.php (some após instalar)
app/           código PHP (Api, Sync, Auth, Backup, Updater, Installer, Migrator, Jobs...)
bin/rotina.php ferramenta de terminal   ·   ./rotina  atalho que roda como o dono dos arquivos
migrations/    001 a 015 (lista em docs/05-arquitetura-e-api.md)   (novas: 016_...sql)
config/        config.php (gerado pelo instalador, privado) · config.sample.php
storage/       backups, logs, releases (cópias para rollback) — privado
deploy/        modelos de Apache (IP e subpasta) e Nginx
tests/         testes: regras da redistribuição (PHP, sem banco) e integração da API (Node, só para desenvolvedores)
docs/          documentação: visão geral, guias, regras, arquitetura e esta operação
install.sh     preparação + instalação guiada
```
