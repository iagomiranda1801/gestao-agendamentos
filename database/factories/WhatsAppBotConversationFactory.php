<?php

namespace Database\Factories;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Company;
use App\Models\WhatsAppBotConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppBotConversation>
 */
class WhatsAppBotConversationFactory extends Factory
{
    protected $model = WhatsAppBotConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '5511999'.random_int(100000, 999999);

        return [
            'company_id' => Company::factory(),
            'company_whatsapp_instance_id' => null,
            'appointment_id' => null,
            'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net',
            'state' => WhatsAppBotConversationState::Greeting,
            'data' => [],
            'last_incoming_message_id' => null,
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes): array => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function inState(WhatsAppBotConversationState $state): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => $state,
        ]);
    }
}
