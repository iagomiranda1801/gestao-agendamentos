<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class FilamentSelectTranslationTest extends TestCase
{
    public function test_select_no_options_message_is_translated_in_portuguese(): void
    {
        $this->app->setLocale('pt_BR');

        $this->assertSame(
            'Nenhuma opção disponível.',
            __('filament-forms::components.select.no_options_message'),
        );
    }
}
