<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Client;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;
use App\Support\PublicBookingTextSanitizer;

class CollectNameStep implements BotStep
{
    public function __construct(
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        $existing = $this->existingClient($context);

        if ($existing !== null && filled($existing->name)) {
            return "Vou usar o cadastro que temos aí: *{$existing->name}*. Está correto?\n\n"
                ."1 - Sim, é meu nome\n"
                ."2 - Não, quero atualizar";
        }

        return $this->messages->askName();
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);
        $existing = $this->existingClient($context);

        if ($existing !== null && filled($existing->name)) {
            if ($trimmed === '1') {
                return $this->advance($context, (string) $existing->name);
            }

            if ($trimmed === '2') {
                return BotAction::stay($this->messages->askName());
            }

            return BotAction::stay($this->messages->invalidOption());
        }

        $name = PublicBookingTextSanitizer::clientName($trimmed);

        if (blank($name) || mb_strlen((string) $name) < 3) {
            return BotAction::stay("Informe o *nome completo* (mínimo 3 caracteres).");
        }

        return $this->advance($context, (string) $name);
    }

    protected function advance(BotContext $context, string $name): BotAction
    {
        $next = (bool) $context->settings->require_email_for_online_booking
            ? WhatsAppBotConversationState::CollectingEmail
            : $this->stateAfterEmail($context);

        return BotAction::goTo($next, [
            'client_name' => $name,
        ]);
    }

    protected function stateAfterEmail(BotContext $context): WhatsAppBotConversationState
    {
        return $this->needsAcceptance($context)
            ? WhatsAppBotConversationState::AcceptingTerms
            : WhatsAppBotConversationState::Confirming;
    }

    protected function needsAcceptance(BotContext $context): bool
    {
        return filled($context->settings->privacy_notice) || filled($context->settings->booking_terms);
    }

    protected function existingClient(BotContext $context): ?Client
    {
        return Client::query()
            ->where('company_id', $context->company->getKey())
            ->where('phone_normalized', $context->conversation->phone_normalized)
            ->where('is_active', true)
            ->first();
    }
}
