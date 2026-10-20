# Especificação — IA de tatuagem: nome do cliente, ideia e tom das respostas

**Status:** proposta, sem implementação  
**Versão:** 1.0  
**Data:** 20/10/2026  
**Produto:** Agendaqui  
**Base de código analisada:** `origin/main` em `2a07400`

## 1. Resumo

A conversa da GODS TATOO de 20/10 (telefone 553496857787) mostrou falhas na IA de tatuagem:

- O cliente respondeu "lucas" e "Lucas" e o bot pediu o nome de novo. Ele só seguiu com "me chamo lucas".
- O cadastro do cliente ficou com o nome "queria fazer um orçamento".
- A ideia da tatuagem recebeu a frase de pedido de orçamento.
- No meio da conversa, a IA cumprimentou de novo ("Olá Lucas! Tudo bem?").

O detector de nomes reconhece "Lucas" corretamente. Os problemas estão nas regras em volta dele:

- conflito com o nome de um tatuador do estúdio;
- cadastro antigo nunca corrigido;
- descrição preenchida a partir da resposta ao pedido do nome;
- texto livre do Gemini enviado sem filtro.

Esta especificação corrige esses pontos. Também propõe usar o nome do WhatsApp para confirmar o nome do cliente, em vez de perguntar.

Todas as referências `arquivo:linha` são de `origin/main` `2a07400`. Exceto quando outro arquivo for indicado, `TattooAI` = `app/Services/Tattoo/TattooAIConversationService.php`.

## 2. Conversa de referência

| Cliente | Bot |
|---|---|
| opa | Oi! Sou a assistente do estúdio *GODS TATOO*. Vou te atender daqui até o tatuador entrar na conversa. Qual seu nome? |
| queria fazer um outro orçamento de tatto | Não peguei seu nome. Como você se chama? |
| lucas | Pra eu seguir, me diz só o seu nome. |
| Lucas | Não peguei seu nome. Como você se chama? |
| me chamo lucas | (segue o fluxo) |
| no antebraço, parte de cima | pergunta de tamanho |
| 25cm | Qual estilo você imagina para a tatuagem? … |
| o estilo é Lettering | Olá Lucas! Tudo bem? Você gostaria de me mostrar alguma imagem de referência ou… |

Painel: título "queria fazer um orçamento", ideia "queria fa… tattoos", local "no antebraço", tamanho "25cm".

Simulação do `CustomerNameDetector` de `origin/main`, executada no PC:

| Entrada | Nome extraído do texto | É um nome plausível? |
|---|---|---|
| lucas / Lucas / me chamo lucas | Lucas | sim |
| queria fazer um outro orçamento de tatto | nenhum | não |
| o estilo é Lettering | "o estilo é Lettering" | **sim** |
| opa | nenhum (é saudação) | **sim** |

## 3. Itens

### 3.1 Nome do cliente igual ao de um tatuador (prioridade alta)

**Problema.** "lucas" e "Lucas" foram rejeitados como nome, e só "me chamo lucas" foi aceito. O texto entra como preferência de tatuador (`artist_preference = Lucas`) e como a nota "Mencionou tatuador: Lucas". Esses dados continuam no pedido.

**Causa raiz.**
- `TattooAI:350-357` (`detailsWithoutModel`) envia o nome solto para preferência de tatuador quando ele é igual ao de um profissional e o cliente não disse "me chamo…".
- `TattooAI:435-456` e `:476` (`sanitizeCollectedName`) fazem o mesmo.
- `TattooAI:520-563` (`matchingStudioProfessional`/`mentionedStudioProfessional`) comparam só o primeiro nome dos profissionais ativos.
- Sem um tatuador chamado Lucas, o nome teria sido aceito em `:467-487`. Por isso a hipótese é que a GODS TATOO tem um profissional ativo com esse primeiro nome (ver seção 5).

**Comportamento esperado.**
- Se a última pergunta do bot foi o nome, um nome solto e plausível é o nome do cliente, mesmo que coincida com o de um tatuador.
- Se houver ambiguidade real, a IA pergunta "Lucas é você ou o tatuador?" no tom do estúdio. Exemplo: o cliente escreve "quero com o Lucas" ou cita um sobrenome que também é de um profissional.
- A preferência de tatuador só é gravada quando o cliente pede um tatuador ("quero com o Lucas", "pode ser o Lucas?"). Nunca vem da resposta ao pedido do nome.

**Critérios de aceite.**
1. Depois de "Qual seu nome?", tanto "lucas" quanto "Lucas" viram nome do cliente, mesmo com o profissional Lucas ativo. Não ficam `artist_preference` nem nota.
2. "quero fazer com o Lucas" ainda grava a preferência de tatuador.
3. Na resposta ambígua, a pergunta de confirmação é feita uma vez. A resposta do cliente decide.

