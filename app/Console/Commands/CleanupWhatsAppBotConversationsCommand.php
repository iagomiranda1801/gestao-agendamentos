<?php

namespace App\Console\Commands;

use App\Models\TattooRequest;
use App\Models\WhatsAppBotConversation;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use Illuminate\Console\Command;

class CleanupWhatsAppBotConversationsCommand extends Command
{
    protected $signature = 'whatsapp:cleanup-bot-conversations';

    protected $description = 'Encerra conversas do bot WhatsApp inativas e remove antigas.';

    public function handle(): int
    {
        $threshold = now()->subMinutes(WhatsAppBookingBotService::CONVERSATION_TTL_MINUTES);

        $abandoned = WhatsAppBotConversation::query()
            ->whereNull('finished_at')
            ->where(function ($query) use ($threshold): void {
                $query->where(function ($regular) use ($threshold): void {
                    $regular->where(function ($scope): void {
                        $scope->whereNull('menu_choice')->orWhere('menu_choice', '!=', 1);
                    })->where('last_activity_at', '<', $threshold);
                })->orWhere(function ($quote): void {
                    $quote->where('menu_choice', 1)->where('expires_at', '<', now());
                });
            })
            ->update([
                'finished_at' => now(),
                'finished_reason' => 'expired',
            ]);

        TattooRequest::query()->where('status', 'collecting')
            ->where('created_at', '<', $threshold)
            ->whereDoesntHave('conversation', fn ($query) => $query->where('menu_choice', 1)->whereNull('finished_at'))
            ->update(['status' => 'cancelled']);

        $purged = WhatsAppBotConversation::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Bot WhatsApp: {$abandoned} conversa(s) expirada(s), {$purged} antiga(s) removida(s).");

        return self::SUCCESS;
    }
}
