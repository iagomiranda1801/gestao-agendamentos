<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait NotifiesValidationErrors
{
    protected bool $hasNotifiedValidationError = false;

    protected function onValidationError(ValidationException $exception): void
    {
        $this->hasNotifiedValidationError = true;

        Notification::make()
            ->danger()
            ->title('Não foi possível salvar')
            ->body($this->validationErrorSummary($exception))
            ->persistent()
            ->send();
    }

    protected function notifyValidationException(ValidationException $exception): void
    {
        if ($this->hasNotifiedValidationError) {
            return;
        }

        $this->onValidationError($exception);
    }

    protected function validationErrorSummary(ValidationException $exception): string
    {
        $messages = collect($exception->errors())
            ->flatten()
            ->map(fn (mixed $message): string => trim((string) $message))
            ->filter()
            ->unique()
            ->reject(fn (string $message): bool => str_starts_with($message, 'validation.'))
            ->values();

        if ($messages->isEmpty()) {
            return 'Revise os campos obrigatórios destacados e tente novamente.';
        }

        return $messages->implode("\n");
    }
}
