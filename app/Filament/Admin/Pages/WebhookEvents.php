<?php

namespace App\Filament\Admin\Pages;

use App\Models\EvolutionWebhookEvent;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function direction(EvolutionWebhookEvent $event): string
    {
        $payload = $event->payload ?: [];
        $fromMe = Arr::get($payload, 'data.key.fromMe')
            ?? Arr::get($payload, 'data.0.key.fromMe')
            ?? Arr::get($payload, 'key.fromMe');

        return match ($fromMe) {
            true, 1, '1', 'true' => 'Empresa → cliente',
            false, 0, '0', 'false' => 'Cliente → empresa',
            default => 'Não informada',
        };
    }

    public function contact(EvolutionWebhookEvent $event): string
    {
        return explode('@', (string) ($event->remote_jid ?: '—'))[0];
    }

    public function messagePreview(EvolutionWebhookEvent $event): string
    {
        $payload = $event->payload ?: [];
        foreach ([
            'data.message.conversation', 'data.0.message.conversation',
            'data.message.extendedTextMessage.text', 'data.0.message.extendedTextMessage.text',
            'data.message.imageMessage.caption', 'data.0.message.imageMessage.caption',
            'data.text', 'message.conversation',
        ] as $path) {
            $value = Arr::get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 100);
            }
        }

        return '—';
    }
}
