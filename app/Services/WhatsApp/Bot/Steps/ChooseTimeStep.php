<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Service;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;
use Carbon\CarbonImmutable;

class ChooseTimeStep implements BotStep
{
    private const PAGE_SIZE = 8;

    public function __construct(
        protected OnlineBookingCatalogService $catalog,
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        [$slots, $hasMore] = $this->availableSlots($context);

        if ($slots === []) {
            return $this->messages->noSlotsAvailable();
        }

        return $this->messages->timeMenu($slots, $hasMore);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);

        if ($trimmed === '0') {
            return BotAction::goTo(WhatsAppBotConversationState::ChoosingDate, [
                'selected_date' => null,
                'selected_date_label' => null,
                'time_page' => 0,
            ]);
        }

        [$slots, $hasMore] = $this->availableSlots($context);

        if ($slots === []) {
            return BotAction::stay($this->messages->noSlotsAvailable());
        }

        if (! ctype_digit($trimmed)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $number = (int) $trimmed;

        if ($number === 9 && $hasMore) {
            $nextPage = (int) ($context->get('time_page', 0)) + 1;

            return BotAction::goTo(WhatsAppBotConversationState::ChoosingTime, [
                'time_page' => $nextPage,
            ]);
        }

        $index = $number - 1;

        if ($index < 0 || $index >= count($slots)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        return BotAction::goTo(WhatsAppBotConversationState::CollectingName, [
            'selected_slot' => $slots[$index]['value'],
            'selected_slot_label' => $slots[$index]['label'],
        ]);
    }

    /**
     * @return array{0: array<int, array{label: string, value: string}>, 1: bool}
     */
    protected function availableSlots(BotContext $context): array
    {
        $service = $this->service($context);
        $selectedDate = $context->get('selected_date');

        if ($service === null || ! is_string($selectedDate) || $selectedDate === '') {
            return [[], false];
        }

        try {
            $localDate = CarbonImmutable::createFromFormat('Y-m-d', $selectedDate);
        } catch (\Throwable) {
            return [[], false];
        }

        if (! $localDate instanceof CarbonImmutable) {
            return [[], false];
        }

        $professionalId = $context->get('professional_id');
        $professionalId = is_int($professionalId) ? $professionalId : null;

        $all = $this->catalog->getAvailableSlots(
            $context->company,
            $service,
            $professionalId,
            $localDate->startOfDay(),
        );

        $page = max(0, (int) $context->get('time_page', 0));
        $offset = $page * self::PAGE_SIZE;
        $slice = $all->slice($offset, self::PAGE_SIZE)->values();
        $hasMore = $all->count() > $offset + $slice->count();

        $slots = $slice
            ->map(fn (CarbonImmutable $slot): array => [
                'label' => $slot->format('H:i'),
                'value' => $slot->format('Y-m-d H:i'),
            ])
            ->all();

        return [$slots, $hasMore];
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
