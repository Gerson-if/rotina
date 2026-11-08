# 5. Arquitetura, banco de dados e API

[← Regras e fluxos](04-regras-e-fluxos.md) · Próximo: [Instalação e operação →](06-instalacao-e-operacao.md)

Para quem mantém o código. Em uma frase: **uma página única no navegador conversa com um PHP simples (sem framework) que grava em MySQL/MariaDB.**

## 1. Visão em camadas

```mermaid
flowchart LR
    subgraph Navegador
      UI[index.html<br/>telas e regras de tela]
      AD[api-adapter.js<br/>rede, estado, sincronização]
      UI --- AD
    end
    AD -- "HTTP JSON" --> API[public/api.php]
    subgraph Servidor PHP
      API --> R[Api.php<br/>rotas]
      R --> S[Sync.php<br/>gravação de alterações]
      R --> RD[Redistribution.php]
      R --> J[Jobs.php<br/>rotinas]
      R --> O[Auth, Http, Db, Mappers, Util...]
    end
    S --> DB[(MySQL / MariaDB)]
    RD --> DB
    J --> DB
```

## 2. Front-end

- **`public/index.html`** contém todas as telas, estilos e a lógica de interface (uma única página, sem build).
  Usa Tailwind, Chart.js e FontAwesome por CDN (e leitura de planilhas no navegador); o navegador precisa de internet para elas.
- **`public/api-adapter.js`** é a camada de rede. Mantém o **estado em memória** (`state`) e fala com o servidor.
  O parâmetro `?v=` no `<script>` força o navegador a baixar a versão nova a cada release.
- **Como a tela fica atualizada:**
  1. A cada **5 s** o navegador pergunta `stamp` (consulta minúscula). Só se o carimbo mudou ele baixa os dados (`bootstrap`).
  2. Quando o usuário altera algo, o adaptador envia **só o que mudou** (`sync`); se a rede cair, tenta de novo sozinho.
  3. A cada **15 s** um batimento atualiza a presença na equipe (o servidor limita a gravação a 1 vez por 20 s).
- A tela esconde o que o perfil não pode usar, mas **quem decide é o servidor**.

## 3. Back-end (`app/`)

| Arquivo | Responsabilidade |
|---|---|
| `Api.php` | Roteamento (`api.php?r=...`) e os endpoints de leitura/escrita |
| `Sync.php` | **Grava** alterações de verificações, usuários e procedimentos: valida, aplica permissões, controla versão e gera os eventos de auditoria, tudo em transação |
| `UserDeletion.php` | Exclusão **definitiva** de usuário, em transação: transfere as verificações em aberto, passa o histórico para o “Usuário removido” e preserva a auditoria |
| `Redistribution.php` | Regra e execução da redistribuição automática e da designação de pendências |
| `Auth.php` | Sessão por cookie assinado (HMAC-SHA256), relida do banco a cada requisição |
| `Http.php` | Entrada/saída JSON, limite de tentativas por IP, erros (`HttpError`) |
| `Db.php` | Conexão PDO, transações, travas (`GET_LOCK`), fuso horário |
| `Mappers.php` | Converte linhas do banco em objetos para a tela (inclui a classe real da verificação) |
| `Jobs.php` | Rotinas agendadas "de carona" (métricas, manutenção, backup, redistribuição) |
| `Backup.php`, `Updater.php`, `Installer.php`, `Migrator.php`, `SqlSplitter.php`, `System.php` | Backup/restauração, atualização com rollback, instalação, migrations, tela Sistema |
| `Branding.php` | Plano de fundo da tela de login |
| `Config.php`, `Util.php` | Configuração, utilidades (IDs, auditoria, texto) |

Princípios: **sem framework**, `declare(strict_types=1)`, SQL com parâmetros, todo erro "esperado" vira `HttpError` com mensagem em português.

## 4. Banco de dados

### Tabelas

| Tabela | Conteúdo |
|---|---|
| `users` | Contas. `role` (ADM/TEC), `shift` (Manhã, Tarde, Noite, Madrugada, Geral), `status` (Pendente/Aprovado/Bloqueado), senha (bcrypt), presença (`last_seen_at`), `token_version` (invalida sessões ao trocar a senha) |
| `verifications` | **A verificação** (veja abaixo) |
| `verification_events` | Linha do tempo de cada verificação (um registro por evento, com `details` em JSON) |
| `batches` | Conjuntos criados por planilha (e agrupamentos nomeados) |
| `actions` | Procedimentos (com exclusão lógica em `deleted_at`) |
| `audit_log` | Auditoria geral (quem fez o quê, com IP); retenção configurável |
| `daily_metrics` | Totais diários por técnico (alimenta os gráficos) |
| `rate_limits` | Contadores de tentativas por IP |
| `app_state` | Pares chave/valor do sistema: configuração da Redistribuição (`redist`), ponteiro do rodízio (`redist_rr`), última conferência (`redist_ts`), última execução (`redist_last`, marcadores das rotinas) |
| `schema_migrations` | Quais migrations foram aplicadas (com checksum) |

