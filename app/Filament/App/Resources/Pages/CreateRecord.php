<?php

namespace App\Filament\App\Resources\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use Filament\Resources\Pages\CreateRecord as FilamentCreateRecord;
use Illuminate\Validation\ValidationException;

class CreateRecord extends FilamentCreateRecord
{
    use NotifiesValidationErrors;

    public function create(bool $another = false): void
    {
        $this->hasNotifiedValidationError = false;

        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            throw $exception;
        }
    }
}
