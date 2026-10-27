# 3. Guia do administrador

[← Guia do técnico](02-guia-do-tecnico.md) · Próximo: [Regras e fluxos →](04-regras-e-fluxos.md)

Este guia percorre o menu lateral do administrador, na ordem em que você costuma usar. As regras por trás de cada tela
(pendência, redistribuição, observações) estão explicadas com mais detalhe em [Regras e fluxos](04-regras-e-fluxos.md).

> **Dica geral:** nas telas de formulário (Atribuição, Procedimentos, Redistribuição) a **barra de ações fica fixa no topo**:
> você salva, importa ou limpa sem rolar até o fim. Janelas (modais) fecham com **Esc** ou pelo botão; clicar fora **não** fecha.
> As confirmações (distribuir, excluir, atualizar...) são **avisos do próprio sistema**, não o pop-up do navegador. Nos campos de **data**, clicar em qualquer ponto do campo abre o calendário.

---

## 1. Analytics & Tendências

A visão geral da operação, com um seletor de período **Semana / Mês / Ano**. Atualiza sozinha quando chegam dados novos.

| Gráfico | O que mostra |
|---|---|
| **Produção por período e resultado** | Concluídas por dia, semana ou mês, separadas em *Resolvido direto*, *Ordem de serviço (O.S.)* e *Outros tratamentos* |
| **Procedimentos aplicados** | Quais procedimentos foram mais usados |
| **Cumprimento do prazo** | Quantas foram concluídas **até** o prazo e quantas **depois** (compara a data da conclusão com a data do prazo) |
| **Tempo médio de atendimento** | Média entre *Iniciar* e *Concluir*. Atendimentos com mais de 8 h (esquecidos abertos) não entram na média |
| **Em aberto por tempo de atraso** | Verificações não concluídas em faixas: *a vencer*, *vence hoje*, *atrasada hoje*, *1 dia*, *2–3 dias*, *4–7 dias* e *mais de 7 dias de atraso* |
| **Carga por turno** | Quanto trabalho em aberto cada turno tem |
| **Desempenho por técnico** | Produção por pessoa no período |

---

## 2. Verificação (Conjuntos e Atribuições únicas)

Duas listas do que foi atribuído e do andamento de cada conjunto:

- **Conjuntos:** o que veio de *Atribuição por Planilha*.
- **Atribuições únicas:** o que foi criado um a um em *Atribuição Única*.

Os números ao lado de **Conjuntos** e **Atribuições únicas** no menu contam **só o que ainda tem verificação em aberto**: um conjunto
100% concluído (ou uma atribuição única concluída) deixa de contar e, quando não resta nada em aberto, o número some. Assim ele não
cresce para sempre. Os concluídos continuam na lista, com o filtro *Concluídas*.

Use a **busca** (conjunto, login PPPoE ou técnico) e os filtros: **situação** (**em aberto** = não concluídas, não iniciadas,
**pendentes de contato**, em execução, concluídas, atrasadas), **turno**, **prioridade**, **datas** (De/Até) e **ordenação** (mais recentes, mais
pendentes, mais atrasadas, menor conclusão, nome A–Z). *Limpar filtros* volta ao padrão.

## 3. Banco de Verificações

A lista global de **todas** as verificações, com busca por PPPoE e filtros por conjunto, técnico, prioridade e status
(atrasadas, aguardando início no prazo, pendentes de contato, em execução, concluídas). Aqui você pode:

- **Reatribuir** a verificação a outro técnico, com **prazo individual**;
- abrir o **Raio-X** (os detalhes da verificação) e, dali, a linha do tempo;
- **excluir** com confirmação (a exclusão apaga a verificação e o histórico dela).

---

## 4. Equipe & Aprovações

- **Equipe: presença e perfis.** Quem está online, em atendimento ou ocioso, último acesso e desempenho. Clique em um técnico
  para abrir o perfil completo.
- **Solicitações de acesso pendentes.** Os cadastros feitos na tela de login ficam aqui. **Aprove** para liberar o acesso
  ou **rejeite**. Um cadastro não aprovado não entra no sistema.