### A tabela `verifications` (grupos de colunas)

| Grupo | Colunas |
|---|---|
| Identidade | `id`, `pppoe`, `batch_id`, `created_by`, `created_at`, `updated_at` |
| Atribuição | `tech_id`, `shift`, `due_date`, `priority`, `is_single` |
| Classe e histórico | `kind` (Normal/Adicional/Reaberta), `prev_count` (quantas verificações anteriores do PPPoE), `repeat_of` (a anterior), `root_id` (a primeira da cadeia de reaberturas; só nas Reabertas) |
| Atendimento | `ligou`, `nao_atende`, `resolvido`, `os_criada`, `action_id`, `obs`, `em_execucao`, `started_at`, `finished_at`, `finalizado`, `handle_time_min` |
| Pendência | `pending_contact`, `pending_since`, `pending_note` |
| Passagem de bastão | `handoff_note`, `handoff_from`, `handoff_at` |
| Redistribuição | `redist_count` (trocas feitas pelo sistema), `redist_at` (última troca) |
| Controle | `version` (concorrência otimista), `active_pppoe` (coluna que garante **uma verificação aberta por PPPoE**) |

`active_pppoe` tem índice **único**: o banco impede duas verificações abertas do mesmo PPPoE, independentemente da tela.

### Retrabalho: cadeia de reaberturas (`root_id`)

Introduzido na **versão 1.8.14** (migration `016_rework_chain.sql`). Leia isto antes de mexer em reabertura, produtividade ou nas listas.

**O modelo (não mudou):** reabrir uma verificação concluída **insere uma linha nova** em `verifications` com `kind = 'REABERTA'` e
`repeat_of = <id da verificação que foi reaberta>`. A linha original **não é alterada**: continua `finalizado = 1`, com o mesmo `tech_id`, `finished_at`,
resultado e `version`. É por isso que a produtividade (todas as consultas por `finalizado = 1` e `finished_at`) continua certa:
cada conclusão é uma linha, e cada linha conta para quem a concluiu.

**O que a 016 acrescentou:** uma única coluna, `root_id VARCHAR(40) NULL`, com índice `ix_verif_root`.

| Linha | `repeat_of` | `root_id` |
|---|---|---|
| Original (Normal/Adicional), reaberta ou não | o que já tinha | **sempre `NULL`** (não é alterada) |
| 1ª reaberta | id da original | id da **original** |
| 2ª reaberta (reabriu a 1ª) | id da 1ª reaberta | id da **original** (herda a raiz) |
| 3ª, 4ª... | id da anterior | id da **original** |

A **raiz da cadeia** de qualquer linha é `COALESCE(root_id, id)`. Toda a cadeia (original + reabertas) compartilha a mesma raiz.
**Quantas vezes o PPPoE foi reaberto** = `COUNT(*)` das linhas com `root_id = <raiz>`. Sem chave estrangeira, de propósito: excluir um
conjunto ou verificação não pode ser travado pelo histórico.

**Quem grava:** só o `sync`, na criação de uma verificação com `reopenOf` (`app/Sync.php`). Ele valida (a anterior precisa estar
concluída e ser do mesmo PPPoE; técnico só reabre as próprias), calcula a raiz e grava `root_id` na **nova** linha. Nenhuma outra
parte do sistema escreve `root_id`.

**Como chega na tela** (`app/Mappers.php`, usado por `bootstrap`, `sync` e pelo CSV): duas colunas calculadas no `SELECT`
(`VERIF_SELECT`) viram dois campos de cada verificação:

| Campo da API | Significado |
|---|---|
| `reopens` | Quantas vezes o PPPoE foi reaberto: `COUNT(*) WHERE root_id = COALESCE(v.root_id, v.id)`. É o mesmo número para a original e para todas as reabertas da cadeia. `0` = nunca reaberto. |
| `chain` | Raiz da cadeia (id da original), ou `null` quando `reopens = 0`. A tela agrupa por `chain`. |

O adaptador (`public/api-adapter.js`) copia `reopens` e `chain` de volta para a tela depois de cada `sync`. Esses campos **não** fazem
parte do que a tela envia (`pickTask`): são só de leitura, decididos pelo servidor.

