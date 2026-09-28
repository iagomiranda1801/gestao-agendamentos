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
            ->where('last_activity_at', '<', $threshold)
            ->update([
                'finished_at' => now(),
                'finished_reason' => 'expired',
            ]);

        TattooRequest::query()->where('status', 'collecting')
            ->where('created_at', '<', $threshold)
            ->update(['status' => 'cancelled']);

        $purged = WhatsAppBotConversation::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Bot WhatsApp: {$abandoned} conversa(s) expirada(s), {$purged} antiga(s) removida(s).");

        return self::SUCCESS;
    }
}
