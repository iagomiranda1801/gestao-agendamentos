<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;

class CollectEmailStep implements BotStep
{
    public function __construct(
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        return $this->messages->askEmail((bool) $context->settings->require_email_for_online_booking);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);
        $required = (bool) $context->settings->require_email_for_online_booking;

        if (! $required && mb_strtolower($trimmed) === 'pular') {
            return $this->advance($context, null);
        }

        if ($trimmed === '') {
            return BotAction::stay($this->messages->askEmail($required));
        }

        if (! filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return BotAction::stay("E-mail inválido. Envie um e-mail no formato *nome@dominio.com*".
                ($required ? '.' : ' ou digite *pular*.'));
        }

        return $this->advance($context, strtolower($trimmed));
    }

    protected function advance(BotContext $context, ?string $email): BotAction
    {
        $next = $this->needsAcceptance($context)
            ? WhatsAppBotConversationState::AcceptingTerms
            : WhatsAppBotConversationState::Confirming;

        return BotAction::goTo($next, [
            'client_email' => $email,
        ]);
    }

    protected function needsAcceptance(BotContext $context): bool
    {
        return filled($context->settings->privacy_notice) || filled($context->settings->booking_terms);
    }
}
