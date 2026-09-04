<?php

namespace App\Filament\App\Resources\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use Filament\Resources\Pages\EditRecord as FilamentEditRecord;
use Illuminate\Validation\ValidationException;

class EditRecord extends FilamentEditRecord
{
    use NotifiesValidationErrors;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->hasNotifiedValidationError = false;

        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            throw $exception;
        }
    }
}
