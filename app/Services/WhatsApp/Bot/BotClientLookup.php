<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Client;
use App\Support\PhoneNormalizer;

class BotClientLookup
{
    public function find(BotContext $context): ?Client
    {
        $candidates = PhoneNormalizer::candidates($context->conversation->phone_normalized);

        if ($candidates === []) {
            return null;
        }

        return Client::query()
            ->where('company_id', $context->company->getKey())
            ->where('is_active', true)
            ->whereIn('phone_normalized', $candidates)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function advanceAfterIdentity(
        BotContext $context,
        string $name,
        ?string $email = null,
        ?Client $client = null,
        array $extra = [],
    ): BotAction {
        $data = array_replace($extra, [
            'client_name' => $name,
            'client_email' => $email ?: $client?->email,
            'client_id' => $client?->getKey(),
            'client_phone' => $client?->phone_normalized ?: $context->conversation->phone_normalized,
        ]);

        $needsEmail = (bool) $context->settings->require_email_for_online_booking
            && blank($data['client_email']);

        if ($needsEmail) {
            return BotAction::goTo(WhatsAppBotConversationState::CollectingEmail, $data);
        }

        if (filled($context->settings->privacy_notice) || filled($context->settings->booking_terms)) {
            return BotAction::goTo(WhatsAppBotConversationState::AcceptingTerms, $data);
        }

        return BotAction::goTo(WhatsAppBotConversationState::Confirming, $data);
    }
}