**Testes.** Criar casos de feature em `TattooAIConversationService` com um profissional "Lucas" ativo:
- nome solto após o pedido do nome;
- "me chamo Lucas";
- "quero com o Lucas";
- caso ambíguo e a resposta "sou eu".

### 3.2 Cadastro salvo com uma frase como nome (prioridade alta)

**Problema.** O cliente já existia com o nome "queria fazer um orçamento". O título da conversa mostra essa frase mesmo depois de a IA coletar "Lucas".

**Causa raiz.**
- O cadastro veio de código anterior a d46bfc4/0fbc1ef. No `68e17b3`, a IA salvava o nome do Gemini só com a validação `string|min:2`. Hoje `TattooAI:272` exige `isPlausible`.
- `app/Services/AI/WhatsAppAIConversationService.php:408-419` (`saveClient`): só renomeia nomes do tipo "Cliente WhatsApp N" (`isPlaceholderName`, `:427-431`). `createRequest` (`TattooAI:894`) usa a mesma função.
- `app/Services/AI/WhatsAppAIConversationService.php:384-406` (`linkKnownClient`): vincula o cliente. Corretamente, não preenche o nome com a frase (`TattooAI:493-497`), mas também não corrige o cadastro.
- `app/Filament/App/Resources/TattooAiConversations/Pages/ViewTattooAiConversation.php:36` e `:147`: usam o nome do cadastro antes do nome coletado.

**Comportamento esperado.**
- `saveClient` renomeia o cliente quando o nome atual é placeholder **ou** não passa em `CustomerNameDetector::isPlausible`.
- Nome cadastrado válido nunca é sobrescrito.
- A tela usa o nome coletado (ou o telefone) quando o nome do cadastro é inválido.
- Um comando de uma vez só, por exemplo `clients:fix-invalid-names`, limpa os clientes com nome inválido:
  - `--dry-run` é o padrão e gera um relatório por empresa (id, nome atual, nome proposto, origem);
  - `--apply` aplica as mudanças;
  - se não houver nome confiável, usa "Cliente WhatsApp N" ou o nome do WhatsApp do item 3.7.

**Critérios de aceite.**
1. Um cliente "queria fazer um orçamento" é renomeado para "Lucas" quando a IA coleta o nome.
2. Um cliente "Maria Souza" não é alterado.
3. O dry-run não grava nada. O apply registra quem executou e o antes e o depois.

**Testes.**
- Unitários de `saveClient` para os casos placeholder, frase e nome válido.
- Teste da página: título com cadastro inválido mais nome coletado.
- Teste do comando em dry-run e apply.

### 3.3 Resposta ao pedido do nome virando ideia (prioridade alta)

**Problema.** A ideia recebeu "queria fazer um outro orçamento de tatto".

**Causa raiz.**
- `TattooAI:362`: quando o nome foi pedido e não foi encontrado, todo texto considerado "usável" vira descrição.
- `TattooAI:873-877` (`hasUsableDescription`): só rejeita formas mínimas como "quero uma tatuagem".
- `TattooAI:371` e `:748`: preenchem a descrição pela última pergunta.

**Comportamento esperado.**
- Uma resposta ao pedido do nome nunca preenche a ideia.
- `hasUsableDescription` rejeita frases de pedido sem conteúdo de desenho. Exemplos: "orçamento", "queria/quero fazer (um/outro)", "fazer uma tattoo", "quanto custa", "tem horário".

**Critérios de aceite.** A frase acima não preenche a ideia em nenhum ponto do fluxo. "quero uma rosa fine line no braço" continua sendo aceita.

**Testes.** Casos de dados para `hasUsableDescription` e um fluxo completo com a conversa de referência.

### 3.4 Cumprimento do Gemini no meio da conversa (prioridade média)

**Problema.** Depois de "o estilo é Lettering", o bot respondeu "Olá Lucas! Tudo bem? Você gostaria de me mostrar…".

**Causa raiz.**
- `TattooAI:291-292` usa o texto livre do modelo quando `replyAsksForMissingDetail` (`:813-833`) vê que ele pergunta pelo campo faltante.
- Não há filtro de saudação. Não foi a detecção de cumprimento: a simulação confirma que "o estilo é Lettering" não é saudação.

**Comportamento esperado.**
- Depois da primeira resposta do bot, o texto do modelo não abre com saudação ("Olá", "Oi", "Tudo bem?", "Opa, {nome}").
- A saudação inicial é removida. Se o resto ficar vazio ou incoerente, usar `nextQuestion`.
- Incluir a regra também no prompt (`TattooAI:655-675`).

**Critérios de aceite.** Nenhuma mensagem depois da primeira começa com saudação. A pergunta útil é mantida.

