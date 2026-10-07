# Atendimento com IA para tatuagem

## Arquitetura encontrada (etapa 1)

- Laravel 13/PHP 8.4, Filament 5, banco relacional Eloquent, filas Laravel (`database` por padrão; Horizon/Redis também presentes), Vite.
- O tenant é `Company`; recursos Filament usam o painel `app` e isolamento por empresa. Usuários pertencem à empresa via `company_user`. Profissionais, clientes, agenda e permissões já têm models, policies e services próprios.
- A Evolution API já recebe `messages.upsert` em `EvolutionWebhookService`, registra eventos e dispara `HandleWhatsAppInboundMessageJob`. O bot anterior de tatuagem usa `WhatsAppBotConversation`, que é temporária, e cria `TattooRequest`. Os demais perfis usam os bots de agendamento/cardápio. Mensagens de saída usam `EvolutionApiClient` e `WhatsAppOutboundGate`; uma resposta manual ativa `WhatsAppHumanTakeover`.
- `TattooRequest`, `TattooRequestImage` e `TattooQuote` representam pedido, referências privadas e proposta humana com valor/sinal. `AppointmentService` e `AvailabilityService` validam a agenda. `FinancialAccount` já contém chave PIX, banco e conta padrão de recebimento. Por isso não foram duplicados.
- `TattooImageService` e `TATTOO_FILESYSTEM_DISK` definem o padrão de mídia privada, incluindo S3.

## Arquivos (etapa 2)

Criados:

- `database/migrations/2026_10_06_100000_create_tattoo_ai_tables.php`
- `app/Models/TattooAiConversation.php`, `TattooAiMessage.php`, `TattooPaymentReceipt.php`
- `app/Services/AI/GeminiService.php`
- `app/Services/Tattoo/TattooAIConversationService.php`, `TattooReceiptService.php`, `TattooAISchedulingService.php`
- `app/Jobs/NotifyTattooReceiptJob.php`
- `app/Http/Controllers/TattooReceiptController.php`
- `app/Filament/App/Resources/TattooAiConversations/TattooAiConversationResource.php` e suas páginas `ListTattooAiConversations.php`, `ViewTattooAiConversation.php`
- `tests/Feature/Tattoo/TattooAIFlowTest.php`
- Este documento.

Modificados:

- `.env.example`, `config/services.php`, `routes/web.php`
- `app/Jobs/HandleWhatsAppInboundMessageJob.php`, `app/Services/WhatsApp/EvolutionWebhookService.php`
- `app/Models/CompanySchedulingSetting.php`, `FinancialAccount.php`, `TattooRequest.php`
- `app/Filament/App/Pages/SchedulingSettingsPage.php`
- `app/Filament/App/Resources/FinancialAccounts/Schemas/FinancialAccountForm.php`
- `app/Filament/App/Resources/TattooRequests/TattooRequestResource.php`, `Pages/EditTattooRequest.php`
- `app/Filament/App/Resources/Appointments/Pages/CreateAppointment.php`

## Banco (etapa 3)

A migração adiciona `tattoo_ai_enabled` e `tattoo_ai_prompt` às configurações de agenda e `pix_recipient_name` à conta financeira. Cria `tattoo_ai_conversations` (estado persistente, empresa, cliente, profissional, pedido e intervenção humana), `tattoo_ai_messages` (histórico limitado no prompt, idempotência pelo ID da Evolution e uso de tokens) e `tattoo_payment_receipts` (arquivo privado, análise e confirmação humana). Todas têm chaves estrangeiras, índices e timestamps. As tabelas novas não usam soft delete porque as tabelas de orçamento existentes também não usam.

## Fluxo e proteções

