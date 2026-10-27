# Histórico de versões

## 1.8.10
- **Aviso das observações, menor e mais direto.** Na janela de atendimento, a caixa grande acima do campo ("Muito importante: escreva o que você fez e o que encontrou. Se ficar em branco, ninguém sabe o que foi feito, e você não consegue concluir.") foi trocada por **uma linha curta logo abaixo do campo**, na mesma linha do "Salva sozinho": em amarelo, "Escreva o que fez e o que encontrou para poder concluir." (ou "Já há texto de antes: acrescente o que você fez."); em verde, "Anotado." quando há texto novo. O exemplo dentro do campo também ficou mais curto ("O que o cliente relatou, o que você fez e como ficou."), e o campo ganha mais espaço. A regra não mudou: para concluir é preciso escrever algo novo (mínimo de 5 caracteres), e o selo **Obrigatório** continua no título. Documentação atualizada. Sem migration.

## 1.8.9
- **Aviso de pendência recebida, só informativo.** Quando o técnico recebe uma verificação que estava pendente (por assumir, por redistribuição ou por designação do ADM), o sistema agora **apenas informa** que ela estava pendente de contato; não reforça mais que "agora é sua responsabilidade". No card aparece o selo **RECEBIDA** com "Estava pendente de contato"; na janela de atendimento, "Esta verificação estava pendente de contato", com quem deixou, há quanto tempo e a nota dele (como antes). Documentação atualizada. Sem migration.

## 1.8.8
- **"Pendentes" x "Pendentes de contato" deixaram de se confundir.** Nos filtros e ordenações de **Verificação** (conjuntos e atribuições únicas, inclusive o detalhe do conjunto), no **Banco de Verificações** e no perfil do técnico, o que era "Pendentes" (= não concluídas) agora se chama **"Em aberto"**; no Banco, "Pendentes na Fila" virou **"Aguardando início (no prazo)"**, que é o que ele realmente filtra. "Pendente de contato" fica reservado para a fila central. A ordenação "Mais pendentes" virou "Mais em aberto".
- **Números do menu que não crescem para sempre.** O número ao lado de **Conjuntos** e de **Atribuições únicas** agora conta **só o que ainda tem verificação em aberto**: conjunto 100% concluído e atribuição única concluída saem da conta, e quando não resta nada o número some (o ponteiro mostra "N conjunto(s) com verificação em aberto"). No técnico, o número do **Histórico Concluído** foi removido (tudo ali já está concluído). As listas continuam mostrando os concluídos, com o filtro *Concluídas*.
- **Avisos do sistema no lugar do pop-up do navegador.** "Distribuir agora" (Redistribuição) ganhou um aviso próprio que mostra **quantas pendências** serão distribuídas, o que acontece com elas, quantas esgotaram o limite de trocas (ficam para o ADM) e oferece **Simular primeiro**. O mesmo aviso passou a valer para "Distribuir todas pelo automático" (com atalho *Ajustar a regra*) e para remover o plano de fundo, excluir/limpar backups, aplicar atualização e voltar versão. Esc cancela e clicar fora não fecha; nos avisos de exclusão o foco já começa em *Cancelar*.
- **Campos de data (prazo) abrem ao clicar.** Antes, só o ícone do calendário abria a seleção, e no tema escuro esse ícone ficava praticamente invisível. Agora o calendário usa o tema escuro, o ícone é sempre visível e **clicar em qualquer ponto do campo** abre a seleção (valendo para todos os campos de data do sistema).
- Documentação atualizada (glossário "em aberto" x "pendente de contato", números do menu, avisos e campos de data). Sem migration.

## 1.8.7
- **Documentação reescrita, de ponta a ponta.** O README virou uma porta de entrada curta (o que é, início rápido, índice) e a documentação foi organizada em `docs/`, um documento por assunto:
  1. **Visão geral:** o que o sistema faz, perfis, glossário, mapa de telas e o caminho de uma verificação (com diagrama).
  2. **Guia do técnico:** o dia de trabalho passo a passo (atender, observações obrigatórias, deixar pendente, assumir e receber pendências).
  3. **Guia do administrador:** cada tela do menu (gráficos, atribuições, procedimentos, pendências, redistribuição, usuários, sistema).
  4. **Regras e fluxos:** a fonte de verdade das regras de negócio (estados, permissões, observações, pendência de ponta a ponta, redistribuição justa, limite de trocas, auditoria e indicadores), com diagramas.
  5. **Arquitetura, banco e API:** camadas, tabelas e colunas, as 15 migrations, todas as rotas da API, rotinas, configuração e segurança.
  6. **Instalação, atualização e operação:** o conteúdo operacional do README antigo, preservado e corrigido.
- **Correções na documentação** (o texto antigo não batia com o sistema):
  - Dizia haver **bloqueio da conta após 5 erros de senha**. Não existe: o freio é o limite por IP (30 tentativas de login por 15 min; 10 pedidos de cadastro por hora).
  - Sugeria o `cron` a cada 10 min; a Redistribuição pede **a cada minuto**.
  - Dizia que a tela atualiza a cada 15 s; o sistema confere mudanças a cada **5 s** (a presença é que bate a cada 15 s).
  - Citava os modos "Automática" nas atribuições, que não existem na interface; listava só 3 migrations.
- **Correção na tela de cadastro:** removida a frase "Com técnico "Automático", o turno passa a ser o do técnico escolhido", que sobrou de uma versão anterior e aparecia abaixo do campo de turno.
- Sem migration e sem mudança de regra. A rota `admin/redistribution/suggest` e alguns ramos do front que sobraram do modo "Automático" continuam sem uso e estão listados na seção "Pontos de atenção" da documentação de arquitetura.

## 1.8.6
- **"Quem deixa / quem retoma" agora é uma das visões do painel de Pendências.** Saiu o botão da barra e o bloco que abria solto acima da tabela. No lugar, há um **6º cartão** ao lado de Pendentes, Acima de 24h, Prazo vencido, Já redistribuídas e Precisam de você: ao clicar, o painel de baixo troca para o ranking, e clicar em qualquer outro cartão volta à lista.
- **Feito para muitos técnicos:** o ranking é paginado (10, 20 ou 50 por página), com **busca por técnico**, **período** (7, 30, 90 dias ou 1 ano), **ordenação** (quem mais deixou, quem mais retomou, menor saldo, nome A–Z) e botão de atualizar. Cada linha mostra o turno, barras de "deixou" e "retomou" e o **saldo** (retomou menos deixou; negativo em laranja, positivo em verde). No topo, o total do período.
- Esclarecimento na própria tela: "retomou" é quando o técnico assume uma pendência da fila; quando o **sistema ou o ADM** designa, isso **não** conta como "retomou".
- Sem migration e sem mudança no servidor (usa a mesma rota de estatísticas).

## 1.8.5
- **Nota curta ao deixar pendente:** o campo agora aceita no máximo **200 caracteres**, com contador (0/200, fica amarelo perto do limite) e exemplo curto. O texto pede "em poucas palavras: o que já foi tentado e o que falta". O servidor também recusa nota acima de 200, com mensagem clara. Notas antigas, mais longas, continuam sendo exibidas normalmente.
- **Painel Pendências: o ADM designa a pendência.** Cada linha ganhou o botão **Designar**, que abre uma janela com a nota de quem deixou e duas formas de resolver:
  - **Redistribuição automática** (padrão, marcada como recomendada): usa a regra que já existe (turnos habilitados, menos trabalho, limite por técnico) só para essa pendência.
  - **Técnico à escolha:** lista todos os técnicos aprovados, com o turno e quantas verificações cada um tem na fila, do menos ocupado para o mais ocupado. Vale qualquer técnico, mesmo de turno fora da rotina. O botão diz exatamente o que vai acontecer ("Designar para Fulano").
- **Distribuir todas pelo automático:** novo botão na barra do painel; passa toda a fila pela regra da Redistribuição (as que esgotaram o limite de trocas ficam para o ADM designar). O resultado abre no modal de resultado.
- **Mesmo tratamento de quem recebe:** observações em branco, nota de quem deixou como aviso e o histórico anterior guardado na auditoria. A designação manual **zera** a contagem de trocas da verificação (resolve as que "precisavam de você"). Na auditoria aparece o evento **Designada pelo ADM** (com o ADM como autor) ou **Redistribuída** (quando o ADM usou o automático).
- Proteções: se alguém assumiu ou concluiu a pendência antes, o servidor avisa ("Atualize a tela") e não troca nada; técnico bloqueado ou inexistente é recusado; só ADM pode designar. A tela do ADM atualiza na hora.
- Rota nova `POST admin/pending/assign`. Sem migration.