- **Gestão de usuários e técnicos.** Cadastre colaboradores (**Novo Técnico / Usuário**) e, em cada um, edite **nome, e-mail,
  perfil (Técnico ou Administrador) e turno** (a lista inclui *Geral (ADM)*) e **redefina a senha**. Há **busca** por nome,
  e-mail ou turno e atalhos *Todos / Técnicos / ADM* com a contagem de cada um. O sistema **não permite ficar sem nenhum administrador ativo**.

## 5. Sistema

| Bloco | Para que serve |
|---|---|
| **Plano de fundo da tela de login** | Imagem (JPG, PNG ou WebP, reduzida a até 1920 px) ou vídeo (MP4 H.264 ou WebM, até 40 MB, sem som e em repetição). Dá para trocar ou remover; volta ao visual padrão |
| **Conexão com o banco de dados** | Botão *Testar conexão*: abre uma conexão nova com a configuração do servidor e mostra o resultado |
| **Backup** | O sistema já faz um backup automático por dia. Aqui você gera um agora, baixa uma cópia e escolhe quantos manter (3, 5, 10 ou 20); *Limpar antigos* apaga o excedente. Baixe de vez em quando e guarde **fora do servidor** |
| **Atualização do sistema** | Envie o `.zip` da nova versão: o sistema faz backup, atualiza e, se algo falhar, volta sozinho. Há *Permitir reaplicar a mesma versão (ou uma mais antiga)* e *Voltar versão anterior* |

---

## 6. Procedimentos

São as opções que o técnico escolhe no atendimento (ex.: "Troca de equipamento").

- **Criar:** escreva o nome em *Novo procedimento* e clique em **Adicionar**. Não aceita dois com o mesmo nome.
- **Renomear:** lápis. Os registros antigos passam a mostrar o nome novo. `Esc` cancela a edição.
- **Apagar:** lixeira. O sistema **pede confirmação** e informa quantas verificações usam esse procedimento (elas ficam sem procedimento no histórico).
- **"Nenhuma Ação"** é o valor padrão e não pode ser apagado.

Procedimentos que já vêm configurados: *Troca de equipamento*, *Ajuste de DBM*, *Necessário visita técnica ao local*,
*Feito procedimento no equipamento*, *Outros* e *Sem sucesso no contato*.

---

## 7. Atribuição Única

Cria **uma** verificação para um PPPoE.

| Campo | Observação |
|---|---|
| Login PPPoE | O cliente a verificar |
| Problema detectado / observações iniciais (opcional) | Texto seu, que vai para o campo de observações da verificação |
| Técnico responsável | Quem atende |
| Turno, Prazo da rotina, Prioridade | Quando e com que urgência |
| Conjunto (opcional) | Nome para agrupar (ex.: "Avulsas") |

O que o sistema faz por você:

- Se o PPPoE **já tem verificação aberta**, ela é **reatribuída** (não cria uma segunda).
- Se o PPPoE **já teve verificação concluída**, a nova entra como **Adicional** e a tela mostra o histórico antes de você confirmar.
- Registra na auditoria que a verificação foi criada por **Atribuição única** (essa informação **não** vai para as observações do técnico).

## 8. Atribuição por Planilha

Cria **várias** verificações de uma vez.

1. Defina **Nome do conjunto**, **Prazo**, **Turno padrão** (ou *Respeitar turno de cada técnico*) e **Prioridade inicial**.
2. Envie a **planilha** (`.csv`, `.xlsx`, `.xls`, `.ods` ou `.txt`; **só a coluna de login é usada**) ou **cole os logins**.
3. O sistema consulta o **histórico** desses PPPoE. Os já verificados antes entram como **Adicional**. Para os que **ainda
   estão em aberto**, você escolhe: mover para o novo técnico (padrão) ou marcar **"Não mexer nas que ainda estão em aberto"**
   (mantém com o técnico atual). Dá para **ver quais já foram verificados e como terminaram**.
4. Escolha a **regra de divisão** e os técnicos participantes:
   - **Divisão igualitária:** partes iguais entre os técnicos marcados;
   - **Definir cotas:** você diz quantos cada um recebe.
5. Confira a **prévia** e clique em **Importar e atribuir**.

Cada verificação criada guarda na auditoria a **origem** (planilha ou planilha com cotas). Isso **não** aparece nas observações.

---

## 9. Pendências (a fila central)