**Testes.** Gemini simulado retornando "Olá Lucas! Tudo bem? Você tem referência?": o envio deve ser "Você tem referência?" (ou a pergunta padrão).

### 3.5 Detector aceitando "o estilo é Lettering" e "Opa" (prioridade média)

**Problema.** A simulação mostra que esses textos passam como nome plausível. Aqui não fez estrago porque o nome já existia, mas em outra ordem eles virariam nome do cliente.

**Causa raiz.**
- `app/Support/CustomerNameDetector.php:13`: a lista `NOT_A_NAME` não tem palavras de campos do pedido nem algumas saudações.
- `:70-77` (`isPlausible`): aceita 1 a 4 palavras só com letras.
- Também relacionado: `TattooAI:758` grava o estilo com o texto inteiro.

**Comportamento esperado.**
- Ampliar `NOT_A_NAME` com:
  - campos do pedido: estilo, tamanho, local, braço, antebraço, perna, costas, cm, referência, foto, lettering, realismo, blackwork, fine line;
  - saudações e interjeições: opa, eae, salve, fala, bom dia.
- Ao gravar estilo e outros campos abertos, remover prefixos como "o estilo é", "quero", "seria", "é".

**Critérios de aceite.** "o estilo é Lettering" e "Opa" não são plausíveis. O estilo é gravado como "Lettering". Nomes reais como "Lucas", "Ana Paula" e "Fala Neto" continuam aceitos.

**Testes.** Casos de dados no teste do detector e em `acceptOpenAnswer`.

### 3.6 Loop no pedido do nome (prioridade média)

**Problema.** O bot alternou entre "Não peguei seu nome…" e "Pra eu seguir, me diz só o seu nome." sem explicar nem oferecer saída.

**Causa raiz.**
- `TattooAI:396-415` (`askForName`) e `:765-811` (`repeatsQuestion`/`finishReply`, textos em `:800-801`) não têm limite de tentativas.

**Comportamento esperado.**
- Na segunda tentativa, aceitar um nome plausível que veio na mensagem ou confirmá-lo ("É Lucas?").
- Na terceira, seguir sem nome usando o nome do WhatsApp (item 3.7) ou "você", e marcar a conversa para a equipe.
- Guardar o contador de tentativas no `collected_data`.

**Critérios de aceite.** O pedido do nome não aparece mais de 2 vezes em seguida e o fluxo sempre avança.

**Testes.** Uma sequência de 3 respostas sem nome deve terminar com o fluxo avançando e a conversa marcada.

### 3.7 Nova funcionalidade: usar o nome do WhatsApp (prioridade média, depois de 3.1–3.3)

**Situação atual.**
- O webhook `messages.upsert` (`app/Services/WhatsApp/EvolutionWebhookService.php:89` e `:168`) recebe o `pushName` (o nome público do perfil), mas a IA ignora esse campo.
- `app/Services/WhatsApp/WhatsAppContactService.php` (`sync`, `:23`) já busca contatos via `findContacts`. Hoje `contactName` (`:142-146`) usa `name` e, na falta dele, `pushName`.

**Investigação necessária.** Verificar com a instância real se a Evolution devolve o nome **salvo na agenda do celular da empresa** ou só o `pushName`. Se não devolver, a fonte "agenda" fica de fora.

**Comportamento esperado.**
- **Prioridade:** cliente cadastrado com nome válido > nome da agenda da empresa > `pushName`.
- Todo nome candidato passa por `CustomerNameDetector::isPlausible` e pela regra do item 3.1.
- **IA confirma em vez de perguntar.** Exemplo: "Opa, Lucas, tudo bem? É Lucas mesmo?" no tom do estúdio. Se o cliente corrigir ("na verdade é Lucão"), a correção prevalece. Se confirmar ou ignorar e seguir, o nome é aceito.
- **Origem do nome.** Guardar a fonte em `collected_data.name_source`: `client`, `contact_book`, `push_name`, `customer` ou `model`. Quando o cadastro for criado ou corrigido por essa via, registrar a origem para auditoria.
- Nunca sobrescrever nome válido já cadastrado. `pushName` com emoji, empresa ou apelido implausível é descartado.
- Implementar no núcleo compartilhado (`app/Services/AI/WhatsAppAIConversationService.php`, `handle`, `:59`) e aplicar à IA de tatuagem e à de estética (`app/Services/Beauty/BeautyAIConversationService.php`).

**Critérios de aceite.**
1. Primeira mensagem com `pushName` "Lucas": a IA confirma o nome em vez de pedi-lo.
2. `pushName` "🔥 Studio X": o nome é pedido normalmente.
3. Cliente cadastrado "Lucas Silva": o nome não muda.
4. A correção do cliente substitui o candidato e `name_source` passa a ser `customer`.
5. Funciona nas duas IAs.