## 1.8.4
- **Quem recebe uma pendência começa com as observações em branco.** Quando uma verificação que estava pendente de contato é **assumida** por outro técnico ou **redistribuída** pela rotina, o campo *Observações* de quem recebe vem **vazio**: nada do que estava escrito antes aparece nele. O texto anterior **não se perde**: fica na **auditoria** (evento *Pendência retomada* / *Redistribuída*, bloco “Observações anteriores”).
- **Deixar pendente não copia mais a nota para as observações.** A nota (o que já foi tentado e o que falta) fica só em *pendente* e vira o aviso de quem receber.
- **Aviso para quem recebeu:** no card (selo **RECEBIDA** + “Estava pendente · agora é sua responsabilidade”) e na janela de atendimento (“Esta verificação estava pendente de contato e agora é sua responsabilidade”, com quem deixou e há quanto tempo). Se houver nota, ela aparece **dentro do aviso**, nunca nas observações. O aviso agora aparece mesmo quando não há nota.
- **Exceção:** se a mesma pessoa retoma a **própria** pendência (ou a redistribuição só tem ela como opção), nada é limpo e não aparece “recebida”.
- Como as observações de quem recebe começam vazias, a regra de **só concluir depois de escrever a própria observação** passa a valer para essas verificações também.
- Sem migration.

## 1.8.3
- **Nota do técnico anterior chega junto com a verificação (passagem de bastão).** Antes, quando uma pendência ia para outro técnico (ele assumiu ou a Redistribuição enviou), a nota “o que já foi tentado e o que falta” ficava para trás. Agora:
  - A nota é guardada com a verificação (**migration 015**: `handoff_note`, `handoff_from`, `handoff_at`; aditiva, sem chave estrangeira) tanto quando o técnico **assume** quanto quando a **Redistribuição** envia.
  - Para quem recebeu, a verificação aparece **diferente**: selo **RECEBIDA** no card, caixa âmbar **“Recebida de [nome] · o que falta fazer”** no lugar de “Aguardando início” e, ao abrir, um destaque com o texto completo, quem deixou e há quanto tempo. Some quando a verificação é concluída; se for deixada pendente de novo, vale a nota nova.
- **Nova seção “Pendências” no menu do ADM** (ao lado de Redistribuição), no lugar do painel que ficava misturado na Verificação:
  - Cartões que também filtram: *Pendentes*, *Acima de 24h*, *Prazo vencido*, *Já redistribuídas* e *Precisam de você* (esgotaram o limite de trocas da Redistribuição). Mostra se a redistribuição automática está ligada.
  - Tabela com login/conjunto, quem deixou, há quanto tempo, prazo, número de trocas e **a nota do técnico**; busca, **paginação** (8, 15, 30 ou 50 por página) e botão **Histórico** (linha do tempo). Ranking *quem deixa / quem retoma* movido para cá. Selo no menu com a quantidade.
  - Saiu da Verificação o painel de pendentes e o cartão “Pend. de contato”; o filtro de situação continua nas tabelas.
- **Pendentes de contato do técnico agora tem paginação** (6, 12, 24 ou 48 por página; “ir para” quando há muitas páginas).
- **Barra de ações fixa no topo** em *Procedimentos*, *Redistribuição*, *Atribuição Única* e *Atribuição por Planilha*: os botões (Salvar/Atribuir/Importar, Limpar, Simular, Distribuir agora e o interruptor da Redistribuição) acompanham a rolagem, sem precisar descer até o fim. Em Procedimentos, o campo de novo procedimento fica na barra. Atribuição Única reorganizada em dois blocos (*Cliente* e *Quem atende e quando*).
- **Indicador “Tempo médio” do topo do ADM** estava fixo em `9m`; agora vem do servidor (período do gráfico). Os demais cartões informam o período que usam.
- Verificados com dados simulados todos os gráficos do ADM (tendência, procedimentos, prazo, tempo médio, turnos, desempenho, em aberto).

## 1.8.2
- **Desempenho com grande volume de dados e gráficos que não atualizavam.**
  - **Gráficos/indicadores muito mais rápidos:** as consultas filtravam por `DATE(finished_at)`, que impede o uso de índice e fazia o banco ler a tabela inteira a cada gráfico (5 consultas por abertura da tela). Agora filtram direto pela data (`finished_at >= ...`) e há índices novos para isso.
  - **Gráficos que “não atualizavam”:** o resultado ficava guardado por 45 s no navegador, então uma conclusão recente não aparecia. Agora o cache é descartado **na hora** em que chegam dados novos (de outro usuário ou seus) e o tempo máximo caiu para 20 s. Os gráficos passam a ser **atualizados no lugar** (sem recriar nem reanimar), o que também elimina o “pisca” a cada atualização.
  - **Menos carga no servidor:** o carimbo de sincronização (consultado a cada 5 s por cada pessoa com o sistema aberto) é calculado **uma vez a cada 3 s** e compartilhado entre todos, em vez de uma varredura por pessoa.
  - **Menos dados trafegados:** as respostas da API agora vão **compactadas (gzip)** quando o navegador aceita e o servidor ainda não comprime (em geral reduz ~10x a lista de verificações).
  - **Migration 014 (aditiva, só índices):** `ix_verif_done`, `ix_verif_tech_done`, `ix_verif_pend_queue`, `ix_verif_tech_redist`. Em bancos muito grandes pode levar alguns segundos para aplicar.
- **Card da fila do técnico:** o botão **Continuar Verificação** (verificação já iniciada) agora é **verde**, diferente do **Iniciar Verificação** (azul/índigo). Antes eram quase da mesma cor e confundiam.
- **Atribuição por Planilha, visual:** o envio da planilha virou uma **área grande de upload** (arraste o arquivo ou clique em *Escolher arquivo*; depois de carregar, mostra o nome do arquivo). A regra de divisão ganhou **dois cartões** (*Divisão igualitária* e *Definir cotas*) com ícone, explicação curta e marca de selecionado, no lugar dos botões pequenos.
- **Redistribuição, linguagem mais simples:** *Máximo de trocas por verificação* virou **Quantas vezes pode mudar de técnico**, com explicação clara; *Máximo de verificações por técnico* virou **Limite de verificações por técnico**.
- **Listas de Conjuntos e Atribuições únicas com 4 por página** (antes 8 e 15), para a tela não ficar enorme. Na paginação, agora há **Por página** (4, 8, 15, 30 ou 50) e, com mais de 5 páginas, **Ir para** a página desejada (digite o número e Enter), pensando em milhares de itens.

## 1.8.1
- **Redistribuição é onde a rotina automática vive (correção da 1.8.0).** A divisão automática estava no lugar errado. Agora:
  - **Rotina sozinha, a cada ciclo:** ligada, o sistema distribui a fila central (*Pendentes de contato*) de forma justa entre os **turnos habilitados**, depois do **tempo de espera** para o técnico assumir (padrão 30 min). Acabou a “janela de abertura do turno”: não há mais horários de abertura nem “continuar tentando por X minutos”, então nada fica parado esperando você executar.
  - **Você decide se fica ligada:** o interruptor *Redistribuição automática* liga e desliga.
  - **Sem Salvar:** o interruptor e os campos **salvam sozinhos** (o botão Salvar saiu). *Simular* e **Distribuir agora, sem esperar** (antes “Executar agora”; só serve para adiantar a distribuição, não é necessário para o sistema funcionar) ficam sempre disponíveis e salvam o que estiver na tela antes de rodar.
  - **Justa entre os turnos:** recebe primeiro o turno com menor carga média por técnico, depois quem tem menos trabalho; o desempate gira (ou rodízio, se escolhido). Respeita o limite de fila e o máximo de trocas.
- **Atribuição por Planilha e Atribuição Única voltaram ao que eram:** removidos o modo *Automática* (planilha) e a opção *Automático* (técnico, na única). A divisão volta a ser *Igualitária* ou *Cotas*.
- Configurações antigas continuam válidas (o modo “na abertura” passa a funcionar como contínuo). Sem migration. `php tests/redistribution.test.php` atualizado.