**O que a tela faz com isso** (`public/index.html`, bloco *Retrabalho*): `rwCollapse()` junta a cadeia numa linha só, **dentro da mesma
lista** (chave = conjunto/atribuição única + `chain`), mantendo a verificação **mais recente**. `rwOpen()` diz se há reabertura em
andamento (toda verificação em aberto está sempre carregada na tela, então basta olhar `state.tasks`). `kindBadge()` desenha o selo.
Ao reabrir, a tela já marca a cadeia na hora (`reopenAsNew`) e o servidor confirma. Só **exibição**: nada aqui altera o banco.

**Indicador de retrabalho** (`analytics`): `totals.rework` = reaberturas criadas no período
(`kind = 'REABERTA' AND created_at >= início`); `techs[].rework` agrupa por `tech_id` da verificação reaberta (a que `repeat_of`
aponta). Para o técnico, `totals.rework` só conta as reaberturas de verificações dele.

**Consultas úteis:**

```sql
-- PPPoE com mais reaberturas
SELECT COALESCE(root_id, id) AS raiz, pppoe, COUNT(*) AS reaberturas
  FROM verifications WHERE kind = 'REABERTA' GROUP BY COALESCE(root_id, id), pppoe ORDER BY reaberturas DESC;

-- Retrabalho por técnico (dono da verificação que foi reaberta), nos últimos 30 dias
SELECT u.name, COUNT(*) AS reabertas
  FROM verifications r JOIN verifications o ON o.id = r.repeat_of JOIN users u ON u.id = o.tech_id
 WHERE r.kind = 'REABERTA' AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY u.id, u.name ORDER BY reabertas DESC;

-- Todas as verificações de uma cadeia (a original tem id = raiz)
SELECT id, kind, tech_id, finalizado, finished_at FROM verifications WHERE id = :raiz OR root_id = :raiz ORDER BY created_at, id;
```

**O que a migration 016 fez nos dados existentes:** preencheu `root_id` das reabertas que já existiam (8 passadas, uma por nível da
cadeia; cobre até 8 reaberturas seguidas) e não alterou mais nada. Reaberturas antigas aparecem agrupadas na tela sem nenhum outro ajuste.

**Regras para futuras atualizações (não quebre):**

1. **Nunca edite a original ao reabrir** (nem `root_id`, nem `version`, nem `finished_at`): a produtividade e a auditoria dependem de ela ficar intacta.
2. **Produtividade continua por linha.** Não troque `COUNT(*)` por `COUNT(DISTINCT pppoe)` nas consultas de produtividade, ranking, TMA ou dashboards: isso apagaria o trabalho do técnico da 1ª conclusão. Se um dia quiser "PPPoEs únicos", crie um indicador **separado**.
3. **Agrupar é só exibição.** Se criar uma lista nova, decida se ela mostra *verificações* (não agrupa, como o Banco de Verificações) ou *PPPoEs* (use `rwCollapse`). Não apague linhas para "evitar duplicidade": a duplicidade de **abertas** já é impedida por `active_pppoe`.
4. **`chain`/`reopens` são só de leitura** no front. Não os envie no `sync` nem os grave a partir da tela.
5. Se mudar o formato de `id` das verificações, reveja o `VARCHAR(40)` de `root_id` e de `repeat_of`.
6. Se uma verificação-raiz for **excluída**, as reabertas continuam com o mesmo `root_id` (órfão) e continuam agrupadas entre si; o número de reaberturas diminui apenas pelas linhas excluídas. Não é preciso corrigir nada.
7. Migrations já aplicadas não podem ser editadas (o checksum aborta): mudanças neste modelo entram em uma **nova** migration (`017_...`).

### Migrations (`migrations/`)

São **sempre aditivas** e aplicadas em ordem; o checksum impede alterar uma já aplicada.

| # | Para que serve |
|---|---|
| 001 | Esquema inicial |
| 002 | Procedimentos padrão (versão original) |
| 003 | Tabelas de apoio da versão PHP (limite de tentativas, estado do sistema) |
| 004 | Linha do tempo (histórico) de cada verificação |
| 005 | Perfil do técnico (bio) e presença online |
| 006 | Turno "Madrugada" e foto de perfil |
| 007 | Corrige classe e contagem de anteriores de PPPoE antigos |
| 008 | Classe "Reaberta" (reabrir cria uma verificação nova) |
| 009 | Remove histórico "órfão" de verificações já excluídas |
| 010 | Lista atual de procedimentos padrão |
| 011 | Marca as verificações de "Atribuição Única" |
| 012 | "Pendente de contato" (fila compartilhada) |
| 013 | Redistribuição: `redist_count`, `redist_at` |
| 014 | Índices para grande volume (gráficos, sincronização, redistribuição) |
| 015 | Passagem de bastão (`handoff_*`) |
| 016 | Retrabalho: `root_id` (cadeia de reaberturas) + preenchimento das reaberturas antigas |

