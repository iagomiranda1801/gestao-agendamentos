<?php

namespace App\Console\Commands;

use App\Enums\WhatsAppOutboundKind;
use App\Models\TattooAiConversation;
use App\Models\WhatsAppBotConversation;
use App\Services\Tattoo\TattooStudioMenu;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CompanyDateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTattooQuoteFollowupsCommand extends Command
{
    protected $signature = 'tattoo:send-quote-followups';

    protected $description = 'Retoma orçamentos de tatuagem sem resposta após 96 horas';

    public function handle(EvolutionApiClient $evolution, WhatsAppOutboundGate $gate, WhatsAppHumanTakeover $takeover): int
    {
        $cutoff = now()->subHours(96);
        $companyEnabled = fn ($query) => $query->where('is_active', true)
            ->whereHas('schedulingSetting', fn ($settings) => $settings->where('tattoo_custom_flow_enabled', true));

        TattooAiConversation::query()->whereHas('company', $companyEnabled)
            ->where('menu_choice', 1)->whereNull('tattoo_request_id')->where('human_takeover', false)
            ->whereIn('status', ['collecting_information', 'awaiting_confirmation'])
            ->whereNull('quote_followup_sent_at')->where('last_bot_question_at', '<=', $cutoff)
            ->whereColumn('last_interaction_at', '<=', 'last_bot_question_at')
            ->with(['company.schedulingSetting', 'instance'])->orderBy('id')->limit(50)->get()
            ->each(function (TattooAiConversation $conversation) use ($evolution, $gate, $takeover): void {
                $this->send($conversation, $evolution, $gate, $takeover);
            });

        WhatsAppBotConversation::query()->whereHas('company', $companyEnabled)
            ->where('menu_choice', 1)->whereNull('finished_at')->whereNull('quote_followup_sent_at')
            ->where('last_bot_question_at', '<=', $cutoff)
            ->whereColumn('last_activity_at', '<=', 'last_bot_question_at')
            ->with(['company.schedulingSetting', 'instance'])->orderBy('id')->limit(50)->get()
            ->each(function (WhatsAppBotConversation $conversation) use ($evolution, $gate, $takeover): void {
                $this->send($conversation, $evolution, $gate, $takeover);
            });

        return self::SUCCESS;
    }

    private function send(TattooAiConversation|WhatsAppBotConversation $conversation, EvolutionApiClient $evolution,
        WhatsAppOutboundGate $gate, WhatsAppHumanTakeover $takeover): void
    {
        $company = $conversation->company;
        $instance = $conversation->instance?->instance_name;
        if (! $company || ! $instance || ! $company->is_active
            || ! $company->schedulingSetting?->tattoo_custom_flow_enabled) {
            return;
        }
        $hour = CompanyDateTime::nowLocal($company)->hour;
        if ($hour < 14 || $hour >= 22) {
            return;
        }
        $type = $conversation instanceof TattooAiConversation ? 'ai' : 'legacy';
        Cache::lock("wa:tattoo-followup:{$type}:{$conversation->getKey()}", 60)->block(5,
            function () use ($conversation, $company, $instance, $evolution, $gate, $takeover): void {
                $conversation->refresh();
                $lastInbound = $conversation instanceof TattooAiConversation
                    ? $conversation->last_interaction_at : $conversation->last_activity_at;
                if ($conversation->menu_choice !== 1 || $conversation->quote_followup_sent_at
                    || ! $conversation->last_bot_question_at || $conversation->last_bot_question_at->gt(now()->subHours(96))
                    || $lastInbound?->gt($conversation->last_bot_question_at)
                    || $takeover->isPaused($instance, $conversation->phone_normalized)
                    || ($conversation instanceof TattooAiConversation
                        ? ($conversation->human_takeover || $conversation->tattoo_request_id || ! in_array($conversation->status, ['collecting_information', 'awaiting_confirmation'], true))
                        : $conversation->finished_at !== null)) {
                    return;
                }
                if (! $gate->reserve($company, WhatsAppOutboundKind::AfterSales)->allowed) {
                    return;
                }
                // Claim before the external send: an uncertain provider failure must not duplicate a follow-up.
                $conversation->update(['quote_followup_sent_at' => now()]);
                try {
                    $evolution->sendText($instance, $conversation->phone_normalized, TattooStudioMenu::FOLLOWUP);
                    $takeover->rememberBotSend($instance, $conversation->phone_normalized, TattooStudioMenu::FOLLOWUP);
                    $gate->recordSuccess($company);
                } catch (Throwable $exception) {
                    $gate->recordFailure($company);
                    Log::warning('Tattoo quote follow-up delivery uncertain.', [
                        'conversation_type' => $conversation::class, 'conversation_id' => $conversation->getKey(),
                        'company_id' => $company->getKey(), 'error' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