Mostra as verificações que técnicos **deixaram pendentes de contato**. A que espera há mais tempo aparece primeiro.

**Cartões** (clique para filtrar a lista):

| Cartão | O que conta |
|---|---|
| **Pendentes** | Todas as pendências na fila |
| **Acima de 24h** | As que esperam há 24 horas ou mais |
| **Prazo vencido** | As cujo prazo já passou |
| **Já redistribuídas** | As que o sistema já passou a outro técnico ao menos uma vez |
| **Precisam de você** | As que **esgotaram o limite de trocas**: o sistema parou de mexer, cabe a você decidir |
| **Quem deixa / retoma** | Troca a lista por um **ranking por técnico** (com busca, período, ordenação e paginação): quantas pendências cada um deixou e quantas retomou. Quando o sistema ou o ADM designa, isso **não** conta como "retomou" |

**Ações:**

- **Designar** (em cada linha): abre uma janela com a nota de quem deixou e duas formas de resolver.
  **Redistribuição automática** (recomendada) escolhe pela regra; ou **escolha um técnico** (a lista mostra o turno e quantas
  verificações cada um tem na fila, do menos ao mais ocupado; vale qualquer técnico aprovado). A designação manual **zera** o contador de trocas.
- **Distribuir todas pelo automático** (barra superior): passa a fila inteira pela regra, depois de um aviso de confirmação do sistema (com atalho para **Ajustar a regra**). As que esgotaram o limite continuam na fila para você designar.
- **Histórico:** abre a linha do tempo da verificação.
- **Regra:** atalho para a página *Redistribuição*.

Quem recebe uma pendência (por qualquer caminho) começa com as **observações em branco** e vê o **aviso** de que ela estava pendente.

---

## 10. Redistribuição

Liga e configura a **rotina automática** que passa as pendências da fila central a técnicos, de forma justa.
**Vem desligada**: nada muda até você ligar. As mudanças são **salvas automaticamente**; você não precisa clicar em nada para a rotina funcionar.

| Campo | O que faz | Padrão |
|---|---|---|
| **Liga/desliga** | Ativa a rotina automática | Desligada |
| **Esperar antes de distribuir (min)** | Tempo para um técnico assumir sozinho. Passou disso, o sistema distribui | 30 |
| **Como escolher o técnico** | *Quem tem menos trabalho* ou *um de cada vez (rodízio)* | Menos trabalho |
| **Turnos que recebem** | Quais turnos podem receber. Técnico "Geral" recebe em qualquer turno marcado | Todos |
| **Limite de verificações por técnico** | Quem já tem esse tanto na fila não recebe mais | 15 |
| **Quantas vezes pode mudar de técnico** | Cada entrega do sistema conta 1; ao chegar nesse número o sistema para de mexer e ela fica na fila central para você | 3 |

Botões opcionais:

- **Simular:** mostra o que seria feito agora, **sem alterar nada**. O resultado abre num segundo modal.
- **Distribuir agora, sem esperar:** adianta a rotina, ignorando o tempo de espera. Antes de executar, o sistema abre um aviso que mostra **quantas pendências** serão distribuídas, o que vai acontecer com elas (inclusive as que esgotaram o limite de trocas e ficam para você) e oferece **Simular primeiro**.

Os cartões do topo mostram a fila central, quantas foram distribuídas hoje e se a rotina está ativa (e quando conferiu a fila pela última vez).
A regra completa de justiça, o limite de trocas e as proteções estão em [Regras e fluxos](04-regras-e-fluxos.md#6-redistribuição-automática).

---

## 11. Rotinas do dia a dia (sugestão)

1. **De manhã:** abra *Analytics* e *Pendências*. Olhe **Precisam de você** e **Acima de 24h**.
2. **Ao criar trabalho novo:** *Atribuição por Planilha* para lotes; *Atribuição Única* para casos avulsos.
3. **Quando faltar alguém:** use *Designar* (ou *Distribuir todas pelo automático*) para a fila não parar.
4. **Toda semana:** conferir *Quem deixa / retoma* para entender onde o fluxo trava, e baixar um backup em *Sistema*.
5. **Quando entrar gente nova:** aprovar em *Solicitações de acesso* e conferir o **turno**.
