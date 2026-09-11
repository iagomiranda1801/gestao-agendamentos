<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Service;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;

class ChooseProfessionalStep implements BotStep
{
    public function __construct(
        protected OnlineBookingCatalogService $catalog,
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        $service = $this->service($context);

        if ($service === null) {
            return $this->messages->invalidOption();
        }

        $professionals = $this->catalog->getEligibleProfessionals($context->company, $service);
        $allowNoPreference = (bool) $context->settings->allow_no_professional_preference;

        if ($professionals->isEmpty()) {
            return "Nenhum profissional disponível para este serviço. Envie *menu* para escolher outro serviço.";
        }

        if (! (bool) $context->settings->allow_professional_selection) {
            return "Vamos alocar um profissional disponível. Digite *1* para continuar.";
        }

        return $this->messages->professionalMenu($professionals, $allowNoPreference);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);
        $service = $this->service($context);

        if ($service === null) {
            return BotAction::goTo(WhatsAppBotConversationState::ChoosingService);
        }

        $professionals = $this->catalog->getEligibleProfessionals($context->company, $service);
        $allowNoPreference = (bool) $context->settings->allow_no_professional_preference;

        if ($professionals->isEmpty()) {
            return BotAction::stay($this->messages->invalidOption());
        }

        if (! (bool) $context->settings->allow_professional_selection) {
            if ($trimmed !== '1') {
                return BotAction::stay($this->messages->invalidOption());
            }

            return BotAction::goTo(WhatsAppBotConversationState::ChoosingDate, [
                'professional_id' => null,
                'professional_name' => 'Sem preferência',
            ]);
        }

        if ($trimmed === '0' && $allowNoPreference) {
            return BotAction::goTo(WhatsAppBotConversationState::ChoosingDate, [
                'professional_id' => null,
                'professional_name' => 'Sem preferência',
            ]);
        }

        if (! ctype_digit($trimmed)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $index = (int) $trimmed - 1;

        if ($index < 0 || $index >= $professionals->count()) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $professional = $professionals[$index];

        return BotAction::goTo(WhatsAppBotConversationState::ChoosingDate, [
            'professional_id' => (int) $professional->getKey(),
            'professional_name' => (string) $professional->name,
        ]);
    }

    protected function service(BotContext $context): ?Service
    {
        $id = (int) ($context->get('service_id') ?? 0);

        if ($id <= 0) {
            return null;
        }

        return Service::query()
            ->where('company_id', $context->company->getKey())
            ->whereKey($id)
            ->first();
    }
}