**Testes.**
- Webhook com e sem `pushName`.
- Prioridade entre as fontes.
- Confirmação, correção e nome inválido.
- Variante para a IA de estética.

### 3.8 Ponto de decisão (não é bug): tom da apresentação

A apresentação "Oi! Sou a assistente do estúdio *X*. Vou te atender daqui até o tatuador entrar na conversa." (`TattooAI:580-583`), o prompt "Você é a assistente do estúdio…" (`:655-675`) e a pergunta de estilo com exemplos (`:685`) vieram de commits posteriores a `71f2a29` (d46bfc4/0fbc1ef). Eles se afastaram do tom informal de tatuador, que dizia para não se apresentar como assistente ou robô. Os asteriscos são negrito no WhatsApp e não são erro.

**Decisão do Iago (20/10): misturar.** O tom volta a ser informal, de alguém do estúdio, mas a IA avisa logo na abertura que é a assistente e que o tatuador acompanha a conversa.

**Regras.**
- A abertura (só na primeira mensagem) diz que é a assistente, em tom leve e numa frase curta. Exemplo: "Opa, tudo bem? Aqui é a assistente do *{estúdio}*, o tatuador acompanha a conversa por aqui 🤙 Qual seu nome?"
- Daqui pra frente, nada de se apresentar de novo: mensagens curtas, uma pergunta por vez, gírias leves ("show", "massa", "fechou"), no máximo um emoji, sem "prezado", "informe", "aguarde", "seu atendimento" nem listas.
- Perguntas de exemplo:
  - "Show, Lucas! Me conta como você imagina a tattoo?"
  - "Massa! E vai ser em qual parte do corpo?"
  - "E mais ou menos de que tamanho? Pode ser em cm mesmo."
  - "Já tem um estilo em mente? Tipo fine line, blackwork, lettering… se não souber, tranquilo."
  - "Tem alguma foto de referência? Se tiver, manda aqui."
- Se perguntarem "é robô?" ou "é uma pessoa?", a resposta é sincera: é a assistente do estúdio, e o tatuador entra na conversa pra fechar o orçamento.
- Aplicar a `studioAssistantIntro()` (`TattooAI:580-583`), ao prompt (`:655-675`), a `nextQuestion()` (`:685`) e às mensagens fixas de fallback e handoff.

**Critérios de aceite.** A primeira mensagem do bot menciona "assistente" uma única vez. Nenhuma mensagem depois dela se apresenta de novo. As mensagens fixas seguem os exemplos acima.

**Testes.** Ajustar as asserções de texto em `TattooAIFlowTest`/`TattooAIResilienceTest` e incluir um teste garantindo que "assistente" aparece só na primeira resposta.

## 4. Ordem sugerida de implementação

1. 3.1 nome igual a tatuador e 3.3 nome virando ideia: correções pequenas e de maior impacto.
2. 3.2 `saveClient` e tela, depois o comando de limpeza: rodar o dry-run e revisar antes de aplicar.
3. 3.5 detector e 3.6 limite de tentativas.
4. 3.4 filtro de saudação.
5. 3.7 nome do WhatsApp: investigar `findContacts` primeiro.
6. 3.8: aplicar o tom misturado definido pelo Iago.

## 5. Checagens em produção

- Profissionais ativos da GODS TATOO: confirmar se existe algum com primeiro nome Lucas (valida a causa do item 3.1).
- `collected_data` da conversa de 20/10 (553496857787): conferir `artist_preference`, `notes`, `description`, `style` e `name`.
- Cliente vinculado a essa conversa: data de criação e conversa de origem do nome "queria fazer um orçamento".
- Quantidade de clientes com nome inválido por empresa: base para o dry-run do item 3.2.
- Payload real do `findContacts` da instância da GODS TATOO: existe nome da agenda além do `pushName`?

## 6. Fora de escopo

- Fluxo de comprovante PIX e atraso humanizado das respostas, que já foram entregues.
- O bot antigo baseado em regras (`TattooWhatsAppBotService`).
- Mudanças de modelo ou de cota do Gemini.
- Importação em massa de contatos (já existe em `WhatsAppContactService::importAsClients`).

## 7. Riscos

- **Nome solto aceito como cliente quando era tatuador:** mitigar com a confirmação no caso ambíguo e com a correção pelo cliente.
- **Limpeza de cadastros:** pode renomear clientes que a equipe nomeou de propósito. Por isso dry-run, relatório e apply explícito.
- **`pushName` não confiável** (apelidos, nomes de empresa): sempre confirmar e nunca sobrescrever nome válido.
- **`NOT_A_NAME` maior** pode rejeitar nomes raros iguais a palavras comuns. Cobrir com testes de nomes reais.
- **Filtro de saudação** pode cortar uma resposta útil. Recorrer a `nextQuestion`.
