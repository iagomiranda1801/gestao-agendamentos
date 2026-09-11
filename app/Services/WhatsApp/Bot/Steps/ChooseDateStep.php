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

class ChooseDateStep implements BotStep
{
    private const PAGE_SIZE = 7;

    public function __construct(
        protected OnlineBookingCatalogService $catalog,
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        [$dates, $hasMore] = $this->availableDates($context);

        if ($dates === []) {
            return $this->messages->noDatesAvailable();
        }

        return $this->messages->dateMenu($dates, $hasMore);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);
        [$dates, $hasMore] = $this->availableDates($context);

        if ($dates === []) {
            return BotAction::stay($this->messages->noDatesAvailable());
        }

        if (! ctype_digit($trimmed)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        $number = (int) $trimmed;

        if ($number === 9 && $hasMore) {
            $nextPage = (int) ($context->get('date_page', 0)) + 1;

            return BotAction::goTo(WhatsAppBotConversationState::ChoosingDate, [
                'date_page' => $nextPage,
            ]);
        }

        $index = $number - 1;

        if ($index < 0 || $index >= count($dates)) {
            return BotAction::stay($this->messages->invalidOption());
        }

        return BotAction::goTo(WhatsAppBotConversationState::ChoosingTime, [
            'selected_date' => $dates[$index]['value'],
            'selected_date_label' => $dates[$index]['label'],
            'date_page' => 0,
            'time_page' => 0,
        ]);
    }

    /**
     * @return array{0: array<int, array{label: string, value: string}>, 1: bool}
     */
    protected function availableDates(BotContext $context): array
    {
        $service = $this->service($context);

        if ($service === null) {
            return [[], false];
        }

        $professionalId = $context->get('professional_id');
        $professionalId = is_int($professionalId) ? $professionalId : null;

        $all = $this->catalog->getAvailableDates(
            $context->company,
            $service,
            $professionalId,
        );

        $page = max(0, (int) $context->get('date_page', 0));
        $offset = $page * self::PAGE_SIZE;
        $slice = $all->slice($offset, self::PAGE_SIZE)->values();
        $hasMore = $all->count() > $offset + $slice->count();

        $dates = $slice
            ->map(fn (CarbonImmutable $date): array => [
                'label' => $this->formatDate($date),
                'value' => $date->format('Y-m-d'),
            ])
            ->all();

        return [$dates, $hasMore];
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

    protected function formatDate(CarbonImmutable $date): string
    {
        $weekdays = [
            0 => 'domingo',
            1 => 'segunda',
            2 => 'terça',
            3 => 'quarta',
            4 => 'quinta',
            5 => 'sexta',
            6 => 'sábado',
        ];

        $weekday = $weekdays[(int) $date->format('w')] ?? '';
        $suffix = $weekday !== '' ? " ({$weekday})" : '';

        return $date->format('d/m/Y').$suffix;
    }
}