## 1.8.0
- **Menu lateral sem modais para navegar:** *Procedimentos*, *Redistribuição*, *Atribuição Única* e *Atribuição por Planilha* agora são **páginas** do menu lateral, no mesmo padrão das demais opções (item ativo, título na barra superior). Depois de atribuir, o sistema leva para a lista de Verificação. O 1º Esc, na página de Procedimentos, cancela a edição em andamento.
- **Modais:** o fundo **não rola** mais com um modal aberto (a rolagem fica só dentro da janela, sem “pulo” da barra de rolagem) e **clicar fora não fecha** nenhum modal; use o botão Fechar ou a tecla Esc.
- **Simulação da Redistribuição:** o resultado abre em um **segundo modal** (com a lista de quem receberia cada pendência e, se estiver ligada, o botão *Executar agora*). A execução real também mostra o resultado nesse modal.
- **Redistribuição, textos simples:** frases curtas e diretas; removida a expressão “não cobrar ninguém”. Nova explicação sobre novas demandas na própria página.
- **Divisão automática para novas demandas (nova rotina):** em *Atribuição por Planilha* há o modo **Automática** (agora o padrão) e em *Atribuição Única* a opção de técnico **Automático**. O servidor aplica a regra da Redistribuição sobre a **carga atual**: escolhe primeiro o turno com menor carga média por técnico e, dentro dele, quem tem menos trabalho; o desempate **gira**, então o mesmo técnico não é sempre o primeiro. Prévia da divisão (por turno e por técnico) antes de confirmar. Nova rota `POST admin/redistribution/suggest`. O mesmo critério passou a valer para as pendências redistribuídas.
- **Observações exclusivas do técnico:** o sistema não escreve mais nada automático em Observações (antes entravam “Atribuído por planilha...”, “Verificação adicional...”, “[Reatribuído para...]”, “[Assumido por...]”, “[Retomado por...]” e o cabeçalho “[Pendente de contato — ...]”). Isso fica **só na auditoria**: a criação registra a **origem** (planilha, cotas, automática, única) e a reatribuição registra a **nota do ADM**. Ao deixar pendente, só o texto escrito pelo técnico entra nas observações.
- Sem migration. Teste das regras puras atualizado: `php tests/redistribution.test.php`.
- Não alterado: o campo “Problema detectado / Observações iniciais” da Atribuição Única continua indo para as observações (é texto escrito pelo ADM, não automático).

## 1.7.1
- **Redistribuição não depende mais de o técnico estar online.** Antes, só recebia pendência quem tinha dado sinal nos últimos ~75 s; se ninguém estivesse com o sistema aberto, tudo ficava na fila central. Agora a escolha usa apenas:
  - **Turno elegível:** o turno do técnico precisa estar habilitado em *Redistribuição* (técnico “Geral” vale para qualquer turno habilitado) e, no modo *na abertura do turno*, dentro da janela de abertura;
  - **Parâmetros:** técnico aprovado e não bloqueado, abaixo do **limite de fila**, respeitando o máximo de trocas por verificação;
  - **Carga de trabalho:** *quem tem menos trabalho* (empate: quem recebeu menos hoje) ou *rodízio*.
- O painel mostra, em cada turno, quantos técnicos estão **liberados** (aprovados e não bloqueados) em vez de “online de total”; textos do modal e do README atualizados. A presença online continua existindo na Equipe, só não influencia mais a distribuição.
- Sem migration.
## 1.7.0
- **Redistribuição automática de pendências entre turnos (novo, desligado por padrão).** Em **Ações rápidas → Redistribuição**, o ADM liga o recurso e escolhe:
  - **Quando distribuir:** *na abertura do turno* (continua tentando por uma janela configurável, para quem entrar depois) ou *aos poucos, durante o dia* (após uma espera mínima, que respeita um “tentar de novo depois das 7h”).
  - **Como escolher o técnico:** *quem tem menos trabalho* (empate: quem recebeu menos hoje) ou *rodízio* (ordem fixa, lembra onde parou).
  - **Turnos que recebem** e o **horário de abertura de cada um** (padrão: Manhã 06:00, Tarde 12:00, Noite 18:00, Madrugada 00:00; o ADM pode ajustar). Técnico “Geral” recebe em qualquer turno ligado.
  - **Limites:** máximo de verificações na fila de cada técnico e máximo de trocas por verificação.
- **Como funciona:** a fila central é a própria área *Pendentes de contato*. O sistema troca o dono **da mesma verificação** (como o “Assumir” manual): histórico, prazo, observações e classe ficam intactos e não há duplicidade (o índice único por PPPoE continua valendo). Só recebe quem está **online**, aprovado e abaixo do teto; ninguém recebe de volta o que acabou de deixar (se houver outro técnico). Sem elegível, a verificação **continua na fila central**. Cada troca é um `UPDATE` condicional (pendente, não concluída, mesma `version`): se um técnico assumir à mão no mesmo instante, a redistribuição é ignorada. Um lock no banco impede duas execuções ao mesmo tempo.
- **Segurança:** botão **Simular** (mostra quem receberia o quê, sem gravar) e **Executar agora** (com confirmação). Rotas só para ADM, com CSRF e validação (horários HH:MM, limites).
- **Sem cobrança individual:** o evento `REDISTRIBUIDA` aparece na linha do tempo como ação do *Sistema* e só informa o destino. Quem deixou a pendência fica apenas na auditoria do ADM. O painel mostra números da equipe (fila central, distribuídas hoje, última execução).
- **Execução automática** a cada ~1 min, de carona no uso normal do sistema (`/stamp`, depois de responder) e também pelo `cron`. Se usar cron em vez do automático, agende a cada minuto.
- **Migration 013** (aditiva): `verifications.redist_count` e `redist_at`. A configuração fica em `app_state` (chave `redist`).
- Teste das regras puras, sem banco: `php tests/redistribution.test.php`.
- Não alterado: o ranking “quem deixa / quem retoma” do painel de pendentes continua como era.

## 1.6.7
- **Gerenciar usuários com busca:** novo campo para achar o técnico por **nome, e-mail ou turno** (ignora acentos e maiúsculas), mais botões **Todos / Técnicos / ADM** com o total de cada um e o aviso *Mostrando X de Y*. Se nada for encontrado, aparece uma mensagem clara. A busca continua preenchida quando a lista se atualiza sozinha.
- **Modal de Procedimentos com linguagem mais simples:** textos curtos e diretos (ex.: *“São as opções que o técnico escolhe ao atender”*), exemplo mais claro no campo, contador de opções e botões *Mudar o nome* / *Apagar*. Ao editar, aparece um aviso amarelo com o nome antigo e o botão **Cancelar**; **Enter** salva. Não deixa criar dois procedimentos com o mesmo nome. Os nomes agora são exibidos de forma segura (sem interpretar HTML).
- **Confirmação ao apagar procedimento:** o ADM vê uma janela *“Apagar este procedimento?”* com o nome e, se houver, **quantas verificações usam** esse procedimento (elas ficam sem procedimento no histórico). O foco já vem no botão **Não, manter**, então um Enter sem querer não apaga.
- **Tecla Esc para voltar em qualquer janela:** o Esc fecha a janela que está por cima, uma de cada vez. No atendimento, o que foi escrito é guardado antes de fechar; na edição de procedimentos, o 1º Esc cancela a edição e o 2º fecha a janela. Sem janela aberta, o Esc continua fechando o menu no celular.
- **Observações obrigatórias no atendimento:** o campo agora se chama *Observações: o que você fez?* com a etiqueta **Obrigatório** e um aviso em destaque (amarelo) explicando que, em branco, ninguém sabe o que foi feito. Quando já existe texto de antes (de outro técnico ou de uma pendência), o aviso diz que **aquele texto não conta** e pede que o técnico escreva embaixo a parte dele. O aviso fica verde quando há texto novo. O botão **Concluir verificação** só conclui se o técnico escreveu algo novo (mínimo de 5 caracteres); caso contrário o campo fica destacado em vermelho e recebe o cursor.
- Sem migration.

## 1.6.6
- **Modal de verificação pendente / atendimento sem textos sobrepostos:** a faixa de avisos no topo dos modais tinha altura fixa de uma linha. O aviso de **Pendente de contato** (título, quem deixou e a nota) é maior que isso e invadia o conteúdo abaixo, deixando tudo embolado e ilegível. Agora a faixa **cresce conforme o aviso** (e mantém o mínimo de antes quando não há nada, então o layout não pula). Vale para o *Raio-X* (verificação pendente, de outro técnico ou concluída) e para a tela de atendimento.
- **Atualização do navegador:** o `api-adapter.js` era carregado com um carimbo de versão antigo (`?v=1.5.21`), então o navegador podia continuar usando uma cópia em cache, sem as melhorias de sincronização da 1.6.5. O carimbo foi atualizado.
- Sem migration.

