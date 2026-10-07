<?php

namespace App\Services\Tattoo;

use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Models\User;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TattooQuoteService
{
    public function create(TattooRequest $request, User $user, array $data): TattooQuote
    {
        if (in_array($request->status, ['collecting', 'cancelled', 'declined', 'booked'], true)) {
            throw ValidationException::withMessages(['status' => 'Esta solicitação não aceita novos orçamentos.']);
        }

        $data = validator($data, [
            'price_type' => ['required', 'in:fixed,range'],
            'amount_min' => ['required', 'numeric', 'min:0.01'],
            'amount_max' => ['nullable', 'required_if:price_type,range', 'numeric', 'gte:amount_min'],
            'sessions' => ['required', 'integer', 'min:1', 'max:20'],
            'minutes_per_session' => ['nullable', 'integer', 'min:15', 'max:480'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'conditions' => ['nullable', 'string', 'max:3000'],
        ])->validate();

        $message = $this->message($request, $data);

        return DB::transaction(function () use ($request, $user, $data, $message): TattooQuote {
            TattooRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $version = (int) $request->quotes()->lockForUpdate()->max('version') + 1;
            $quote = new TattooQuote(array_merge($data, [
                'created_by' => $user->getKey(),
                'version' => $version,
                'message_snapshot' => $message,
            ]));
            $quote->company_id = $request->company_id;
            $quote->tattoo_request_id = $request->getKey();
            $quote->save();
            $request->update(['status' => 'in_review']);

            return $quote;
        });
    }

    public function send(TattooQuote $quote): void
    {
        Cache::lock('tattoo-quote-send:'.$quote->getKey(), 60)->block(5, function () use ($quote): void {
            $quote = TattooQuote::query()->find($quote->getKey());
            if (! $quote) {
                return;
            }
            $request = $quote->request->loadMissing(['client', 'company']);
            if ($quote->sent_at !== null) {
                return;
            }
            if ((int) $request->quotes()->max('version') !== (int) $quote->version) {
                return;
            }
            $instance = CompanyWhatsAppInstance::query()->where('company_id', $request->company_id)->default()->first()?->instance_name
                ?? CompanyWhatsAppInstance::query()->where('company_id', $request->company_id)->first()?->instance_name;
            if (! $instance || ! $request->client->phone_normalized) {
                throw ValidationException::withMessages(['phone' => 'Configure o WhatsApp e o telefone do cliente antes de enviar.']);
            }
            app(EvolutionApiClient::class)->sendText($instance, $request->client->phone_normalized, $quote->message_snapshot);
            DB::transaction(function () use ($quote, $request): void {
                $quote->update(['sent_at' => now()]);
                $request->update(['status' => 'quote_sent']);
            });
        });
    }

    public function message(TattooRequest $request, array $data): string
    {
        $min = 'R$ '.number_format((float) $data['amount_min'], 2, ',', '.');
        $price = $data['price_type'] === 'range'
            ? $min.' a R$ '.number_format((float) $data['amount_max'], 2, ',', '.')
            : $min;
        $lines = [
            'Orçamento de tatuagem - '.$request->company->name,
            'Desenho: '.$request->description,
            'Local: '.$request->body_placement,
            'Valor: '.$price,
            'Sessões previstas: '.$data['sessions'],
        ];
        if (! empty($data['deposit_amount'])) {
            $lines[] = 'Sinal: R$ '.number_format((float) $data['deposit_amount'], 2, ',', '.');
        }
        if (! empty($data['valid_until'])) {
            $lines[] = 'Válido até: '.date('d/m/Y', strtotime($data['valid_until']));
        }
        if (! empty($data['conditions'])) {
            $lines[] = $data['conditions'];
        }
        $lines[] = 'Responda esta mensagem para combinar o agendamento.';

        return implode("\n", $lines);
    }
}
