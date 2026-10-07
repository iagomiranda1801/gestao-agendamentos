<?php

namespace App\Services\Tattoo;

use App\Models\TattooAiConversation;
use App\Models\TattooRequest;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AvailabilityService;
use App\Support\CompanyDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TattooAISchedulingService
{
    public function __construct(protected AvailabilityService $availability, protected AppointmentService $appointments) {}

    /** @return list<string> */
    public function slots(TattooAiConversation $conversation): array
    {
        $request = $conversation->request;
        $quote = $request?->quotes()->latest('version')->first();
        if (! $request?->professional_id || ! $quote || ! $this->paid($conversation)) {
            return [];
        }
        $company = $conversation->company;
        $professional = $request->professional;
        $duration = (int) ($quote->minutes_per_session ?: 60);
        $interval = (int) $company->schedulingSetting?->slot_interval_minutes ?: 15;
        $today = CompanyDateTime::nowLocal($company);
        $slots = [];
        for ($day = 0; $day < 14 && count($slots) < 5; $day++) {
            $date = $today->addDays($day)->format('Y-m-d');
            for ($minute = 8 * 60; $minute <= 20 * 60 && count($slots) < 5; $minute += $interval) {
                $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
                $start = CompanyDateTime::parseLocal($company, $date, $time);
                if ($this->availability->assertAvailable($company, $professional, null, $start, $duration, 0, 0)->available) {
                    $slots[] = $date.' '.$time;
                }
            }
        }

        return $slots;
    }

    public function book(TattooAiConversation $conversation, string $selected): void
    {
        $request = $conversation->request;
        $quote = $request?->quotes()->latest('version')->first();
        if (! $request || ! $quote || ! $request->professional_id || ! $quote->creator || ! $this->paid($conversation)
            || $request->appointment_id || ! in_array($selected, $this->slots($conversation), true)) {
            throw ValidationException::withMessages(['slot' => 'Horário indisponível. Consulte os horários novamente.']);
        }
        DB::transaction(function () use ($conversation, $request, $quote, $selected): void {
            $locked = TattooRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->appointment_id) {
                throw ValidationException::withMessages(['slot' => 'Este pedido já foi agendado.']);
            }
            $appointment = $this->appointments->createInternalAppointment(
                $conversation->company, $quote->creator, $request->client, $request->professional, null,
                CompanyDateTime::parseLocal($conversation->company, substr($selected, 0, 10), substr($selected, 11, 5)),
                ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => $quote->minutes_per_session ?: 60,
                    'internal_notes' => 'Pedido de tatuagem #'.$request->id.': '.$request->description,
                    'send_whatsapp_confirmation' => false, 'reference_key' => 'tattoo-ai-'.$request->id],
            );
            $locked->update(['appointment_id' => $appointment->id, 'status' => 'booked']);
            $conversation->update(['status' => 'converted_to_appointment']);
        });
    }

    protected function paid(TattooAiConversation $conversation): bool
    {
        $quote = $conversation->request?->quotes()->latest('version')->first();
        if ($quote?->accepted_at && (float) $quote->deposit_amount <= 0) {
            return true;
        }

        return $conversation->receipts()->where('payment_status', 'confirmed')->exists();
    }
}