## 1.6.5
- **Status do técnico corrigido:** **Online** agora significa *sem verificações pendentes (tudo em dia)*; **Ocioso** só aparece quando o técnico tem fila a fazer e não iniciou nada; **Em atendimento** quando há uma verificação em andamento. Correções: (1) o **perfil** do técnico sempre mostrava "ocioso" (valor fixo no código) e agora usa a situação real; (2) o servidor contava como pendente também o que está em *Pendente de contato*, que sai da fila do técnico, então a equipe aparecia "ociosa" mesmo com tudo em dia: agora conta só a fila real, igual à tela do técnico; (3) a barra superior do técnico mostrava "Fila zerada" e agora mostra **Online · Tudo em dia**.
- **Sincronização sem precisar de F5:** a atualização automática passou de 15 s para **5 s** (a consulta é só um carimbo de poucos bytes; os dados só são baixados quando algo mudou). Antes ela era **cancelada** sempre que havia qualquer campo focado (inclusive a busca da fila), o que deixava a tela parada; agora campos de busca, filtro e data não bloqueiam, e só formulários em edição e janelas abertas adiam a atualização (que acontece no ciclo seguinte). Também atualiza ao **voltar para a aba**, ao **recuperar a internet** (reenvia o que ficou pendente) e detecta mudanças em usuários (nome, turno, aprovação), não só em verificações. O status da equipe (online / ocioso / em atendimento) passou a atualizar a cada 15 s e **na hora** após qualquer ação sua ou mudança recebida.
- **Perfil e Sair juntos no cabeçalho:** o botão **Sair** agora fica ao lado do seu perfil, no topo. O rodapé do menu lateral (que repetia *Meu perfil e configurações* e *Sair*) foi removido, evitando a duplicidade.
- Sem migration.

## 1.6.4
- **Submenus independentes:** cada menu com submenu (*Verificação* e *Equipe & Aprovações*) abre e fecha **por conta própria**. Abrir um não fecha o outro, e ir para outra seção também não fecha nenhum: só fecham quando você clica neles. O clique no item pai leva à seção (e mantém o submenu aberto) quando ela ainda não está aberta; se já está, alterna o submenu. A **setinha** abre/fecha só o submenu, sem trocar de tela (alvo de toque maior). O estado (aberto/fechado) é **lembrado** ao recarregar a página.
- **Animações mais suaves:** os submenus abrem e fecham deslizando em altura (com fade, e os itens entram em sequência); a setinha gira; os itens do menu ganham transições no hover/clique e uma **barrinha animada** no item ativo; o fundo escuro da gaveta (celular) aparece e some em fade; e a seção que abre entra com um leve fade. Menus fechados ficam fora do alcance do teclado (não recebem foco). Para quem usa o aparelho com "reduzir movimento", as animações são praticamente desligadas.
- Sem migration.

## 1.6.3
- **Nomes mais diretos para o técnico:** *Fila de Verificação* agora é **Minha Verificação** e *Fila de Outros Técnicos* agora é **Verificações dos outros técnicos** (menu lateral, título da barra superior e título da tela). O botão de assumir pendência virou **Assumir para mim**. Os avisos e textos de apoio acompanham os novos nomes (ex.: "já está em Minha Verificação", "já está com Fulano, aguardando início; assuma pela aba Verificações dos outros técnicos"; "Nenhum outro técnico tem verificação em aberto no momento").
- Os nomes do menu lateral agora podem ocupar até duas linhas, para os títulos completos caberem sem serem cortados.
- Sem migration.

## 1.6.2
- **Submenus agora recolhem e expandem ao clicar no item pai:** em *Verificação* e *Equipe & Aprovações*, o primeiro clique leva para a seção e abre o submenu; clicar de novo no mesmo item **recolhe** o submenu (a tela continua onde estava) e outro clique expande. O chevron (seta) gira conforme aberto/fechado, e o estado é anunciado para leitores de tela (`aria-expanded`). Se você sair da seção e voltar, o submenu reabre sozinho. Funciona também com a barra recolhida (alterna os ícones sob o item).
- Sem migration.

## 1.6.1
- **Submenus na barra lateral do ADM:** o item ativo se abre mostrando suas seções internas, com contador em cada uma.
  - **Verificação:** *Conjuntos* e *Atribuições únicas*.
  - **Equipe & Aprovações:** *Equipe* (online/total), *Aprovações* (selo âmbar quando há solicitações esperando) e *Gerenciar usuários*.
  Clicar numa subseção já abre a tela naquela seção; clicar no item pai volta para a última subseção usada. A barra superior mostra o caminho (ex.: *Verificação · Atribuições únicas*). Com a barra recolhida, os submenus viram ícones logo abaixo do item pai; no celular aparecem dentro da gaveta e ela fecha ao escolher.
- Os cartões de seleção que ficavam dentro dessas páginas (Conjuntos / Atribuições únicas e Equipe / Aprovações / Gerenciar) foram escondidos, pois o menu lateral agora faz esse papel; o conteúdo e os filtros de cada seção continuam iguais.
- A barra lateral ficou 12 px mais larga (272 px) para "Equipe & Aprovações" caber ao lado do selo. Sem migration.

## 1.6.0
- **Novo visual com barra lateral (ADM e técnico), baseado no protótipo enviado:** o menu saiu do topo e agora fica numa **barra lateral escura com ícones**, que **recolhe para uma coluna só de ícones** (botão no topo da barra; a escolha é lembrada neste aparelho). Cada item tem ícone em quadradinho, destaque azul no item ativo, contadores (fila, pendentes, em aberto, online) e dica ao passar o mouse quando recolhida (os contadores viram um selinho sobre o ícone).
  - **ADM:** Analytics & Tendências · Verificação · Banco de Verificações · Equipe & Aprovações (com o contador de solicitações) · Sistema; e o bloco **Ações rápidas** com Procedimentos, Atribuição Única e Atribuir por Planilha (antes ficavam soltos na barra de cima).
  - **Técnico:** Fila de Verificação · Pendentes de contato · Fila de Outros Técnicos · Histórico Concluído · Equipe · Meu Dashboard.
  - **Rodapé da barra:** Meu perfil e configurações e Sair.
- **Barra superior enxuta:** mostra o **nome da seção atual**, o status/presença do técnico, o filtro de **Prazo / Data** (técnico) e o usuário logado. No celular o filtro e a presença descem para uma segunda linha, sem espremer o título.
- **Celular e tablet:** a barra vira uma **gaveta** aberta pelo botão de menu, fecha ao escolher uma seção, ao tocar fora ou com Esc. Em telas grandes fica fixa ao lado do conteúdo, que ocupa o espaço que sobrar.
- Tudo o que já existia continua igual por dentro (mesmos menus, telas, atalhos e dados); só a navegação mudou de lugar. Sem migration. A tela de login não muda.

## 1.5.30
- **Assumir uma pendência não inicia mais a verificação:** o técnico assume e ela vai para a **sua fila** como *Aguardando início*; a tela é levada até o card (destacado por instantes) e ele começa com **Iniciar Verificação** quando estiver pronto. Antes, assumir já iniciava o atendimento e abria a tela de atendimento. A janela de confirmação e os botões foram reescritos para isso (*Assumir para minha fila*).
- **Tempo de atendimento correto:** ao assumir, o início do atendimento anterior é zerado; o relógio só começa quando o novo técnico clicar em Iniciar (o tempo de espera nunca vira tempo de atendimento nem distorce o *Tempo médio*). A observação do colega continua nas observações da verificação e na auditoria.
- Sem migration.

## 1.5.29
- **Período (turno) sempre visível no card do técnico:** o período ficava no fim da linha de selos (prioridade, classe, execução, atrasada...), que não quebra linha e rola para o lado, então com vários selos ele ficava escondido. Agora **Prazo** e **Período** (com o nome por extenso: Manhã, Tarde, Noite, Madrugada) têm uma linha própria, sempre à mostra, logo abaixo do cabeçalho do card; a linha de selos ficou só com prioridade, classe e situação. O card ficou 2 rem mais alto para nada ser cortado.
- Sem migration.

## 1.5.28
- **Reabrir uma verificação que já está com outra pessoa agora diz com quem está:** antes o aviso só falava que o PPPoE já tinha uma verificação em aberto. Agora informa **quem** está com ela e **em que situação**, e o que fazer: *sendo atendida agora por Fulano* (aguarde ou combine com ele), *na fila de Fulano* (assuma pela aba "Fila de Outros Técnicos"), *em Pendentes de contato, deixada por Fulano* (assuma pela aba Pendentes de contato), ou *na sua fila / em atendimento por você*. A mensagem do servidor para o caso de duas pessoas criarem ao mesmo tempo também passa a dizer com quem ficou. Avisos longos ficam mais tempo na tela (até 10 s).
- Sem migration.

## 1.5.27
- **Equipe & Aprovações em sub-seções:** no topo da aba há três cartões, no mesmo modelo da aba Verificação, e cada um carrega só o seu conteúdo (chega de rolar a página até o fim): **Equipe** (presença, último acesso e perfis, com o contador online/total), **Aprovações** (solicitações de acesso; o cartão fica em destaque âmbar com a quantidade quando há pedidos esperando) e **Gerenciar usuários** (cadastrar, editar, turnos e senhas). Na primeira vez na sessão, se houver solicitações pendentes, a aba já abre em *Aprovações*. Os contadores se atualizam ao aprovar, cadastrar ou mudar a presença. A consulta de presença só roda com a sub-seção *Equipe* aberta.
- Sem migration.

