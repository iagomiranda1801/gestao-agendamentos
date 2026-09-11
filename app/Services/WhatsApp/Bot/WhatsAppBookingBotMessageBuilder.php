<?php

namespace App\Services\WhatsApp\Bot;

use App\Models\Appointment;
use App\Models\Company;
use App\Services\Company\CompanySubscriptionService;
use Illuminate\Support\Collection;

class WhatsAppBookingBotMessageBuilder
{
    public function greeting(Company $company): string
    {
        $name = $this->companyName($company);

        return "Olá! Você está falando com o atendimento de *{$name}*.\n\n"
            ."O que deseja fazer?\n"
            ."1 - Agendar um horário\n"
            ."0 - Falar com um atendente\n\n"
            ."Digite o número da opção. Envie *menu* a qualquer momento para reiniciar.";
    }

    /**
     * @param  Collection<int, \App\Models\Service>  $services
     */
    public function serviceMenu(Collection $services, bool $showPrice, bool $showDuration): string
    {
        $lines = ["Escolha o *serviço* digitando o número:"];

        foreach ($services as $index => $service) {
            $extras = [];

            if ($showDuration && (int) $service->duration_minutes > 0) {
                $extras[] = (int) $service->duration_minutes.' min';
            }

            if ($showPrice && $service->price_cents !== null) {
                $extras[] = app(CompanySubscriptionService::class)->formatReais((int) $service->price_cents);
            }

            $suffix = $extras !== [] ? ' — '.implode(' · ', $extras) : '';
            $number = $index + 1;
            $lines[] = "{$number} - {$service->name}{$suffix}";
        }

        $lines[] = '';
        $lines[] = 'Envie *menu* para reiniciar.';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, \App\Models\Professional>  $professionals
     */
    public function professionalMenu(Collection $professionals, bool $allowNoPreference): string
    {
        $lines = ["Escolha o *profissional* digitando o número:"];

        foreach ($professionals as $index => $professional) {
            $number = $index + 1;
            $lines[] = "{$number} - {$professional->name}";
        }

        if ($allowNoPreference) {
            $lines[] = '0 - Sem preferência';
        }

        $lines[] = '';
        $lines[] = 'Envie *menu* para reiniciar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $dates
     */
    public function dateMenu(array $dates, bool $hasMore): string
    {
        $lines = ["Escolha a *data* digitando o número:"];

        foreach ($dates as $index => $date) {
            $number = $index + 1;
            $lines[] = "{$number} - {$date['label']}";
        }

        if ($hasMore) {
            $lines[] = '9 - Mais datas';
        }

        $lines[] = '';
        $lines[] = 'Envie *menu* para reiniciar.';

        return implode("\n", $lines);
    }

    public function noDatesAvailable(): string
    {
        return "Sem horários disponíveis nos próximos dias.\n\n"
            ."Envie *menu* para escolher outro serviço ou *0* para falar com um atendente.";
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $slots
     */
    public function timeMenu(array $slots, bool $hasMore): string
    {
        $lines = ["Escolha o *horário* digitando o número:"];

        foreach ($slots as $index => $slot) {
            $number = $index + 1;
            $lines[] = "{$number} - {$slot['label']}";
        }

        if ($hasMore) {
            $lines[] = '9 - Mais horários';
        }

        $lines[] = '0 - Voltar para outra data';
        $lines[] = '';
        $lines[] = 'Envie *menu* para reiniciar.';

        return implode("\n", $lines);
    }

    public function noSlotsAvailable(): string
    {
        return "Sem horários disponíveis nesta data.\n\nDigite *0* para escolher outra data ou *menu* para reiniciar.";
    }

    public function askName(): string
    {
        return "Qual é o seu *nome completo*?";
    }

    public function askEmail(bool $required): string
    {
        if ($required) {
            return "Informe seu *e-mail* para concluir o agendamento.";
        }

        return "Deseja informar um *e-mail* para receber a confirmação? Digite o e-mail ou envie *pular*.";
    }

    public function acceptTerms(string $privacyNotice, ?string $bookingTerms): string
    {
        $lines = [];

        if ($privacyNotice !== '') {
            $lines[] = "*Aviso de privacidade*";
            $lines[] = $privacyNotice;
            $lines[] = '';
        }

        if (filled($bookingTerms)) {
            $lines[] = "*Termos do agendamento*";
            $lines[] = $bookingTerms;
            $lines[] = '';
        }

        $lines[] = 'Digite *1* para aceitar e continuar, ou *0* para cancelar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array{service: string, professional: string, date: string, time: string, name: string, email: ?string}  $summary
     */
    public function confirmation(array $summary): string
    {
        $lines = [
            "*Confirme seu agendamento*",
            "",
            "• Serviço: {$summary['service']}",
            "• Profissional: {$summary['professional']}",
            "• Data: {$summary['date']}",
            "• Horário: {$summary['time']}",
            "• Nome: {$summary['name']}",
        ];

        if (filled($summary['email'] ?? null)) {
            $lines[] = "• E-mail: {$summary['email']}";
        }

        $lines[] = '';
        $lines[] = 'Digite *1* para confirmar ou *2* para cancelar.';

        return implode("\n", $lines);
    }

    public function success(Appointment $appointment, ?string $manageUrl): string
    {
        $lines = [
            'Agendamento confirmado!',
            '',
            "Código: *{$appointment->public_confirmation_code}*",
        ];

        if (filled($manageUrl)) {
            $lines[] = "Gerencie pelo link: {$manageUrl}";
        }

        $lines[] = '';
        $lines[] = 'Obrigado! Envie *menu* para um novo atendimento.';

        return implode("\n", $lines);
    }

    public function handoff(): string
    {
        return "Um atendente responderá em breve por aqui.\n\nEnvie *menu* quando quiser tentar de novo pelo bot.";
    }

    public function cancelled(): string
    {
        return "Tudo bem, cancelamos o atendimento. Envie *menu* quando quiser recomeçar.";
    }

    public function invalidOption(): string
    {
        return "Não entendi. Digite o *número* da opção desejada, ou envie *menu* para reiniciar.";
    }

    public function unsupportedMedia(): string
    {
        return "Não consigo processar áudios, imagens ou anexos aqui. Envie apenas *texto*, ou digite *menu* para reiniciar.";
    }

    public function botDisabledFallback(): string
    {
        return '';
    }

    protected function companyName(Company $company): string
    {
        return trim((string) $company->name) !== '' ? (string) $company->name : 'nossa empresa';
    }
}
