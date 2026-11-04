<?php

namespace App\Services\Tattoo;

use App\Models\Appointment;
use App\Models\TattooAiConversation;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Support\CompanyDateTime;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Escolha de dia/horário pelo WhatsApp. Nada fica reservado antes do sinal:
 * o horário escolhido fica só em collected_data._schedule até o comprovante ser aprovado.
 */
class TattooBookingFlow
{
    public function __construct(protected TattooAISchedulingService $scheduling, protected EvolutionApiClient $evolution) {}

    /** @param Closure(): string $pix */
    public function handle(TattooAiConversation $conversation, TattooQuote $quote, string $text, string $normalized, Closure $pix): ?string
    {
        $request = $conversation->request;
        if (! $request || $request->appointment_id || $conversation->status === 'converted_to_appointment'
            || (! $quote->sent_at && ! $quote->accepted_at)) {
            return null;
        }
        $schedule = ($conversation->collected_data ?: [])['_schedule'] ?? null;
        $stage = $schedule['stage'] ?? null;
        $wantsDays = (bool) preg_match('/\b(outro dia|outros dias|outra data|outras datas|agendar|agendamento|marcar|horario|horarios|dias|datas)\b/u', $normalized);
        $paidStage = in_array($conversation->status, ['payment_confirmed', 'ready_to_schedule'], true);
        if (preg_match('/\b\d{4}-\d{2}-\d{2} \d{2}:\d{2}\b/', $text, $match)) {
            return $this->choose($conversation, $quote, $match[0], $pix);
        }
        if ($stage === 'day') {
            if (($date = $this->pickDay($conversation, $schedule['days'] ?? [], $normalized)) !== null) {
                return $this->showTimes($conversation, $date);
            }

            return $this->showDays($conversation, $wantsDays ? null : 'Não entendi o dia 😅 ');
        }
        if ($stage === 'time') {
            if (preg_match('/\boutr[oa]s? (dia|data)s?\b/u', $normalized)) {
                return $this->showDays($conversation);
            }
            if (($time = $this->pickTime($schedule['times'] ?? [], $normalized)) !== null) {
                return $this->choose($conversation, $quote, $schedule['date'].' '.$time, $pix);
            }
            if (($date = $this->pickDay($conversation, $schedule['days'] ?? [], $normalized, false)) !== null) {
                return $this->showTimes($conversation, $date);
            }

            return $wantsDays ? $this->showDays($conversation) : $this->showTimes($conversation, (string) $schedule['date'], 'Não entendi o horário 😅 ');
        }
        if ($wantsDays || ($stage === null && $paidStage)) {
            return $this->showDays($conversation);
        }

        return null;
    }

    public function showDays(TattooAiConversation $conversation, ?string $prefix = null): string
    {
        $days = $this->scheduling->days($conversation);
        if ($days === []) {
            $this->store($conversation, null);

            return 'No momento não achei horário livre na agenda. Vou conferir com a equipe e já te chamo.';
        }
        $this->store($conversation, ['stage' => 'day', 'days' => $days]);
        $lines = [];
        foreach ($days as $i => $date) {
            $lines[] = ($i + 1).'. '.$this->scheduling->label($conversation, $date);
        }

        return ($prefix ?? '')."Esses são os próximos dias com horário livre:\n".implode("\n", $lines)."\n\nResponde com o número do dia.";
    }

    protected function showTimes(TattooAiConversation $conversation, string $date, ?string $prefix = null): string
    {
        $times = $this->scheduling->times($conversation, $date);
        if ($times === []) {
            return $this->showDays($conversation, 'Esse dia não tem horário livre. ');
        }
        $days = (($conversation->collected_data ?: [])['_schedule']['days'] ?? null) ?: [];
        $this->store($conversation, ['stage' => 'time', 'date' => $date, 'times' => $times, 'days' => $days]);
        $lines = [];
        foreach ($times as $i => $time) {
            $lines[] = ($i + 1).'. '.$time;
        }

        return ($prefix ?? '').'Horários livres na '.$this->scheduling->label($conversation, $date).":\n".implode("\n", $lines)
            ."\n\nResponde com o número do horário ou *outro dia* pra ver outras datas.";
    }