## 1.5.26
- **Plano de fundo da tela de login (ADM):** na aba **Sistema**, novo card para escolher uma **imagem** (JPG, PNG, WebP) ou um **vídeo** (MP4 ou WebM, até 40 MB) que aparece atrás do login, com prévia, informações do arquivo e o botão **Remover e voltar ao padrão** quando quiser. Imagens são reduzidas automaticamente (até 1920 px) antes de enviar. O vídeo toca sem som e em repetição; para quem prefere menos movimento no aparelho ele aparece parado. O login ganhou uma película escura sobre o fundo para o texto continuar legível. Quem já está logado não baixa o fundo.
- **Envio seguro e sem depender dos limites do servidor:** o arquivo vai em pedaços de 1 MB (com nova tentativa se a rede falhar e barra de progresso), então não esbarra em `upload_max_filesize`/`post_max_size` do PHP nem no limite do Nginx. O formato é conferido pelo **conteúdo** do arquivo (um arquivo qualquer renomeado para .mp4 é recusado). O vídeo é entregue com suporte a trechos (Range), necessário para tocar no Safari/iPhone. Só o ADM envia, troca ou remove; a tela de login só lê. O arquivo fica em `storage/branding/` (fora da pasta pública) e **sobrevive a atualizações** do sistema. Sobras de envios abandonados são limpas pela manutenção automática. Auditoria registra *LOGIN_BG_SET* e *LOGIN_BG_REMOVE*. Sem migration.
- **Olhinho para ver/ocultar a senha** no login, no pedido de acesso e na alteração de senha do perfil. A senha volta a ficar oculta ao trocar de tela.

## 1.5.25
- **ADM vê os perfis dos técnicos (Equipe & Aprovações):** novo bloco **Equipe: presença e perfis** com todos os técnicos: online / em atendimento / ocioso / offline, **visto por último** (data e hora), **último login**, e-mail, turno, fila, concluídas hoje e em atendimento. Busca por nome ou e-mail e filtro *Só online*; atualiza sozinho a cada 30 s enquanto a aba está aberta. Clicar abre o **perfil completo** (indicadores, gráfico, verificações). Para o ADM o perfil ganha a *Visão do administrador*: último login, em aberto, atendendo, atrasadas, pendentes de contato (agora) e quantas pendências **deixou e retomou nos últimos 30 dias**. A lista de usuários ganhou o botão **Ver perfil**. E-mail e último login continuam visíveis **só para o ADM**.
- **Gráficos do ADM refeitos, com métricas que fazem sentido:** a série "Pendentes / Abertas por prazo" saiu (uma verificação não deve ficar pendente muito tempo, então essa linha distorcia a análise). Agora: **Indicadores do período** (concluídas, % resolvidas direto, % entregues no prazo, tempo médio, em aberto agora) · **Produção por período e resultado** (resolvida direto / O.S. / outro desfecho) · **Procedimentos** · **Cumprimento do prazo** (% no prazo por período) · **Tempo médio de atendimento** · **Em aberto por tempo de atraso** (foto de agora: a vencer, vence hoje, atraso de 1 dia, 2–3, 4–7, +7) · **Carga por turno** (atribuídas x concluídas) · **Desempenho por técnico** (barras por resultado + tabela com % resolução, % no prazo e tempo médio).
- **"Ano" agora é real:** o navegador só carrega ~120 dias, então semana/mês/ano calculados na tela ficavam incompletos. Os gráficos (ADM e técnico) passam a usar números agregados **no servidor** (`/api/analytics`), cobrindo 7 dias, 30 dias ou 12 meses completos. O painel do técnico ganhou *Entregues no prazo* e *Tempo médio* e o gráfico de produção por resultado.
- Sem migration.

## 1.5.24
- **Pendentes de contato (continuidade entre turnos/técnicos):** quando o técnico não consegue concluir (ex.: turno da noite/madrugada, cliente não atendeu), na tela de atendimento há o novo botão **Deixar pendente de contato**, com observação obrigatória (o que já foi tentado e o que falta). A verificação **continua a mesma** (histórico, prazo e atraso preservados), sai da fila dele e vai para a nova aba **Pendentes de contato**, visível a todos os técnicos.
- **Assumir e continuar:** qualquer técnico (inclusive quem deixou) assume com um clique: vira o dono, entra em atendimento e abre a tela de atendimento. A observação do colega fica no histórico e nas observações. O tempo de espera **não conta** como tempo de atendimento. Se dois tentarem ao mesmo tempo, só um assume (o outro vê o aviso de conflito).
- **Sem virar depósito:** a lista mostra primeiro quem espera há mais tempo, com o tempo de espera em cada cartão e **alerta vermelho a partir de 24 h**. Pendente continua contando como atrasada se passar do prazo.
- **Visão do ADM:** painel na aba *Verificação* com o total de pendentes, a mais antiga e quantas passam de 24 h, botão *Ver só pendentes* e o ranking **quem deixa / quem retoma** (últimos 30 dias). Também há cartão de indicador, selo *PENDENTE DE CONTATO* e filtro "Pendentes de contato" no Banco e nas listas. Se o ADM reatribuir uma pendente, ela ganha dono e sai do pool. A auditoria registra *Deixada pendente de contato* (com a observação) e *Pendência retomada*.
- **Ajustes visuais (técnico):** o botão *Pendente de contato* passou para o grupo da esquerda do rodapé da tela de atendimento (junto de Auditoria e Soluções), que quebra linha quando falta espaço, e as abas do técnico também quebram em telas médias: nada se sobrepõe. O botão *Iniciar atendimento* agora é **Iniciar verificação** (no card e na tela de atendimento).
- **Copiar o PPPoE com um toque, sem abrir o card:** ícone de copiar ao lado do login nos cards da fila, na barra de atendimento, nas abas Fila Geral, Histórico e Pendentes, no cabeçalho da tela de atendimento e do Raio-X, nas Soluções da equipe e nas listas do ADM. Clicar no ícone só copia (não abre a verificação) e mostra "PPPoE copiado". Funciona também quando o sistema é acessado por `http://IP` (o navegador bloqueia a cópia moderna nesse caso, então há um método alternativo).
- **Confirmação ao assumir uma pendência:** antes de assumir, o técnico vê uma janela com quem deixou, há quanto tempo e a observação, e confirma com *Assumir e começar* (ou cancela). Se a pendência já tiver sido assumida por outro, ele é avisado e a lista atualiza.
- **Soluções da equipe legível:** ao abrir sem digitar nada (lista das mais recentes), o destaque do termo buscado inseria uma marcação vazia entre cada letra, espaçando o texto e quebrando acentos, aspas e apóstrofos (apareciam códigos soltos). O destaque agora só age com termo de 2+ letras e trabalha no texto original, então as observações aparecem normais, com ou sem busca.
- Migration `012_pending_contact.sql` (aditiva): colunas `pending_contact`, `pending_since`, `pending_note` em `verifications`.

## 1.5.23
- **Banco de Verificações por conjunto:** novo filtro **Conjunto** (ao lado da busca), com todos os conjuntos lançados do mais recente para o mais antigo e a quantidade de logins de cada um. Também há as opções *Só conjuntos de planilha* e *Só atribuições únicas*. Ao escolher um conjunto aparece um resumo (logins, concluídas, em execução, não iniciadas, atrasadas).
- **Duas visões, no mesmo banco:** **Lista** (o modelo de sempre, agora com o nome do conjunto embaixo de cada PPPoE; clicar nele filtra) e **Por conjunto** (os PPPoE agrupados sob o cabeçalho de cada conjunto, com andamento e o botão *Só este conjunto*). Todos os outros filtros, a busca e a paginação continuam valendo nas duas.
- **Exportar CSV:** o botão baixa **tudo o que está filtrado** (não só a página), na ordem da tela, com conjunto, PPPoE, técnico, prazo, turno, prioridade, classe, origem (planilha ou atribuição única), situação, atraso, resultado, procedimento, criada/concluída em e observação. Protegido contra fórmula do Excel.
- **"Lote" agora é "Conjunto"** nos textos da tela (aba Verificação, detalhes, exclusão, nome ao atribuir por planilha, atribuição única, auditoria, raio-X), nas mensagens de erro do servidor e no CSV do servidor. Nada muda nos dados já salvos. Sem migration.

