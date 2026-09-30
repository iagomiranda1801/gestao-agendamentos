# Perfil de tatuagem e orçamentos

**Status:** MVP implementado (28/09/2026)  
**Data:** 28/09/2026

## Objetivo

Receber pedidos de tatuagem pelo WhatsApp, reunir descrição e foto de referência, entregar o pedido ao tatuador para análise e permitir que ele envie um orçamento rápido. Um pedido só ocupa a agenda depois de convertido em agendamento.

## Encaixe no sistema atual

- `CompanyProfile` define os perfis e seus módulos padrão. Adicionar `TattooStudio` com Agenda, WhatsApp e Financeiro. Manter a seleção de módulos editável pelo administrador.
- O bot atual (`HandleWhatsAppInboundMessageJob` e `WhatsAppBookingBotService`) agenda serviços via texto. O webhook recebe o payload, mas o job recebe apenas texto e identificador da mensagem. Para aceitar fotos, ampliar a entrada de mídia e roteá-la por perfil/fluxo sem alterar o bot das demais empresas.
- `WhatsAppBotConversation` guarda o estado temporário da conversa; não deve ser o registro permanente do pedido, pois conversas finalizadas são limpas após sete dias.
- `Appointment` representa horário reservado. `Attendance` representa serviço concluído e seus valores. Orçamento é anterior aos dois e precisa de entidade própria.
- O armazenamento privado de anexos clínicos já dá um padrão de validação, isolamento por empresa e acesso autenticado. As referências de tatuagem devem ter política própria e não ser misturadas ao prontuário clínico.

## Jornada proposta

1. No WhatsApp de uma empresa com perfil Tatuagem e bot habilitado, a primeira mensagem de texto inicia o pedido de orçamento perguntando o nome, sem menu numerado. Uma conversa encerrada só reabre com pedido explícito, ou com saudação após o período de espera, para não interferir no atendimento humano. O bot não oferece agendamento; quando o cliente pede horário, explica que o tatuador analisa o orçamento primeiro. O cliente pode pedir atendimento humano em linguagem natural.
2. Para orçamento, o bot coleta nome, descrição do desenho, parte do corpo, tamanho aproximado em centímetros e uma foto de referência. Se o cliente já descreveu a tatuagem na primeira mensagem, o bot aproveita essa informação. A referência pode chegar como foto ou documento JPEG, PNG ou WEBP; o cliente também pode informar que não tem foto. Cores ou estilo podem ser descritos na mensagem do desenho.
3. O bot mostra um resumo e pede confirmação. Depois cria uma solicitação com status **Aguardando análise** e informa que o valor será definido por uma pessoa. Não informa preço automático.
4. A solicitação entra na tela **Orçamentos de tatuagem**. A equipe atribui o tatuador, que pode ver as imagens, montar a proposta e enviá-la pelo WhatsApp. Pedidos de mais detalhes são tratados pela equipe na conversa com o cliente.
5. O orçamento registra valor fechado ou faixa estimada, duração por sessão, número previsto de sessões, sinal opcional, validade e observações. A criação salva a versão exata do texto que será enviado ao cliente.
6. O aceite do cliente é registrado pela equipe no painel no MVP. A partir do orçamento aceito, a equipe cria um agendamento com cliente e tatuador já preenchidos. A disponibilidade continua sendo validada pela agenda existente.
7. Ao concluir a sessão, o atendimento existente registra o serviço e o valor efetivamente realizado. O orçamento não gera receita, comissão nem contas a receber por si só.

### Estados da solicitação

`aguardando_analise` → `em_analise` → `orcamento_enviado` → `aceito` → `agendado`.

Saídas alternativas: `aguardando_cliente` (faltam detalhes), `recusado`, `expirado` e `cancelado`. O histórico de versões do orçamento permanece consultável. Uma trilha detalhada de mudanças de status fica para evolução posterior.

## Dados e vínculos

