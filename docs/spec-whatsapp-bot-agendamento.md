# Bot WhatsApp de agendamento (MVP)

## Objetivo

Permitir que o cliente faça um novo agendamento inteiramente pelo chat do WhatsApp da empresa, sem sair para o link público. Cancelar e remarcar continuam pelo link `public.appointment.manage`.

## Como funciona

Quando a Evolution API entrega uma mensagem recebida (`messages.upsert`, `fromMe = false`), o `EvolutionWebhookController` já registra o evento. A partir desta versão, também dispara o `HandleWhatsAppInboundMessageJob` que:

1. Resolve a `Company` pela `instance_name` do payload (via `CompanyWhatsAppInstance`).
2. Verifica os gates de habilitação (módulos + settings).
3. Chama `WhatsAppBookingBotService::handleIncoming(...)`, que resolve/cria uma conversa em `whatsapp_bot_conversations`, roteia para o step apropriado e devolve o texto de resposta.
4. Envia a resposta usando `EvolutionApiClient::sendText()` protegido pelo `WhatsAppOutboundGate` com o kind `BotReply`.

## Fluxo conversacional

Menus numerados, um passo por vez. Espelha o wizard do `BookingWizard` em `app/Livewire/PublicBooking`.

1. `greeting` — "1 - Agendar / 0 - Falar com atendente"
2. `choosing_service` — lista serviços com `Service::availableForOnlineBooking()`
3. `choosing_professional` — profissionais elegíveis + "0 - Sem preferência" quando `allow_no_professional_preference`
4. `choosing_date` — próximas 7 datas com pelo menos um slot, "9 - Mais datas"
5. `choosing_time` — 8 horários da data escolhida, "9 - Mais horários", "0 - Voltar"
6. `collecting_name` — se o telefone bate com um `Client` ativo, oferece confirmar o nome cadastrado
7. `collecting_email` — pulável, exceto se `require_email_for_online_booking`
8. `accepting_terms` — só se houver `privacy_notice` ou `booking_terms`
9. `confirming` — resumo + "1 - Confirmar / 2 - Cancelar"
10. `done` — chama `OnlineBookingService::create()`, envia código + link de gestão

Comandos globais aceitos em qualquer estado:

- `menu`, `oi`, `olá`, `início` → reseta e volta para `greeting`
- `sair`, `parar`, `cancelar tudo` → encerra a conversa com motivo `exit`

## Gates de habilitação

O job só responde quando **todos** verdadeiros:

- `Company::is_active`
- Módulo `whatsapp` habilitado (`CompanyModule::WhatsApp`)
- Módulo `scheduling` habilitado (`CompanyModule::Scheduling`)
- `CompanySchedulingSetting::public_booking_enabled`
- `CompanySchedulingSetting::whatsapp_bot_enabled`
- `instance_name` do payload existe em `CompanyWhatsAppInstance`

**Restaurantes / módulo Pedidos sem Agenda:** o job **não** chama o bot de agendamento. Se o cardápio online e o toggle `whatsapp_order_link_bot_enabled` estiverem ligados, responde só com o link `/pedir/{slug}` — no máximo uma vez por telefone/empresa/dia civil (fuso da empresa), e só se o texto parecer saudação ou pedido de cardápio. Ver `docs/spec-restaurante-pedidos-mvp.md` §7.

Grupos (`@g.us`), mensagens do próprio número (`fromMe`) e mensagens sem texto são ignoradas.

## Chamada ao `OnlineBookingService`

O bot passa:

- `honeypot = null`
- `formStartedAt = now - 30s` (passa a checagem anti-spam)
- `privacyAccepted = true` e `termsAccepted = true` (o bot já mostrou os textos e exigiu aceite explícito)
- `idempotencyUuid` guardado em `conversation.data.idempotency_uuid` — se a mesma conversa reprocessar, cai no idempotente do próprio service
- `clientPhone` = `conversation.phone_normalized`
- Cliente existente é reutilizado pelo `OnlineClientResolver` (match por telefone).

## Idempotência de mensagens

`whatsapp_bot_conversations.last_incoming_message_id` guarda o `data.key.id` da última mensagem processada. Se o webhook reentregar o mesmo ID (o que a Evolution faz com alguma frequência), o bot responde `null` e não reprocessa.

## Rate limit / outbound

O `WhatsAppOutboundKind::BotReply` foi adicionado com regra especial no `WhatsAppOutboundGate`:

- `bypassesDailyLimit = true` (bot é reativo, não conta para o teto diário)
- `bypassesCircuitBreaker = true`
- `usesSendInterval = false` (não espera intervalo de envio entre mensagens)

O lock `wa:bot:{company}:{phone}` no `WhatsAppBookingBotService` já impede processamento concorrente por telefone.

## Timeout / limpeza

- `expires_at` = `last_activity_at + 30 min`. Ao receber nova mensagem depois disso, uma nova conversa é iniciada com estado limpo.
- Command `whatsapp:cleanup-bot-conversations` (agendado hourly em `routes/console.php`) marca conversas inativas como `finished_reason = 'expired'` e apaga conversas finalizadas há mais de 7 dias.

## UI

- Toggle na `SchedulingSettingsPage` (seção "Bot de agendamento no WhatsApp") vinculado a `whatsapp_bot_enabled`.
- Read-only `WhatsAppBotConversationResource` no painel App (`Configurações → Bot WhatsApp`) para inspecionar conversas em andamento e histórico.

## Fora do escopo

- Cancelar/remarcar via chat — continua pelo link do token (`ManageAppointment`).
- Consultar meus agendamentos.
- Áudio, imagem, sticker, anexo (o bot responde "Envie apenas texto").
- Botões nativos do WhatsApp / listas.
- IA / NLP.
- Multi-idioma.

## Arquivos principais

- `app/Enums/WhatsAppBotConversationState.php`
- `app/Enums/WhatsAppOutboundKind.php` (novo case `BotReply`)
- `app/Models/WhatsAppBotConversation.php`
- `database/migrations/2026_09_11_100000_create_whatsapp_bot_conversations_table.php`
- `database/migrations/2026_09_11_100100_add_whatsapp_bot_enabled_to_company_scheduling_settings_table.php`
- `app/Services/WhatsApp/Bot/WhatsAppBookingBotService.php`
- `app/Services/WhatsApp/Bot/WhatsAppBookingBotMessageBuilder.php`
- `app/Services/WhatsApp/Bot/BotStep.php` (contract)
- `app/Services/WhatsApp/Bot/BotAction.php`
- `app/Services/WhatsApp/Bot/BotContext.php`
- `app/Services/WhatsApp/Bot/Steps/*.php`
- `app/Services/WhatsApp/EvolutionWebhookService.php` (dispatch inbound)
- `app/Jobs/HandleWhatsAppInboundMessageJob.php`
- `app/Console/Commands/CleanupWhatsAppBotConversationsCommand.php`
- `app/Filament/App/Resources/WhatsAppBotConversations/*`
- `tests/Feature/WhatsApp/BookingBot/*Test.php`
