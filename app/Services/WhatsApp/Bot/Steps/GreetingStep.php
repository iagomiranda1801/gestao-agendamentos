<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;

class GreetingStep implements BotStep
{
    public function __construct(
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        return $this->messages->greeting($context->company);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);

        if ($trimmed === '1') {
            return BotAction::goTo(WhatsAppBotConversationState::ChoosingService);
        }

        if ($trimmed === '0') {
            return BotAction::abandon($this->messages->handoff(), 'handoff');
        }

        return BotAction::stay($this->messages->invalidOption());
    }
}
