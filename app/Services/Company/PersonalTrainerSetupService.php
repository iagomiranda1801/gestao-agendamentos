<?php

namespace App\Services\Company;

use App\Enums\CompanyModule;
use App\Filament\App\Pages\SchedulingSettingsPage;
use App\Filament\App\Resources\Professionals\ProfessionalResource;
use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Support\Segment;

class PersonalTrainerSetupService
{
    /** @return array{steps: list<array{label: string, description: string, complete: bool, url: string}>, templates: list<array{label: string, url: string}>, booking_url: ?string} */
    public function summary(Company $company): array
    {
        if (! $company->isPersonalTrainer()
            || ! app(CompanyModuleService::class)->hasModule($company, CompanyModule::Scheduling)) {
            return ['steps' => [], 'templates' => [], 'booking_url' => null];
        }

        $professionals = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->orderBy('id')
            ->get();
        $professional = $professionals->first();

        $hasHours = $professionals->contains(fn (Professional $item): bool => $item->workingHours()->active()->exists());
        $onlineServices = app(OnlineBookingCatalogService::class)->getEligibleServices($company);
        $hasOnlineService = $onlineServices->isNotEmpty();
        $publicBookingEnabled = (bool) $company->schedulingSetting?->public_booking_enabled;
        $templates = [];

        foreach (['initial_assessment' => 'Avaliação inicial', 'individual_training' => 'Treino individual'] as $key => $name) {
            $existing = Service::query()->where('company_id', $company->getKey())->where('name', $name)->first();
            $templates[] = [
                'label' => ($existing ? 'Editar ' : 'Criar ').$name,
                'url' => $existing
                    ? ServiceResource::getUrl('edit', ['record' => $existing])
                    : ServiceResource::getUrl('create').'?personal_template='.$key,
            ];
        }

        $steps = [
            [
                'label' => 'Cadastrar o personal',
                'description' => 'Confira seus dados e vincule seu acesso ao cadastro profissional.',
                'complete' => $professional !== null,
                'url' => $professional
                    ? ProfessionalResource::getUrl('edit', ['record' => $professional])
                    : ProfessionalResource::getUrl('create'),
            ],
            [
                'label' => 'Definir horários de atendimento',
                'description' => 'A reserva online usa a jornada do personal e o horário geral da empresa.',
                'complete' => $hasHours,
                'url' => $professional
                    ? ProfessionalResource::getUrl('edit', ['record' => $professional])
                    : ProfessionalResource::getUrl('create'),
            ],
            [
                'label' => 'Criar serviços',
                'description' => 'Defina preço e duração da avaliação inicial e do treino individual.',
                'complete' => $onlineServices->contains('name', 'Avaliação inicial')
                    && $onlineServices->contains('name', 'Treino individual'),
                'url' => ServiceResource::getUrl('index'),
            ],
            [
                'label' => 'Ativar agendamento online',
                'description' => 'Habilite a página pública nas configurações da agenda.',
                'complete' => $publicBookingEnabled,
                'url' => SchedulingSettingsPage::getUrl(),
            ],
        ];

        return [
            'steps' => $steps,
            'templates' => $templates,
            'booking_url' => $hasOnlineService && $publicBookingEnabled
                ? Segment::route($company, 'public.booking.show', ['company' => $company])
                : null,
        ];
    }
}
