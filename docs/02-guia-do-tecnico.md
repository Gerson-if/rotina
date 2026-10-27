# 2. Guia do técnico

[← Visão geral](01-visao-geral.md) · Próximo: [Guia do administrador →](03-guia-do-administrador.md)

Este guia mostra, na ordem do dia de trabalho, o que fazer em cada tela.

## 1. Primeiro acesso

1. Na tela de login, crie o seu cadastro (nome, e-mail, senha de pelo menos 4 caracteres e o seu **turno**).
2. A conta fica **pendente**: você ainda não entra. Peça ao administrador para **aprovar** em *Equipe & Aprovações → Aprovações*.
3. Depois de aprovada, entre com o e-mail e a senha. Não existe "esqueci minha senha" por e-mail: quem redefine é o administrador.

## 2. Minha Verificação (sua fila)

É a sua tela principal. Mostra as verificações que **estão com você**, ainda não concluídas e que não estão pendentes.
Cada cartão informa o PPPoE, o prazo, a prioridade e a situação:

| O que aparece | Significado |
|---|---|
| **Aguardando início** | Você ainda não começou. Botão **Iniciar Verificação** (azul). |
| **Em atendimento** | Você já iniciou. Botão **Continuar Verificação** (verde). |
| **Atrasada** | O prazo era antes de hoje e ainda não foi concluída. |
| **Adicional** / **Reaberta** | O PPPoE já foi verificado antes / é uma reabertura de uma concluída. |
| **RECEBIDA** + aviso âmbar | A verificação estava pendente de contato antes de chegar até você (veja a seção 6). |

Clique no cartão para abrir a **janela de atendimento**.

## 3. Atendendo uma verificação

A janela de atendimento reúne tudo em um só lugar:

1. **Iniciar verificação.** Marca o começo do atendimento; é a partir daqui que o tempo passa a contar.
2. **Checklist de atendimento.** Quatro botões que você liga ou desliga conforme o que aconteceu:
   **Ligou**, **Não Atende**, **Resolvido** e **O.S. Aberta**.
3. **Ação / Procedimento.** Escolha na lista o que foi feito. A lista é mantida pelo administrador; a opção padrão é "Nenhuma Ação".
4. **Observações: o que você fez?** Escreva com as suas palavras o que o cliente relatou, o que você fez e como ficou.
   **É obrigatório** e é o que permite a toda a equipe saber o que foi feito. O texto é salvo sozinho enquanto você escreve.
5. **Concluir verificação.** Encerra o atendimento e manda a verificação para o seu **Histórico Concluído**.

Botões auxiliares na mesma janela:

| Botão | Para que serve |
|---|---|
| **Continuar depois** | Fecha a janela e guarda o que você preencheu. A verificação continua com você. |
| **Soluções da equipe** | Mostra como outros técnicos resolveram esse caso. |
| **Auditoria** | Abre a linha do tempo da verificação (tudo o que já aconteceu com ela). |
| **Deixar pendente de contato** | Para quando você não consegue concluir (veja a seção 5). |

### A regra das observações

Para concluir, você precisa **escrever algo novo** (pelo menos 5 caracteres) no campo de observações. Texto que já estava lá
antes **não conta**. Se tentar concluir sem escrever, o campo fica destacado em vermelho e o sistema avisa. Uma linha curta
embaixo do campo acompanha: em amarelo ("Escreva o que fez e o que encontrou para poder concluir") enquanto estiver em branco,
e em verde ("Anotado") quando há texto novo.

### Tempo de atendimento

O tempo é medido do **Iniciar** ao **Concluir**. Atendimentos que ficam abertos por mais de 8 horas (esquecidos) não entram
no tempo médio da equipe.

## 4. Atalhos úteis

- **Esc** fecha a janela aberta (na de atendimento, guarda o que você escreveu).
- Clicar **fora** da janela **não** a fecha: isso evita perder o que você estava fazendo.
- A tela se atualiza sozinha; você não precisa recarregar.

## 5. Deixar pendente de contato