- **Solicitação**: `company_id`, `client_id`, profissional responsável opcional, origem WhatsApp ou cadastro manual, descrição, parte do corpo, tamanho aproximado, estilo/cores opcionais, observações, status, timestamps e `whatsapp_bot_conversation_id` opcional.
- **Anexos**: `company_id`, `request_id`, disco/caminho, MIME, tamanho, nome original, identificador da mensagem de origem e data. Guardar arquivos em disco privado, com limite de cinco imagens de até 10 MB, MIME permitido e visualização autorizada por empresa e por usuário.
- O disco das fotos é configurado por `TATTOO_FILESYSTEM_DISK` e usa `s3` por padrão. No desenvolvimento local, pode ser definido como `local`.
- **Propostas**: `request_id`, versão, tipo de preço (fechado/faixa), valores, sessões e duração estimadas, sinal, validade, condições, texto enviado, quem preparou e data do envio. Uma revisão cria nova versão.
- **Conversão**: `appointment_id` opcional na solicitação, com validação de empresa/cliente; a proposta aceita serve como referência, sem copiar automaticamente o valor para receita realizada. Um orçamento com várias sessões pode originar vários agendamentos em uma etapa posterior.
- Valores monetários devem usar o padrão financeiro existente e nunca ser calculados a partir do texto da conversa.

## Interface e permissões

- Tela de fila pesquisável por cliente/local, com filtro por status e ordenação por data. Filtros por tatuador e intervalo de datas ficam para evolução posterior.
- Tela de detalhes com resumo, imagens, histórico e ação **Fazer orçamento**. Formulário curto com prévia da mensagem antes de enviar.
- Acesso restrito à empresa. Quem tem a permissão existente de gerenciar agendamentos pode ver e atribuir pedidos; o tatuador vinculado ao profissional vê os próprios pedidos e pode orçá-los. Permissões separadas para orçamentos ficam para evolução posterior.
- Permitir solicitação manual para clientes que chegam pelo balcão ou por outro canal.
- No cadastro do cliente, mostrar pedidos e orçamentos anteriores; no agendamento gerado, mostrar link para a solicitação de origem.

## WhatsApp e imagens

- Detectar `imageMessage` e legenda no webhook, incluindo mensagens sem texto. Não carregar binários em payload de job nem armazenar imagem no JSON da conversa.
- Obter a mídia pela Evolution API, validar MIME/tamanho, gravar em armazenamento privado e registrar metadados. Se o download falhar, manter o passo de coleta e pedir reenvio.
- Aplicar idempotência pelo identificador da mensagem tanto ao anexo quanto à criação da solicitação. Preservar o bloqueio por empresa/telefone do bot.
- Mensagens recebidas depois da entrega à equipe não devem reiniciar o questionário automaticamente. O comando de menu pode abrir nova solicitação seguindo as regras de cooldown existentes.
- O envio do orçamento usa o cliente Evolution e o gate de mensagens de saída já existentes, com registro do resultado e proteção contra envio duplicado.

## Fases de implementação

1. **Base e painel:** perfil Tatuagem, tabelas, policies, solicitação manual, fila, detalhe, anexos privados e formulário de orçamento. Esta fase permite validar o fluxo de trabalho sem depender da captura de mídia do WhatsApp.
2. **Coleta pelo bot:** menu e estados próprios para tatuagem, captura de texto/foto, confirmação e criação idempotente da solicitação.
3. **Envio e conversão:** envio da proposta pelo WhatsApp, histórico de versões, aceite manual e criação de agendamento vinculado.
4. **Evoluções pendentes:** aceite pelo próprio WhatsApp, sinal com cobrança, agendamento de múltiplas sessões, lembretes de orçamento e métricas de conversão.

## Critérios de aceite do MVP

1. Uma empresa de tatuagem recebe pedido com descrição, local, tamanho e foto opcional sem criar horário na agenda.
2. A foto fica acessível apenas a usuários autorizados daquela empresa.
3. Mensagem duplicada do webhook não cria pedido, anexo ou envio duplicado.
4. O tatuador consegue preparar, revisar e enviar orçamento com os campos essenciais em poucos passos.
5. Pedido e versões de proposta continuam disponíveis após a limpeza da conversa do bot.
6. Um orçamento aceito pode gerar agendamento; a conclusão desse agendamento usa o atendimento e o financeiro já existentes.
7. O bot de agendamento das empresas de outros perfis mantém o comportamento atual.

## Decisões aplicadas ao MVP

- A equipe atribui o tatuador após receber o pedido.
- O orçamento aceita valor fechado ou faixa estimada.
- O sinal é apenas uma informação na proposta; cobrança fica para fase posterior.
- Aprovação da arte final não integra o MVP.
