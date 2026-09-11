<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\DataTransferObjects\PublicBooking\OnlineBookingData;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfirmStep implements BotStep
{
    public function __construct(
        protected OnlineBookingService $bookingService,
        protected WhatsAppBookingBotMessageBuilder $messages,
    ) {}

    public function prompt(BotContext $context): string
    {
        return $this->messages->confirmation([
            'service' => (string) $context->get('service_name', ''),
            'professional' => (string) $context->get('professional_name', 'Sem preferência'),
            'date' => (string) $context->get('selected_date_label', ''),
            'time' => (string) $context->get('selected_slot_label', ''),
            'name' => (string) $context->get('client_name', ''),
            'email' => $context->get('client_email'),
        ]);
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);

        if ($trimmed === '2') {
            return BotAction::abandon($this->messages->cancelled(), 'cancelled_by_client');
        }

        if ($trimmed !== '1') {
            return BotAction::stay($this->messages->invalidOption());
        }

        return $this->createAppointment($context);
    }

    protected function createAppointment(BotContext $context): BotAction
    {
        $serviceId = (int) ($context->get('service_id') ?? 0);
        $professionalId = $context->get('professional_id');
        $professionalId = is_int($professionalId) ? $professionalId : null;
        $date = (string) $context->get('selected_date', '');
        $slot = (string) $context->get('selected_slot', '');
        $name = (string) $context->get('client_name', '');
        $email = $context->get('client_email');
        $email = is_string($email) && $email !== '' ? $email : null;

        if ($serviceId <= 0 || $date === '' || $slot === '' || $name === '') {
            return BotAction::abandon(
                "Não consegui recuperar seus dados. Envie *menu* para começar de novo.",
                'invalid_state',
            );
        }

        try {
            $localStart = CarbonImmutable::createFromFormat('Y-m-d H:i', $slot);
        } catch (\Throwable) {
            $localStart = null;
        }

        if (! $localStart instanceof CarbonImmutable) {
            return BotAction::abandon(
                "Não consegui interpretar o horário escolhido. Envie *menu* para tentar de novo.",
                'invalid_slot',
            );
        }

        $data = new OnlineBookingData(
            company: $context->company,
            serviceId: $serviceId,
            professionalId: $professionalId,
            localStart: $localStart,
            clientName: $name,
            clientPhone: $context->conversation->phone_normalized,
            clientEmail: $email,
            notes: null,
            idempotencyUuid: $this->idempotencyUuid($context),
            privacyAccepted: true,
            termsAccepted: true,
            honeypot: null,
            formStartedAt: CarbonImmutable::now()->subSeconds(30),
            clientDocument: null,
        );

        try {
            $result = $this->bookingService->create($data);
        } catch (ValidationException $exception) {
            Log::info('WhatsApp bot booking rejected.', [
                'company_id' => $context->company->getKey(),
                'conversation_id' => $context->conversation->getKey(),
                'errors' => $exception->errors(),
            ]);

            $message = $this->formatValidationErrors($exception);

            return BotAction::abandon(
                "Não consegui concluir o agendamento:\n{$message}\n\nEnvie *menu* para tentar outro horário.",
                'validation_failed',
            );
        }

        return BotAction::finish(
            closingMessage: $this->messages->success($result->appointment, $result->manageUrl),
            appointmentId: (int) $result->appointment->getKey(),
        );
    }

    protected function idempotencyUuid(BotContext $context): string
    {
        $stored = $context->get('idempotency_uuid');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return (string) Str::uuid();
    }

    protected function formatValidationErrors(ValidationException $exception): string
    {
        $lines = [];

        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = '• '.$message;
            }
        }

        return $lines === [] ? '• Erro desconhecido.' : implode("\n", $lines);
    }
}