## 5. API

Tudo passa por `public/api.php?r=<rota>`. Respostas em JSON; erros trazem `{"error": "mensagem em português"}`.
Métodos que alteram dados exigem **POST** e o cabeçalho anti-CSRF; a sessão vai em cookie `httpOnly`.

| Rota | Método | Quem | Para que serve |
|---|---|---|---|
| `healthz` | GET | público | Monitoramento (`{"status":"ok"}`) |
| `auth/login` · `auth/register` · `auth/logout` · `auth/me` | POST/POST/POST/GET | público/logado | Entrar, pedir cadastro, sair, ver a própria sessão |
| `bootstrap` | GET | logado | Carga inicial da tela (usuários, verificações, procedimentos); dispara as rotinas depois de responder |
| `stamp` | GET | logado | Carimbo de mudança (polling leve); também aciona a redistribuição, depois de responder |
| `sync` | POST | logado | Grava alterações (criar, editar, excluir) com validação, permissões e versão |
| `analytics` | GET | logado | Séries dos gráficos por período (semana/mês/ano). Inclui o indicador de retrabalho: `totals.rework` e, para o ADM, `techs[].rework` |
| `team` | GET | logado | Presença e situação da equipe (também registra o batimento) |
| `profile`, `profile/verifications` | GET | logado | Perfil de um técnico e suas verificações |
| `profile/bio` · `profile/avatar` | POST | logado | Editar o próprio perfil |
| `avatar` | GET | logado | Foto de perfil |
| `verification/audit` | GET | logado | Linha do tempo de uma verificação |
| `verification/search` | GET | logado | Busca de verificações concluídas (*Soluções da equipe*) |
| `pppoe/check` | POST | ADM | Histórico de uma lista de PPPoE antes de atribuir |
| `pending/stats` | GET | ADM | Ranking *quem deixa / quem retoma* (`days`) |
| `audit` | GET | ADM | Auditoria geral |
| `export/verifications.csv` | GET | ADM | Exporta verificações (`from`, `to`; padrão últimos 30 dias). CSV com `;` e BOM para abrir no Excel; protegido contra injeção de fórmula. A coluna *Reaberturas do PPPoE* traz `reopens`. *Não há botão na tela: acesse pela URL* |
| `branding/login` · `branding/login-file` | GET | público | Plano de fundo da tela de login |
| `admin/system` | GET | ADM | Estado do sistema (versão, banco, backups, manutenção) |
| `admin/redistribution` | GET/POST | ADM | Lê / salva a configuração (e o estado) da Redistribuição |
| `admin/redistribution/run` | POST | ADM | Simular (`dry`) ou executar agora |
| `admin/pending/assign` | POST | ADM | Designar uma pendência (`id`, `techId` opcional; sem `techId` usa o automático) |
| `admin/redistribution/suggest` | POST | ADM | Sugere técnicos para N novas demandas pela mesma regra. **Sem uso na interface atual** (reservada) |
| `admin/users/delete-preview` | GET | ADM | O que a exclusão do usuário (`id`) afeta: verificações em aberto/pendentes/em atendimento/concluídas, eventos, métricas e auditoria |
| `admin/users/delete` | POST | ADM | Exclui definitivamente (`id`, `confirmEmail`; `transferTo` obrigatório se houver verificações em aberto). Erros: `NEEDS_TRANSFER`, `VALIDATION`, `NOT_FOUND` |
| `admin/db-test` | POST | ADM | Testa a conexão com o banco |
| `admin/backup`, `.../download`, `.../delete`, `.../prune`, `.../restore` | POST/GET | ADM | Backup e restauração |
| `admin/update` · `admin/rollback` | POST | ADM | Atualizar por `.zip` / voltar a versão anterior |
| `admin/branding`, `.../chunk`, `.../finish`, `.../remove` | GET/POST | ADM | Enviar e remover o plano de fundo (envio em partes) |

### Como o `sync` protege os dados

