<?php

namespace App\Filament\Admin\Pages;

use App\Models\EvolutionWebhookEvent;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class WebhookEvents extends Page
{
    protected static ?string $slug = 'operacao/webhooks';

    protected static ?string $navigationLabel = 'Webhooks recentes';

    protected static ?string $title = 'Webhooks recentes';

    protected static string|UnitEnum|null $navigationGroup = 'Operação';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.webhook-events';

    protected function getViewData(): array
    {
        return [
            'events' => Schema::hasTable('evolution_webhook_events')
                ? EvolutionWebhookEvent::query()->latest()->limit(100)->get()
                : collect(),
            'hasTable' => Schema::hasTable('evolution_webhook_events'),
        ];
    }
}
