<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;

class AcceptTermsStep implements BotStep
{
    public function __construct(
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        $privacy = (string) $context->settings->privacy_notice;
        $terms = $context->settings->booking_terms;

        return $this->messages->acceptTerms($privacy, filled($terms) ? (string) $terms : null);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);

        if ($trimmed === '1') {
            return BotAction::goTo(WhatsAppBotConversationState::Confirming, [
                'terms_accepted' => true,
            ]);
        }

        if ($trimmed === '0') {
            return BotAction::abandon($this->messages->cancelled(), 'declined_terms');
        }

        return BotAction::stay($this->messages->invalidOption());
    }
}
