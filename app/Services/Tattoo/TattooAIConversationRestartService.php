<?php

namespace App\Services\Tattoo;

use App\Models\TattooAiConversation;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TattooAIConversationRestartService
{
    public function __construct(protected WhatsAppHumanTakeover $takeover) {}

    public function restart(TattooAiConversation $conversation): void
    {
        abort_unless($conversation->company->isTattooStudio(), 403);

        Cache::lock("wa:ai:{$conversation->company_id}:{$conversation->phone_normalized}", 150)
            ->block(10, fn () => $this->restartUnderLock($conversation));
    }

    /** Use apenas enquanto a trava wa:ai da conversa já estiver ativa. */
    public function restartUnderLock(TattooAiConversation $conversation): void
    {
        $files = DB::transaction(function () use ($conversation): array {
            $conversation = TattooAiConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $files = $conversation->messages()->whereNotNull('media_path')->get(['id', 'media_disk', 'media_path']);
            $lastMessageId = $conversation->messages()->max('id') ?? 0;

            $conversation->messages()->whereIn('status', ['pending', 'sending'])->update(['status' => 'suppressed']);
            $conversation->messages()->whereNotNull('media_path')->update(['media_disk' => null, 'media_path' => null]);
            $conversation->update([
                'tattoo_request_id' => null,
                'professional_id' => null,
                'human_takeover' => false,
                'status' => 'collecting_information',
                'collected_data' => null,
                'summary' => null,
                'last_interaction_at' => null,
                'context_start_message_id' => $lastMessageId,
            ]);

            return $files->all();
        });

        foreach ($files as $file) {
            Storage::disk($file->media_disk)->delete($file->media_path);
        }
        $this->takeover->resume($conversation->instance->instance_name, $conversation->phone_normalized);
    }
}