1. Roda **numa transação** e só aceita campos conhecidos, com tamanhos e formatos validados.
2. **Permissões por perfil** (ADM altera tudo; técnico só os campos do atendimento, e só o dono).
3. **Versão otimista:** cada verificação tem `version`. Se a versão enviada não é a do banco, a gravação é recusada com o aviso de que outra pessoa alterou.
4. Passagens especiais (assumir pendência, reabrir) têm regras próprias, descritas em [Regras e fluxos](04-regras-e-fluxos.md).
5. Gera os **eventos de auditoria** na mesma transação (se algo falha, nada é gravado pela metade).

## 6. Rotinas agendadas

Rodam **depois de responder ao usuário**, protegidas por trava no banco (nunca duplicam).

| Rotina | Quando | O que faz |
|---|---|---|
| Redistribuição | A cada ~1 min (se ligada) | Distribui a fila central (veja [Regras e fluxos](04-regras-e-fluxos.md#6-redistribuição-automática)) |
| Métricas do dia | A cada 30 min | Atualiza `daily_metrics` |
| Fechamento e manutenção | Depois das 00:10 | Fecha o dia anterior; aplica a retenção da auditoria; limpa tentativas antigas |
| Backup | Diário, depois das 02:30 | Backup automático com rotação |

Disparam por `bootstrap`, por `stamp` (redistribuição) ou por `./rotina cron`. Se `auto_jobs` estiver `false`, só o `cron` as dispara.

## 7. Configuração (`config/config.php`)

O instalador gera o essencial (`db`, `secret`, `tz`). As demais chaves usam o padrão; todas estão comentadas em `config/config.sample.php`.

| Chave | Padrão | Efeito |
|---|---|---|
| `session_hours` | 12 | Duração da sessão |
| `cookie_secure` | `auto` | `Secure` só em HTTPS (`auto`), ou forçar `true`/`false` |
| `trust_proxy` | `false` | `true` só atrás de proxy reverso confiável |
| `bcrypt_cost` | 12 | Custo das senhas |
| `bootstrap_days` | 120 | Dias de histórico concluído que a tela carrega |
| `audit_retention_days` | 365 | Retenção da auditoria geral |
| `auto_jobs` | `true` | Rotinas "de carona"; com cron, use `false` |
| `backup` | `auto`, `02:30`, 14 diários, 6 mensais, 10 rotulados | Backup automático e rotação |

**Configuração da Redistribuição** (guardada no banco, em `app_state`, chave `redist`; editada pela tela):

| Campo | Significado | Padrão |
|---|---|---|
| `on` | Liga a rotina | 0 |
| `how` | `L` menos trabalho, `R` rodízio | `L` |
| `wait` | Espera mínima antes de distribuir (min) | 30 |
| `cap` | Máximo de verificações por técnico | 15 |
| `max` | Máximo de trocas por verificação | 3 |
| `en` | Turnos que recebem | todos |
| `when`, `win`, `sh` | Restos de uma versão anterior (abertura de turno). **Sem efeito**: a rotina não usa mais janela de horário | — |

## 8. Segurança (resumo técnico)

- Senhas com **bcrypt**; mínimo de 4 caracteres (sistema interno). Tempo de resposta igual para e-mail inexistente.
- **Não há bloqueio de conta por tentativas erradas**: errar a senha nunca trava o usuário. Também **não há limite de tentativas por IP** no login nem no cadastro.
- Sessão em cookie `httpOnly` + `SameSite=Strict`, assinada; **relida do banco a cada requisição** (bloquear, rebaixar ou trocar senha vale na hora).
- Permissões **no servidor**; cabeçalho anti-CSRF; campos de texto curtos filtrados contra XSS; CSV protegido contra injeção de fórmula.
- Auditoria de login, criação, alteração, backup e configuração, com IP.
- `app/`, `config/`, `storage/`, `bin/`, `migrations/` fora da web; erros PHP nunca aparecem para o visitante.

## 9. Testes

| Arquivo | O que cobre | Como rodar |
|---|---|---|
| `tests/redistribution.test.php` | Regras puras da redistribuição: escolha justa, rodízio, limite, validação da configuração | `php tests/redistribution.test.php` (não precisa de banco) |
| `tests/api.test.js` | Integração da API contra um servidor de teste (Node; só para desenvolvedores) | Veja o cabeçalho do arquivo |

## 10. Pontos de atenção conhecidos

- Há código **sem uso** da fase em que a Atribuição por Planilha/Única tinha um modo "Automático" (rota `admin/redistribution/suggest`, ramos `AUTO` no front, e `redist.when/win/sh`). É inofensivo e está listado aqui para não confundir; pode ser removido numa limpeza futura.
- `users.failed_logins` e `users.locked_until` existem no banco, mas o login **não bloqueia** mais contas; `locked_until` ainda é lido pela Redistribuição (quem estiver com bloqueio vigente não recebe).