## 1.5.22
- **Verificação nova sempre no topo (ADM):** a aba *Verificação* ordenava "Mais recentes" pelo **prazo** mais distante do lote, então uma verificação criada agora com prazo próximo caía no fim da fila. Agora a ordem é pela **data de criação**: o que acabou de ser lançado aparece primeiro, mesmo com prazo anterior ao das demais. O prazo só desempata.
- **Duas seções na aba Verificação:** no topo há dois cartões com contagem. **Verificações** mantém o visual de sempre (lotes lançados por planilha). **Atribuições únicas** é exclusiva para o que é lançado em *Atribuição Única* (um login por vez), em lista própria, da mais recente para a mais antiga, com a mesma barra de filtros, indicadores, auditoria, reatribuição e exclusão. As duas deixam de se misturar.
- **Gráficos com período (ADM e técnico):** novo seletor **Semana** (7 dias, por dia), **Mês** (30 dias, por dia) e **Ano** (12 meses, por mês). Vale para todos os gráficos; no técnico, também para os indicadores (efetividade, concluídas, média/dia e O.S.). Concluídas passam a contar no dia em que foram **concluídas** (antes, no prazo). Datas do eixo usam o fuso local (antes o dia podia andar à noite) e os rótulos de procedimento só levam "…" quando são cortados.
- **Sem bloqueio de conta por tentativas falhas:** errar a senha não trava mais o usuário (antes: 5 erros = 15 min bloqueado). Contas que estavam bloqueadas são liberadas na hora. A falha continua registrada na auditoria. Fica apenas o limite por IP (30 tentativas em 15 min) contra robôs.
- Migration `011_single_assignment.sql` (aditiva): coluna `verifications.is_single`. O que já estava no grupo padrão **Avulso** passa a contar como atribuição única; as demais continuam em *Verificações*. Reabrir uma verificação mantém a seção da original.

## 1.5.21
- **Verificações da data:** o cartão que mostrava "Meta da Data Selecionada" agora mostra **"X de Y verificações"** (feitas do total da data) e, abaixo da barra, **quantas faltam** (ex.: "Faltam 7 verificações (30% feito)"). O total continua contando o que vence na data mais o que foi concluído nela.
- **Novos procedimentos:** Troca de equipamento, Ajuste de DBM, Necessário visita técnica ao local, Feito procedimento no equipamento, Outros e Sem sucesso no contato. Migration `010_actions_v2.sql` aposenta os antigos e migra as verificações já registradas (Reinício de ONU e Troca de canal Wi-Fi/DNS → Feito procedimento no equipamento; Ajuste de sinal → Ajuste de DBM; Agendamento de visita → Necessário visita técnica; Sem sucesso → Sem sucesso no contato; Redefinição de senha → Outros). Procedimentos criados pelo ADM não são alterados e a auditoria antiga continua legível.
- **Checklist:** o botão **Criar O.S.** passou a **O.S. Aberta** (mesmo rótulo na linha do tempo da auditoria).
- **Cabeçalho:** removido o selo TELECOM e o subtítulo agora é "Gestão e Distribuição de Rotinas de verificação".
- **Mais recente primeiro:** a fila do técnico (com *Em atendimento* sempre no topo), a lista de verificações do lote e o Banco de Verificações passam a ordenar da mais recente para a mais antiga. Uma verificação nova aparece no início, não no fim. Prioridade e atraso continuam marcados nos cartões, mas não reordenam mais a fila.
- **Botões do modal de atendimento:** **Concluir verificação** (e **Iniciar atendimento**) ficaram maiores, com destaque de cor, ícone e efeito ao tocar; Auditoria, Soluções da equipe e Continuar depois viraram botões secundários mais discretos. No celular o botão principal ocupa a largura toda, no topo do rodapé. Os botões do checklist ficaram mais altos e mostram um marcador (marcado / desmarcado).
- **Modais com layout fixo:** atendimento, raio-X, auditoria e soluções da equipe agora usam as mesmas medidas reservadas: título numa linha só (2xl), linha de selos com altura fixa e sem quebra (o que não couber rola de lado), faixa de aviso (REABERTA / ADICIONAL) de altura fixa e rodapé com altura mínima igual. Um selo ou legenda a mais não muda mais o tamanho nem o alinhamento de nenhum modal. A auditoria ganhou a mesma faixa de aviso, vazia quando não há nada a avisar.
- **Cartões da fila com tamanho fixo:** todos os cartões têm a mesma altura, com faixas pré-definidas: cabeçalho, linha de selos (uma linha só; o que não couber rola de lado), faixa de aviso (REABERTA / ADICIONAL), faixa de status (a mesma altura em *Aguardando início* e *Em atendimento*) e botão de ação. Um cartão REABERTA, ADICIONAL ou atrasado não fica mais maior que os demais.
- **Estabilidade (evita travamentos):**
  - Toda chamada ao servidor tem **tempo limite** (20 s para leituras, 45 s para salvar). Antes, uma resposta que não voltava deixava o salvamento preso para sempre; agora é tratada como falta de conexão e reenviada sozinha.
  - **Reenvio com espera crescente** (5, 10, 20, 40, 60 s) e o aviso "Sem conexão" aparece uma vez por queda, não a cada 5 s.
  - **Sem sobreposição:** a atualização automática (15 s) e o pulso da equipe (30 s) não disparam uma nova consulta enquanto a anterior ainda não terminou.
  - **Atualização automática bem mais leve:** a cada 15 s o painel consulta só um carimbo de poucos bytes (nova rota `stamp`) e baixa os dados completos apenas quando algo mudou. Com vários técnicos conectados, o servidor deixa de montar e enviar todas as verificações a cada ciclo.
  - Bloqueios no banco desistem em 10 s (antes 50 s), antes de o navegador abortar a requisição. Sem migration.
  - Cronômetros não rodam com a aba escondida (atualizam na hora ao voltar).
  - **Digitação mais leve:** as buscas e a pré-visualização do lote esperam uma pausa na digitação em vez de redesenhar a cada tecla.
  - **Criação de lote grande:** deixou de varrer todas as verificações a cada PPPoE (de O(n×m) para O(n+m)) e insere tudo de uma vez. Leitura de planilha com muitas linhas não estoura mais a pilha do navegador.
- **Perfis (aba Equipe):** clicar em qualquer parte do cartão do técnico abre o perfil; no perfil, clicar fora da janela fecha.

## 1.5.20
- **Perfil do técnico mais enxuto:** o perfil agora tem duas abas, **Visão geral** (bio e indicadores) e **Verificações**. A aba traz um contador de concluídas e de pendentes, mesmo antes de abrir.
- **Lista compacta:** cada verificação é uma linha única (situação, PPPoE, resultado, hora). Ao clicar, a linha se abre com lote, procedimento, duração, observação completa e os botões **Abrir auditoria** e **Outras deste PPPoE**. Só uma fica aberta por vez.
- **Agrupada:** as concluídas por dia (Hoje, Ontem, data e dia da semana); as pendentes por situação (Em atendimento, Atrasadas, Para hoje, Próximas).
- **Paginação:** 20 por página, com números de página e "1–20 de 241". Não carrega tudo de uma vez, então continua leve com centenas de PPPoEs.
- **Filtros rápidos com contagem:** concluídas (Resolvidas, Não resolvidas, Com O.S., Não atendeu) e pendentes (Em atendimento, Atrasadas, Aguardando). A busca por PPPoE/observação continua, e o período fica atrás do botão de calendário.
- API: `profile/verifications` aceita `limit` (10–50) e `result`, e devolve contadores por situação. A contagem de *atrasadas* agora exclui as que estão em atendimento. Sem migration.

## 1.5.19
- **Concluídas e pendentes no perfil do técnico:** o perfil (aba Equipe → *Ver perfil*, ou *Ver meu perfil*) ganhou a seção **Verificações do técnico**, com duas abas:
  - **Concluídas:** todas as verificações que o técnico finalizou, da mais recente para a mais antiga, com resultado (Resolvido / O.S. / Não atendeu), procedimento, lote, data e duração do atendimento e a observação completa.
  - **Pendentes:** o que ainda está com ele, com *Em atendimento* primeiro, depois por prazo, e destaque para as **atrasadas**.
- **Auditoria a um clique:** cada linha tem o botão **Auditoria**, que abre a linha do tempo completa daquela verificação (quem fez o quê e quando). Vale para o perfil de qualquer colega, com a mesma permissão que a auditoria já tinha.
- **Busca e filtros:** por PPPoE ou texto da observação e por período (data de conclusão nas concluídas, prazo nas pendentes). Lista paginada de 30 em 30 (*Carregar mais*). Diferente do histórico do técnico, aqui **não há deduplicação por PPPoE**: toda conclusão aparece (pensado para auditar).
- Nova rota `profile/verifications` (somente leitura, sem migration). Teste de integração acrescentado em `tests/api.test.js`.

