<?php

namespace App\Services\Tattoo;

use App\Models\Client;
use App\Models\TattooAiConversation;
use App\Services\Client\ClientService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TattooConversationTakeoverService
{
    public function __construct(protected ClientService $clients) {}

    public function takeOver(TattooAiConversation $conversation, string $name): Client
    {
        $name = Str::squish($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Informe o nome do cliente (2 a 255 caracteres).']);
        }

        return DB::transaction(function () use ($conversation, $name): Client {
            $conversation = TattooAiConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $phones = PhoneNormalizer::candidates($conversation->phone_normalized);
            if ($phones === []) {
                throw ValidationException::withMessages(['phone' => 'A conversa não possui um celular válido.']);
            }

            $client = Client::query()->where('company_id', $conversation->company_id)
                ->whereIn('phone_normalized', $phones)->first();

            if ($client) {
                if ($client->name !== $name) {
                    $client->update(['name' => $name]);
                }
            } else {
                $client = $this->clients->create($conversation->company, [
                    'name' => $name,
                    'phone' => $conversation->phone_normalized,
                    'is_active' => true,
                    'source' => 'whatsapp',
                    'whatsapp_marketing_opt_in' => false,
                ]);
            }

            $conversation->update([
                'client_id' => $client->id,
                'collected_data' => array_merge($conversation->collected_data ?: [], ['name' => $name]),
                'human_takeover' => true,
                'status' => 'human_takeover',
            ]);

            return $client;
        });
    }
}