Use quando **não deu para concluir** (cliente não atende, troca de turno, falta uma informação...). A verificação sai de
*Minha Verificação* e vai para *Pendentes de contato*, onde qualquer técnico pode assumir e continuar.

1. Na janela de atendimento, clique em **Deixar pendente de contato**.
2. Escreva **em poucas palavras** o que já foi tentado e o que falta, de **3 a 200 caracteres** (há um contador). Exemplo:
   *"Liguei 3x, não atendeu. Tentar depois das 7h."* Não precisa de redação: é um recado curto para quem vai continuar.
3. Confirme. A nota acompanha a verificação como aviso para quem receber.

> Deixar pendente é o jeito certo de **não perder o que já foi feito** quando não dá para concluir. O sistema registra
> *quem deixou* e *quem retomou*; o administrador vê isso no painel de Pendências para acompanhar o fluxo da equipe.

## 6. Pendentes de contato e verificações recebidas

**Aba *Pendentes de contato*:** lista a fila central (com paginação). Para assumir uma, clique em **Assumir** e confirme.
Ela vai para a sua *Minha Verificação*.

Uma verificação também pode **chegar até você sozinha**: se o administrador ligou a *Redistribuição*, o sistema entrega
pendências a técnicos dos turnos habilitados, de forma justa. O mesmo vale quando o administrador **designa** uma pendência a você.

Em qualquer desses casos (assumida, redistribuída ou designada), você recebe a verificação assim:

- **As observações vêm em branco.** O que estava escrito antes não vai para o seu campo; ele fica guardado na auditoria.
- Aparece o **aviso** "Esta verificação estava pendente de contato", só para informar, com **quem deixou**,
  **há quanto tempo** e a **nota** dele ("o que já foi tentado e o que falta").
- O cartão ganha o selo **RECEBIDA**. O aviso some quando você conclui.
- Como as observações começam vazias, você precisa **escrever a sua parte** para concluir.

Exceção: se **você mesmo** retoma uma pendência que você deixou, nada é limpo e não aparece "recebida".

## 7. Verificações dos outros técnicos

Em *Verificações dos outros técnicos* você pode **assumir verificações abertas de colegas**. Há busca por PPPoE ou técnico,
paginação e a opção *Ver concluídas dos colegas* (somente consulta). Ao assumir, a verificação passa para a sua fila e as
observações que ela já tinha **continuam como estavam** (o "começar em branco" da seção 6 vale só para quem assume uma
*pendência de contato*).

## 8. Histórico Concluído

Lista o que você já concluiu, com o resultado de cada atendimento (o menu não mostra número aqui: tudo o que está no histórico já foi concluído). Dali você pode **reabrir** uma
verificação sua: o sistema cria uma **nova** verificação de classe *Reaberta* para o mesmo PPPoE (a original permanece
intacta, com o resultado que tinha). Só é possível reabrir verificações **suas**.

## 9. Equipe e Meu Dashboard

- **Equipe:** mostra quem está **online** e quem está **atendendo** agora, e permite abrir o perfil dos colegas. Uma pessoa
  aparece online enquanto estiver com o sistema aberto.
- **Meu Dashboard:** seus números (concluídas, resultado dos atendimentos, prazo e tempo médio) por **semana, mês ou ano**.

## 10. Mensagens comuns

| Mensagem | O que significa |
|---|---|
| "Antes de concluir, escreva em Observações o que você fez" | O campo de observações está sem texto novo. Escreva e conclua. |
| "A nota da pendência aceita até 200 caracteres" | A nota ficou longa; resuma. |
| "Esta verificação foi alterada por outra pessoa. Os dados foram recarregados — refaça a ação." | Alguém mexeu na mesma verificação antes de você (por exemplo, assumiu). Veja o estado atual e repita a ação, se ainda fizer sentido. |
| "Esta verificação pertence a outro técnico." | Você tentou alterar uma verificação que não é sua. Use *Assumir*, se for o caso. |
| "Cadastro em aprovação" | O administrador ainda não liberou o seu acesso. |
| "Acesso bloqueado" | Procure o administrador. |