    /** @param Closure(): string $pix */
    protected function choose(TattooAiConversation $conversation, TattooQuote $quote, string $slot, Closure $pix): string
    {
        if (! $this->scheduling->isFree($conversation, $slot)) {
            return $this->showDays($conversation, 'Esse horário não tá mais livre 😕 ');
        }
        if ($this->scheduling->paid($conversation)) {
            return $this->bookAndConfirm($conversation, $slot)
                ?? $this->showDays($conversation, 'Esse horário acabou de ser ocupado 😕 ');
        }
        if (! $quote->accepted_at) {
            $quote->update(['accepted_at' => now()]);
            $conversation->request->update(['status' => 'accepted']);
        }
        $this->store($conversation, ['stage' => 'chosen', 'slot' => $slot]);
        $when = $this->when($conversation, $slot);
        if ($conversation->status === 'receipt_received') {
            return "Anotado: {$when}. Assim que eu confirmar teu comprovante, o horário fica garantido.";
        }
        $conversation->update(['status' => 'waiting_payment_receipt']);

        return "Show! {$when} 🙌 O horário só fica reservado depois que eu receber o comprovante do sinal.\n\n".$pix();
    }

    /** Cria o agendamento confirmado; null quando o horário já foi ocupado. */
    public function bookAndConfirm(TattooAiConversation $conversation, string $slot): ?string
    {
        try {
            $appointment = $this->scheduling->book($conversation, $slot);
        } catch (ValidationException) {
            $this->store($conversation, null);

            return null;
        }
        $conversation->refresh();
        $quote = $this->scheduling->quote($conversation);
        $local = CompanyDateTime::utcToLocal($conversation->company, CarbonImmutable::parse($appointment->start_at));
        $deposit = (float) ($quote?->deposit_amount ?? 0);
        $this->notifyArtist($conversation, 'Agendamento confirmado: '.$this->clientName($conversation).' dia '.$local->format('d/m')
            .' às '.$local->format('H:i').($deposit > 0 ? ', sinal R$ '.number_format($deposit, 2, ',', '.').' pago' : '').' ✅');

        return 'Fechado! ✅ '.($deposit > 0 ? 'Sinal confirmado e h' : 'H').'orário garantido: '
            .$this->when($conversation, $local->format('Y-m-d H:i')).$this->withArtist($appointment).'. Qualquer coisa é só chamar aqui!';
    }

    /** Sinal aprovado: agenda o horário escolhido ou pede pra escolher o dia. */
    public function paymentConfirmed(TattooPaymentReceipt $receipt): ?string
    {
        return $this->confirmPaidDeposit($receipt->conversation);
    }

    public function confirmPaidDeposit(?TattooAiConversation $conversation): ?string
    {
        if (! $conversation || ! $conversation->request || $conversation->request->appointment_id) {
            return null;
        }
        $schedule = ($conversation->collected_data ?: [])['_schedule'] ?? null;
        if (($schedule['stage'] ?? null) === 'chosen' && ! empty($schedule['slot'])) {
            $when = $this->when($conversation, (string) $schedule['slot']);

            return $this->bookAndConfirm($conversation, (string) $schedule['slot'])
                ?? $this->showDays($conversation, "Recebi teu sinal ✅, mas o horário {$when} acabou de ser ocupado 😕 Escolhe outro dia que eu já garanto pra você, sem pagar de novo.\n\n");
        }

        return $this->showDays($conversation, "Sinal confirmado ✅ Agora é só escolher o dia.\n\n");
    }

    public function hasChosenSlot(TattooAiConversation $conversation): bool
    {
        return ((($conversation->collected_data ?: [])['_schedule']['stage'] ?? null) === 'chosen');
    }

    public function receiptProblem(TattooAiConversation $conversation, TattooPaymentReceipt $receipt): void
    {
        if ($receipt->fresh()?->artist_notified_at !== null) {
            return; // alertas já foram junto com o comprovante encaminhado
        }
        $warnings = $receipt->analysis['warnings'] ?? [];
        $this->notifyArtist($conversation, 'Comprovante com problema: '.$this->clientName($conversation)
            .($warnings !== [] ? ' — '.implode('; ', $warnings) : '').'. Confere no painel.');
    }

    public function sendToCustomer(TattooAiConversation $conversation, string $body, string $key): void
    {
        $message = $conversation->messages()->firstOrCreate(['provider_message_id' => $key],
            ['company_id' => $conversation->company_id, 'direction' => 'out', 'status' => 'pending', 'body' => mb_substr($body, 0, 4000)]);
        if ($message->status !== 'pending') {
            return;
        }
        try {
            $this->evolution->sendText((string) $conversation->instance?->instance_name, $conversation->phone_normalized, $body);
            $message->update(['status' => 'sent']);
        } catch (Throwable $exception) {
            $message->update(['status' => 'failed']);
            Log::warning('Tattoo booking: customer message failed.', ['conversation_id' => $conversation->id, 'error_type' => $exception::class]);
        }
    }