## 1.5.18
- **Concluídas dos colegas:** o técnico pode navegar pelas verificações concluídas de outros técnicos sem precisar pesquisar: a janela *Soluções da equipe* abre listando as mais recentes, com filtro por técnico (um específico, todos os outros ou todos) e por observação. Pesquisar por PPPoE ou palavra continua funcionando. Também abre pelo botão **Ver concluídas dos colegas** na aba Equipe.

## 1.5.17
- **Excluir de verdade:** ao excluir uma verificação (ou um lote inteiro) agora também são apagados a linha do tempo e os registros de auditoria dela. Antes a verificação sumia, mas o histórico ficava no banco por 1 ano e ainda aparecia na auditoria do PPPoE. Fica apenas uma linha *TASK_DELETE* (quem excluiu, quando, qual PPPoE e lote) como registro administrativo.
- Migration `009_purge_orphan_history.sql` limpa o histórico órfão que já sobrou de exclusões antigas.

## 1.5.16
- **Histórico sem PPPoE repetido:** cada PPPoE aparece uma vez só no histórico do técnico (a conclusão mais recente). As anteriores ficam na auditoria. Um PPPoE que você reabriu e está de volta na fila deixa de aparecer no histórico. Os indicadores e gráficos continuam contando cada verificação.
- **Soluções da equipe:** nova janela para comparar como os técnicos resolveram os casos. Pesquisa por login PPPoE ou por palavra nas observações, em todas as verificações concluídas, mostrando técnico, data, resultado, procedimento e a observação completa (com o termo destacado). Filtros: "só de outros técnicos" e "só com observação". Abre pelo botão **Soluções da equipe** no histórico ou dentro da verificação (já pesquisando aquele PPPoE).
- **Auditoria liberada entre técnicos:** qualquer técnico pode abrir a auditoria de verificações concluídas por colegas (antes dava "sem permissão"), e as observações aparecem completas.

## 1.5.15
- **Reabrir = nova verificação (classe REABERTA):** ao reabrir uma verificação concluída (técnico no próprio histórico, ou ADM), o sistema cria uma **nova** verificação do tipo especial *REABERTA*, ligada à anterior. A anterior continua concluída, com o resultado preservado, e a auditoria mostra a nova como a "2ª", "3ª"... com o "Estamos aqui" nela. Quando o técnico reabre, cai na fila dele; quando o ADM reabre, fica com o mesmo técnico (e pode reatribuir depois). Não dá para reabrir se o PPPoE já tem uma verificação em aberto.
- Selo roxo **REABERTA**, faixa própria no card/modais, filtro *Reabertas* no Banco de Verificações e coluna Classe do CSV.
- **Reatribuir** continua dentro da mesma verificação (só troca o técnico).
- Migration `008_kind_reaberta.sql` (aditiva: só acrescenta o valor REABERTA).

## 1.5.14
- **Correção: "1ª verificação" em PPPoE com várias:** a auditoria numerava as verificações pela ordem em que os registros foram gravados no banco. Como o histórico antigo foi gravado em blocos pela migration 004, a verificação atual podia cair como "1ª" mesmo havendo outras. Agora tudo é ordenado pela data real em que cada acontecimento ocorreu, e a numeração (1ª, 2ª, 3ª...) segue a ordem cronológica.

## 1.5.13
- **Correção: PPPoE já verificado aparecia como "primeira":** a classe era gravada só na criação, então PPPoE com verificações anteriores à v1.3 (ou lançados de novo) ficavam como NORMAL. Agora o servidor calcula pelo histórico real quantas verificações anteriores existem, e a migration `007_backfill_kind.sql` corrige os registros antigos. PPPoE inédito (lote novo, sem histórico) continua sem nenhum aviso.
- **Sem total fixo na auditoria:** removido o "2ª de 3". Aparece só "2ª verificação"; a contagem cresce naturalmente se o ADM lançar o mesmo PPPoE outras vezes.

## 1.5.12
- **Aviso só com dado real:** removido o texto "Primeira verificação deste PPPoE". Para PPPoE sem verificação anterior o espaço reservado fica vazio (só mantém o tamanho do card/modal). O aviso de adicional continua aparecendo apenas quando o servidor confirma verificações anteriores, mostrando a quantidade real.

## 1.5.11
- **Faixa reservada para "verificação adicional":** card da fila, modal de atendimento e raio-X agora sempre têm uma faixa de altura fixa para esse aviso. Para PPPoE novo mostra "Primeira verificação deste PPPoE"; para repetido, "Já verificado N× antes — esta é a Nª verificação" com o atalho para a auditoria. Nada muda de tamanho nem desloca o layout.

## 1.5.10
- **Modais padronizados:** atendimento, raio-X e auditoria agora têm o mesmo tamanho fixo (largura e altura), com cabeçalho com ícone, corpo com rolagem interna e rodapé fixo. O aviso de "verificação adicional" não muda mais o tamanho da janela.

## 1.5.9
- **Iniciar não abre o modal:** *Iniciar Atendimento* só inicia (cronômetro começa). O modal abre ao clicar no card — não precisa mais do botão *Continuar Verificação*.
- **Clicar fora fecha:** nos modais de atendimento, detalhes e auditoria. O modal de atendimento também tem *Iniciar Atendimento* dentro, para quem abrir antes de começar.
- **Salvamento em tempo real:** o que é digitado nas observações é salvo enquanto escreve (e ao esconder a aba ou sair da página). Fechar sem querer não perde nada. Na auditoria, as gravações seguidas da observação do mesmo técnico (15 min) viram um único registro, sem encher a linha do tempo.

## 1.5.8
- **Card da fila mais limpo:** o card mostra só o PPPoE, os selos e o status (aguardando / em atendimento com cronômetro). Todo o preenchimento (checklist, procedimento e observações) acontece no **modal de atendimento**, que abre ao clicar em *Iniciar Atendimento* ou *Continuar Verificação*. O que foi preenchido fica salvo mesmo fechando com "Continuar depois".
- **Histórico do técnico:** as verificações concluídas aparecem da mais recente para a mais antiga; a última que você terminou fica sempre no topo.
- **Auditoria única por PPPoE:** a segunda verificação (e as seguintes) do mesmo PPPoE entra na mesma auditoria, com todas as alterações e reatribuições no mesmo lugar. Cada verificação aparece como um bloco (1ª, 2ª, 3ª...) e a atual vem marcada com **"Estamos aqui"**. A aba *Outras verificações deste PPPoE* continua como estava.

## 1.5.7
- **Engrenagem só ao iniciar:** ao clicar em *Iniciar Atendimento* a engrenagem gira rápido e vai desacelerando até parar suavemente. Todas as outras animações (ociosidade, equalizador, brilho, sino etc.) foram mantidas.
- **Saudação e status na barra fixa:** o bloco de boas-vindas do técnico saiu do corpo da página. A barra do topo agora mostra "Olá, nome", o turno e um status compacto (*Em atendimento* com login e cronômetro, *Ocioso* com botão para iniciar a próxima, ou *Fila zerada*), com as mesmas animações de antes.
- **Meu perfil dentro de Configurações do Perfil:** o atalho "Meu perfil" foi removido da fila; o botão **Ver meu perfil** agora fica na janela onde o técnico já edita foto, nome e senha.

## 1.5.6
- **"rotinaApi is not defined":** a interface agora cria a função de acesso à API por conta própria caso o `api-adapter.js` não tenha carregado, e o arquivo é chamado com `?v=` para o navegador nunca usar uma cópia antiga em cache.

## 1.5.5
- **Foto de perfil:** falhas no envio agora mostram o código e o motivo reais (sessão expirada, sem conexão, erro do banco) em vez de mensagem genérica. Se a foto for salva mas a tela falhar ao atualizar, a página recarrega sozinha.

## 1.5.4
- **Testar conexão com o banco, a qualquer hora:** novo cartão em **Sistema → Conexão com o banco de dados** (somente administrador). Mostra destino, versão do MySQL/MariaDB, tempo de resposta, tabelas e migrations, ou o motivo da falha (senha errada, banco inexistente, servidor fora do ar). Antes o botão existia só no assistente de instalação, que se bloqueia e se apaga depois de instalar.

## 1.5.3
- **Foto de perfil:** corrigido o erro "Não foi possível ler esta imagem" em fotos JPG/PNG normais. A política de segurança do site bloqueava a leitura temporária (`blob:`); agora a imagem é lida diretamente, sem alterar a política.

