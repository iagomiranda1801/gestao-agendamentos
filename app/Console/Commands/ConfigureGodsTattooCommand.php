<?php

namespace App\Console\Commands;

use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use App\Models\TattooRequest;
use App\Services\Scheduling\CompanyBusinessHoursService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Scheduling\ProfessionalWorkingHoursService;
use App\Support\CompanyDateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfigureGodsTattooCommand extends Command
{
    protected $signature = 'tattoo:configure-gods';

    protected $description = 'Configura somente a GODS TATOO com atendimento diário das 14h às 22h';

    public function handle(CompanySchedulingSettingService $settings, CompanyBusinessHoursService $businessHours,
        ProfessionalWorkingHoursService $workingHours): int
    {
        $matches = Company::query()->get()->filter(fn (Company $company): bool => $company->isTattooStudio()
            && in_array(Str::slug($company->name), ['gods-tatoo', 'goods-tatoo'], true))->values();
        if ($matches->count() !== 1) {
            $this->error('Esperada uma única empresa de tatuagem GODS TATOO; encontradas: '.$matches->count());

            return self::FAILURE;
        }
        /** @var Company $company */
        $company = $matches->first();
        $hours = array_map(fn (Weekday $day): array => [
            'weekday' => $day->value, 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_active' => true,
        ], Weekday::cases());

        DB::transaction(function () use ($company, $settings, $businessHours, $workingHours, $hours): void {
            $settings->getOrCreate($company)->update([
                'tattoo_custom_flow_enabled' => true,
                'calendar_start_time' => '14:00:00',
                'calendar_end_time' => '22:00:00',
            ]);
            $businessHours->replaceWeeklyHours($company, $hours);
            Professional::query()->where('company_id', $company->getKey())->active()->bookable()
                ->get()->each(fn (Professional $professional) => $workingHours->replaceWeekly($company, $professional, $hours));
            Appointment::query()->where('company_id', $company->getKey())
                ->whereIn('id', TattooRequest::query()->where('company_id', $company->getKey())
                    ->whereNotNull('appointment_id')->select('appointment_id'))
                ->update(['is_tattoo' => true]);
        });

        $today = CompanyDateTime::nowLocal($company)->startOfDay();
        $futureUnmarked = Appointment::query()->where('company_id', $company->getKey())
            ->where('is_tattoo', false)->where('start_at', '>=', CompanyDateTime::localToUtc($company, $today))
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])->orderBy('start_at')->get(['id']);
        $this->info('GODS TATOO configurada. Empresa #'.$company->getKey());
        $this->warn('Revise os agendamentos futuros sem a marca de tatuagem: '.$futureUnmarked->pluck('id')->implode(', '));

        return self::SUCCESS;
    }
}
