<?php

namespace App\Filament\App\Support;

use App\Models\Company;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class AppointmentSchedulingForm
{
    public static function timePicker(TimePicker $picker): TimePicker
    {
        $interval = self::slotIntervalMinutes();

        return $picker
            ->seconds(false)
            ->minutesStep($interval)
            ->helperText("Horários a cada {$interval} min.");
    }

    public static function notifyFailure(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Não foi possível agendar')
            ->body(collect($exception->errors())->flatten()->first() ?? 'Verifique os dados informados.')
            ->send();
    }

    /**
     * @throws ValidationException
     */
    public static function notifyAndRethrow(ValidationException $exception, ?string $statePath = null): never
    {
        self::notifyFailure($exception);

        if (blank($statePath)) {
            throw $exception;
        }

        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $mapped[$key] = $messages;

            if (! str_starts_with($key, $statePath.'.')) {
                $mapped["{$statePath}.{$key}"] = $messages;
            }
        }

        throw ValidationException::withMessages($mapped);
    }

    public static function slotIntervalMinutes(): int
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return 15;
        }

        return (int) app(CompanySchedulingSettingService::class)
            ->getOrCreate($company)
            ->slot_interval_minutes;
    }
}