    public function notifyArtist(TattooAiConversation $conversation, string $message): void
    {
        $phone = PhoneNormalizer::normalize((string) $conversation->company->schedulingSetting?->tattoo_artist_notify_phone);
        if (! $phone || ! $conversation->instance) {
            return;
        }
        try {
            $this->evolution->sendText($conversation->instance->instance_name, (string) $phone, $message);
        } catch (Throwable $exception) {
            Log::warning('Tattoo booking: artist notification failed.', ['company_id' => $conversation->company_id, 'error_type' => $exception::class]);
        }
    }

    /** @param list<string> $days */
    public function pickDay(TattooAiConversation $conversation, array $days, string $normalized, bool $numbers = true): ?string
    {
        $normalized = trim($normalized);
        if ($numbers && preg_match('/^(?:dia\s*)?(\d{1,2})[.)!]?$/u', $normalized, $m) && isset($days[(int) $m[1] - 1])) {
            return $days[(int) $m[1] - 1];
        }
        $today = CompanyDateTime::nowLocal($conversation->company)->startOfDay();
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\b/', $normalized, $m) && checkdate((int) $m[2], (int) $m[1], (int) $today->year)) {
            $date = $today->setDate((int) $today->year, (int) $m[2], (int) $m[1]);

            return ($date->lt($today) ? $date->addYear() : $date)->format('Y-m-d');
        }
        if (preg_match('/\bdepois de amanha\b/u', $normalized)) {
            return $today->addDays(2)->format('Y-m-d');
        }
        if (preg_match('/\b(hoje|amanha)\b/u', $normalized, $m)) {
            return $today->addDays($m[1] === 'hoje' ? 0 : 1)->format('Y-m-d');
        }
        $map = ['domingo' => 0, 'dom' => 0, 'segunda' => 1, 'seg' => 1, 'terca' => 2, 'ter' => 2, 'quarta' => 3, 'qua' => 3,
            'quinta' => 4, 'qui' => 4, 'sexta' => 5, 'sex' => 5, 'sabado' => 6, 'sab' => 6];
        foreach ($map as $word => $weekday) {
            if (preg_match('/\b'.$word.'\b/u', $normalized)) {
                foreach ($days as $date) {
                    if (CarbonImmutable::parse($date)->dayOfWeek === $weekday) {
                        return $date;
                    }
                }
                for ($i = 0; $i < 7; $i++) {
                    if ($today->addDays($i)->dayOfWeek === $weekday) {
                        return $today->addDays($i)->format('Y-m-d');
                    }
                }
            }
        }

        return null;
    }

    /** @param list<string> $times */
    public function pickTime(array $times, string $normalized): ?string
    {
        $normalized = trim($normalized);
        if (preg_match('/^(\d{1,2})[.)!]?$/u', $normalized, $m) && isset($times[(int) $m[1] - 1])) {
            return $times[(int) $m[1] - 1];
        }
        if (preg_match('/\b(\d{1,2})(?::|h)(\d{2})?\b/u', $normalized, $m)) {
            $time = sprintf('%02d:%02d', (int) $m[1], (int) ($m[2] ?? 0));

            return in_array($time, $times, true) ? $time : null;
        }

        return null;
    }

    /** @param array<string, mixed>|null $schedule */
    protected function store(TattooAiConversation $conversation, ?array $schedule): void
    {
        $data = $conversation->collected_data ?: [];
        if ($schedule === null) {
            unset($data['_schedule']);
        } else {
            $data['_schedule'] = $schedule;
        }
        $conversation->update(['collected_data' => $data]);
    }

    protected function when(TattooAiConversation $conversation, string $slot): string
    {
        return $this->scheduling->label($conversation, substr($slot, 0, 10)).' às '.substr($slot, 11, 5);
    }

    protected function withArtist(Appointment $appointment): string
    {
        $name = trim((string) $appointment->professional?->name);

        return $name !== '' ? ' com '.explode(' ', $name)[0] : '';
    }

    protected function clientName(TattooAiConversation $conversation): string
    {
        return trim((string) ($conversation->request?->client?->name ?? ($conversation->collected_data['name'] ?? '')))
            ?: $conversation->phone_normalized;
    }
}
