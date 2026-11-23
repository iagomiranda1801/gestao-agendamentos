<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Select;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;

class AIModelSelect extends Select
{
    /** Load the remote catalog on opening the dropdown, never during page rendering. */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getOptionsForJs(): array
    {
        return $this->transformOptionsForJs($this->getSearchResults(''));
    }
}