1. Ative **Bot de orçamentos no WhatsApp** e **Atendimento com IA (Gemini)** nas configurações da agenda da empresa de tatuagem. Sem a segunda opção, o bot anterior continua atendendo. Empresas de outros perfis não passam pela IA.
2. O webhook continua apenas registrando e enfileirando. O job resolve a empresa e chama `TattooAIConversationService`. A mensagem recebida é salva antes de chamar Gemini. O ID do WhatsApp é único por empresa; respostas também usam uma chave derivada dele. Há lock por empresa/telefone.
3. Gemini só retorna JSON de intenção, campos coletados e texto. O backend permite `ask`, `save_details`, `request_approval` e `handoff`, valida os campos e cria o pedido com os models existentes quando desenho, local e tamanho estiverem disponíveis. A IA não cria proposta com valor, executa SQL ou escolhe rotas. O prompt recebe até oito mensagens recentes e os dados estruturados, sem histórico ilimitado. Uma referência recebida cedo fica temporariamente em armazenamento privado e é vinculada ao pedido quando ele é criado.
4. O tatuador define e envia a proposta pela tela de orçamentos existente. Após o aceite explícito do cliente, o backend consulta a proposta aceita e a conta financeira ativa com PIX. Só envia chave, favorecido e sinal quando esses dados estão completos.
5. Imagem/PDF do comprovante é baixado pela Evolution e salvo em disco privado. Gemini extrai dados em JSON. O backend compara valor e favorecido quando disponíveis e mantém `payment_status=receipt_received`. A equipe recebe notificação no painel e abre o arquivo pela rota autenticada. Só a ação humana **Confirmar sinal** marca `confirmed`; **Rejeitar comprovante** permite novo envio.
6. Após confirmação, o backend lista opções consultadas em `AvailabilityService`. A escolha precisa corresponder a uma opção atual; `AppointmentService` valida de novo dentro da transação e cria o agendamento. O fluxo de criação manual também exige sinal confirmado nos pedidos originados pela IA quando houver sinal definido.
7. **Assumir atendimento** cala a IA; **Devolver para IA** a reativa. Respostas manuais pela instância WhatsApp também respeitam a pausa já existente. Mensagens recebidas durante a pausa continuam registradas.

Nenhuma leitura de comprovante confirma crédito bancário. Respostas da IA que mencionem preço em reais, disponibilidade específica ou pagamento confirmado são descartadas e substituídas por uma pergunta segura.

## Implantação e teste manual

1. Configure no servidor `GEMINI_API_KEY` e, opcionalmente, `GEMINI_MODEL` (padrão `gemini-2.5-flash`) e `GEMINI_TIMEOUT` (padrão 30 segundos). A chave permanece apenas no `.env` do backend. Configure `TATTOO_FILESYSTEM_DISK` e a Evolution como já fazia.
2. Execute `php artisan migrate --force` e `php artisan config:cache`.
3. Mantenha um worker da fila: `php artisan queue:work --tries=5 --timeout=120` (ou Horizon no ambiente Redis). Ajuste `DB_QUEUE_RETRY_AFTER=180` ou `REDIS_QUEUE_RETRY_AFTER=180`, sempre acima do timeout do worker. Reinicie workers existentes com `php artisan queue:restart`.
4. Na conta financeira padrão de recebimentos, preencha chave PIX e nome do favorecido. Ative a IA nas configurações da agenda da empresa.
5. Pelo WhatsApp, envie uma ideia em mensagens separadas, foto de referência e tamanho. Confira a conversa em **Atendimentos IA** e o pedido em **Orçamentos de tatuagem**. Crie e envie uma proposta humana com sinal. Responda `aceito`, confira os dados PIX, envie imagem/PDF do comprovante e faça a conferência no painel. Confirme o sinal e peça horários; selecione um dos horários exatos exibidos.

Decisões operacionais: escolher o modelo Gemini conforme cota e disponibilidade da conta Google; preencher PIX/favorecido; manter worker e armazenamento privado configurados; equipe conferir extrato bancário antes de confirmar qualquer sinal. Comprovante e resposta do provedor não são prova de liquidação.

Referências da API Gemini: [generateContent](https://ai.google.dev/gemini-api/docs/generate-content/text-generation), [saída estruturada](https://ai.google.dev/gemini-api/docs/generate-content/structured-output), [documentos PDF](https://ai.google.dev/gemini-api/docs/document-processing).