## 1.5.2
- **Atualização pelo navegador no Windows (Apache/XAMPP):** corrigido o falso "Erro de sintaxe no pacote". Dentro do Apache o `PHP_BINARY` aponta para o `httpd.exe`; o atualizador agora localiza o `php.exe` de verdade e, se não achar, apenas pula a verificação de sintaxe (com aviso) em vez de bloquear a atualização.

## 1.5.1
- **Foto de perfil mais fácil:** o técnico agora troca ou remove a foto também direto em **Meu perfil** (botões *Adicionar/Trocar foto* e *Remover foto*), além de *Configurações do Perfil*. Pode trocar quantas vezes quiser. O seletor aceita qualquer imagem (a foto é reduzida no navegador) e avisa quando o formato não é suportado (ex.: HEIC). Gravação da imagem no banco reforçada.
- **Meta da Data Selecionada:** passa a contar o que vence na data **e** o que foi concluído nela (mesmo com prazo antigo). A data do filtro e o "atrasadas" viram sozinhos à meia-noite, sem precisar recarregar a página.

## 1.5.0
- **Novo turno: Madrugada.** Disponível no cadastro, na edição de usuários, na criação/reatribuição de verificações, na distribuição em lote e em todos os filtros (Verificação e Banco). Ícone próprio nos cards do técnico.
- **Foto de perfil:** técnicos **e administradores** podem enviar a própria foto em *Meu Perfil* (clique no seu nome no topo). A imagem é recortada em quadrado e reduzida para 256x256 no navegador; aparece no topo, na aba Equipe e no perfil do técnico. Dá para trocar ou remover a qualquer momento. Sem foto, continuam as iniciais coloridas.
- Migration `006_madrugada_avatar.sql` (aditiva: amplia o turno e adiciona as colunas da foto). Novas rotas: `profile/avatar` e `avatar`.

## 1.4.0
- **Auditoria:** a linha do tempo agora abre do **mais recente para o mais antigo**, com opção de inverter. Novos filtros por **data (de/até)** e por **técnico** (quem fez), valendo também para a aba "Outras verificações deste PPPoE". O tempo "depois do anterior" continua correto em qualquer ordem.
- **Equipe (técnicos):** nova aba **Equipe** mostra os colegas com presença em tempo real: **Online**, **Em atendimento** (com o login), **Online · ocioso** ou **Visto há X**. Atualiza sozinha a cada ~30 s; ao sair, a pessoa fica offline na hora. Há contador de colegas online na aba, busca e filtro "Só online".
- **Perfil do técnico:** foto de iniciais, **bio** (até 500 caracteres, editada pelo próprio técnico), turno, tempo de equipe e indicadores com período de 30 dias ou total: **taxa de sucesso**, **TMA** (tempo médio de atendimento), concluídas, O.S. criadas e gráfico dos últimos 14 dias. Qualquer técnico pode visitar o perfil dos outros (o e-mail só o administrador vê). Atalho "Meu perfil" no topo da fila.
- Migration `005_profiles_presence.sql` (aditiva). Novas rotas: `team`, `profile`, `profile/bio`.

## 1.3.0
- **PPPoE já verificados (planilha e atribuição única):** ao carregar uma lista, o sistema consulta o histórico e **avisa o administrador** quais logins já foram verificados antes e quais ainda estão em aberto. Os já concluídos são **reatribuídos como nova verificação da classe ADICIONAL** (sem duplicar PPPoE e preservando o histórico). O administrador escolhe: atribuir todos, atribuir só os que **não foram resolvidos** ou **não atribuir nenhum** dos já feitos; pode ainda manter as que estão em aberto com o técnico atual. Há uma lista para **ver como cada um terminou** (resolvido, O.S., procedimento, observação, técnico e data), com busca e filtros.
- **Classe "Adicional":** selo nos cards, tabelas e detalhes; filtro "Adicionais" no Banco de Verificações; coluna "Classe" nos CSV. A classe é decidida pelo servidor.
- **Auditoria por verificação:** cada PPPoE passa a ter uma **linha de histórico por acontecimento** (criação, início, checklist, procedimento, observação, reatribuição/assunção, conclusão, reabertura) com quem fez e quando. Fica num **modal exclusivo** (botão Auditoria no detalhe, nos cards e na tabela), com abas *Linha do tempo* e *Outras verificações deste PPPoE*. As telas principais continuam limpas. O que já existia ganhou as linhas básicas (criada/iniciada/concluída).
- **Cards do técnico redesenhados**, com ícones animados: engrenagem girando, equalizador, cronômetro e brilho quando **em execução**; ampulheta virando e "zzz" quando **ocioso/aguardando**; sino balançando quando atrasado. A fila agora ordena por: em atendimento, atrasadas, prioridade e prazo.
- **Faixa de presença do técnico:** *Em atendimento* (com cronômetro), *Ocioso* (há quanto tempo parado, botão "Iniciar a próxima") ou *Fila zerada*.
- **Favicons:** ícone SVG/PNG/ICO, apple-touch-icon e manifesto; o mesmo logo no cabeçalho e no instalador.
- Migration `004_verification_history.sql` (aditiva). Novas rotas: `pppoe/check` e `verification/audit`.

## 1.2.0
- **Backups:** agora é possível **excluir** um backup (ícone de lixeira) e **limpar os antigos** mantendo só os N mais recentes (3, 5, 10 ou 20). A aba Sistema mostra a quantidade e o espaço ocupado.
- **Importação de planilha inteligente:** aceita `.csv`, `.txt`, `.tsv`, `.xlsx`, `.xls` e `.ods`, com ou sem título, separados por vírgula, `;`, TAB ou `|`. Importa **somente os logins PPPoE**: ignora o título, a pontuação/sinal do Wi-Fi e qualquer outra coluna, remove repetidos e linhas vazias, e mostra um resumo antes de distribuir. (Para `.xlsx` a biblioteca é carregada sob demanda.)
- **Barra de navegação fixa** nos painéis do administrador e do técnico: logo, usuário, menus e ações ficam sempre visíveis ao rolar. Compacta e com rolagem horizontal no celular. (Corrigido também o motivo de a barra antiga não fixar: o `body` tinha altura de 1 tela.)
- **"Lotes" virou "Verificação"** em todo o sistema.
- **Nova tela Verificação (administrador):** indicadores clicáveis (não iniciadas, em execução, concluídas, atrasadas), pesquisa por verificação/login/técnico, filtros por técnico, situação, turno, prioridade e período, ordenação e paginação. Cada verificação mostra barra de progresso e quem **não iniciou**, está **em execução** ou **concluiu**. O detalhe traz tabela por técnico, lista de logins com busca/filtros/paginação e **exportação CSV**.

## 1.1.0
- **Mensagens de erro/aviso** agora aparecem *acima* de qualquer janela (antes ficavam escondidas atrás dos modais). Erros ficam mais tempo na tela e fecham com um clique.
- **Área do técnico:** os cards têm tamanho fixo; iniciar ou concluir um atendimento não deforma mais os cards vizinhos. O campo de **Observações Técnicas** ficou bem maior (ocupa todo o espaço livre do card).
- Todas as janelas (modais) passaram a ter altura máxima com rolagem interna.
- **Senhas simples:** por ser um sistema interno, a senha precisa ter apenas **4 caracteres** (antes 8–10). Vale para instalação, cadastro, administrador e migração do sistema antigo.
- **Instalador web redesenhado:** só pergunta o essencial (banco + seu acesso), opções avançadas recolhidas, botão *Testar conexão*, mostrar/ocultar senha, e **cria o banco sozinho** quando o usuário tem permissão.
- **`install.sh` simplificado:** 3 etapas, modo navegador por padrão, no máximo uma pergunta.
- **Nova aba "Sistema"** (administrador): gerar backup, baixar, **restaurar** (com confirmação digitada e cópia de segurança automática) e **enviar o .zip de atualização** direto pelo navegador, com opção de voltar à versão anterior.

## 1.0.0
- Reescrita do servidor em **PHP 8 + MySQL/MariaDB** para rodar em Apache ou Nginx já existentes.
  Sem Docker, sem Node.js, sem Composer, sem processos em segundo plano.
- Instalador web (`install.php`) e de terminal (`./rotina install`), com `install.sh` para permissões e site Apache.
- Banco **idêntico** ao da versão Node/Docker (migrations 001 e 002 inalteradas) + migration 003 (`rate_limits`, `app_state`).
- Atualização segura: `./rotina update pacote.zip` (backup, migrations, troca de código, rollback automático).
- Backup em PHP puro (não precisa de `mysqldump`), com validação e teste de restauração; restauração por terminal ou phpMyAdmin.
- Rotinas agendadas sem cron (opcional usar cron).
- Interface original mantida; apenas as chamadas à API passaram a usar `api.php?r=...` (funciona por IP e em subpasta, sem `mod_rewrite`).
