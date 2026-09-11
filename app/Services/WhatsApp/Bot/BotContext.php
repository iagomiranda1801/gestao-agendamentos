<?php

namespace App\Services\WhatsApp\Bot;

use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Models\WhatsAppBotConversation;

class BotContext
{
    public function __construct(
        public readonly Company $company,
        public readonly WhatsAppBotConversation $conversation,
        public readonly CompanySchedulingSetting $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return is_array($this->conversation->data) ? $this->conversation->data : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
