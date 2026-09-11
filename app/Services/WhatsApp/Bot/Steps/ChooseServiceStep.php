<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;

class ChooseServiceStep implements BotStep
{
    public function __construct(
        protected OnlineBookingCatalogService $catalog,
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        $services = $this->catalog->getEligibleServices($context->company);

        if ($services->isEmpty()) {
            return "No momento não temos serviços disponíveis para agendamento online. Envie *0* para falar com um atendente.";
        }

        return $this->messages->serviceMenu(
            $services,
            (bool) $context->settings->show_service_price,
            (bool) $context->settings->show_service_duration,
        );
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);

        if ($trimmed === '0') {
            return BotAction::abandon($this->messages->handoff(), 'handoff');
        }

        $services = $this->catalog->getEligibleServices($context->company);

        if ($services->isEmpty()) {
            return BotAction::abandon($this->messages->handoff(), 'no_services');
        }

        if (! ctype_digit($trimmed)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $index = (int) $trimmed - 1;

        if ($index < 0 || $index >= $services->count()) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $service = $services[$index];

        return BotAction::goTo(WhatsAppBotConversationState::ChoosingProfessional, [
            'service_id' => (int) $service->getKey(),
            'service_name' => (string) $service->name,
        ]);
    }
}
