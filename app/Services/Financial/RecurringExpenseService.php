<?php

namespace App\Services\Financial;

use App\Enums\RecurrenceFrequency;
use App\Models\Company;
use App\Models\Payable;
use App\Models\RecurringExpenseTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringExpenseService
{
    public function __construct(
        protected PayableService $payableService,
    ) {}

    public function generateDuePayables(?Carbon $asOf = null, ?User $user = null): int
    {
        $asOf ??= now()->startOfDay();
        $created = 0;

        $templates = RecurringExpenseTemplate::query()
            ->where('is_active', true)
            ->where('auto_generate', true)
            ->where('starts_on', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf): void {
                $query
                    ->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $asOf->toDateString());
            })
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            if ($this->processTemplate($template, $asOf, $user)) {
                $created++;
            }
        }

        return $created;
    }

    public function generateForTemplate(
        Company $company,
        RecurringExpenseTemplate $template,
        ?Carbon $asOf = null,
        ?User $user = null,
    ): ?Payable {
        $asOf ??= now()->startOfDay();

        $this->ensureTemplateBelongsToCompany($company, $template);

        if (! $template->isActive() || ! $template->auto_generate) {
            throw ValidationException::withMessages([
                'template' => 'O template precisa estar ativo e com geração automática habilitada.',
            ]);
        }

        if ($this->processTemplate($template, $asOf, $user)) {
            return $this->findGeneratedPayable($template, $this->resolveCompetenceDate($template, $asOf));
        }

        return null;
    }

    public function calculateNextCompetenceDate(RecurringExpenseTemplate $template, Carbon $fromDate): Carbon
    {
        return match ($template->frequency) {
            RecurrenceFrequency::Weekly => $fromDate->copy()->startOfDay(),
            RecurrenceFrequency::Monthly => $fromDate->copy()->startOfMonth(),
            RecurrenceFrequency::Quarterly => $fromDate->copy()->firstOfQuarter(),
            RecurrenceFrequency::Semiannual => $fromDate->month <= 6
                ? $fromDate->copy()->startOfYear()
                : $fromDate->copy()->month(7)->startOfMonth(),
            RecurrenceFrequency::Yearly => $fromDate->copy()->startOfYear(),
        };
    }

    public function calculateDueDate(RecurringExpenseTemplate $template, Carbon $competenceDate): Carbon
    {
        return match ($template->frequency) {
            RecurrenceFrequency::Weekly => $competenceDate->copy()->next(
                $template->weekday ?? $competenceDate->dayOfWeek,
            ),
            default => $competenceDate->copy()->day(
                min($template->day_of_month ?? $competenceDate->day, $competenceDate->daysInMonth),
            ),
        };
    }

    public function advanceNextGenerationDate(RecurringExpenseTemplate $template, Carbon $competenceDate): Carbon
    {
        return match ($template->frequency) {
            RecurrenceFrequency::Weekly => $competenceDate->copy()->addWeek(),
            RecurrenceFrequency::Monthly => $competenceDate->copy()->addMonthNoOverflow()->startOfMonth(),
            RecurrenceFrequency::Quarterly => $competenceDate->copy()->addQuarterNoOverflow()->startOfQuarter(),
            RecurrenceFrequency::Semiannual => $competenceDate->copy()->addMonthsNoOverflow(6)->startOfMonth(),
            RecurrenceFrequency::Yearly => $competenceDate->copy()->addYearNoOverflow()->startOfYear(),
        };
    }

    protected function processTemplate(
        RecurringExpenseTemplate $template,
        Carbon $asOf,
        ?User $user,
    ): bool {
        if ($template->next_generation_date === null) {
            $template->forceFill([
                'next_generation_date' => max(
                    $template->starts_on->toDateString(),
                    $this->calculateNextCompetenceDate($template, $template->starts_on)->toDateString(),
                ),
            ])->save();
            $template->refresh();
        }

        $generationDate = Carbon::parse($template->next_generation_date)->startOfDay();
        $deadline = $asOf->copy()->addDays($template->generate_days_in_advance);

        if ($generationDate->greaterThan($deadline)) {
            return false;
        }

        if ($template->ends_on !== null && $generationDate->greaterThan($template->ends_on)) {
            return false;
        }

        $competenceDate = $this->resolveCompetenceDate($template, $generationDate);

        if ($template->ends_on !== null && $competenceDate->greaterThan($template->ends_on)) {
            return false;
        }

        $existing = $this->findGeneratedPayable($template, $competenceDate);

        if ($existing !== null) {
            $this->syncNextGenerationDate($template, $competenceDate);

            return false;
        }

        DB::transaction(function () use ($template, $competenceDate, $user): void {
            $dueDate = $this->calculateDueDate($template, $competenceDate);

            $this->payableService->createFromRecurringTemplate(
                $template->company,
                $template,
                $competenceDate,
                $dueDate,
                $user,
            );

            $this->syncNextGenerationDate($template, $competenceDate);
        });

        return true;
    }

    protected function syncNextGenerationDate(RecurringExpenseTemplate $template, Carbon $competenceDate): void
    {
        $next = $this->advanceNextGenerationDate($template, $competenceDate);

        $template->forceFill([
            'next_generation_date' => $next->toDateString(),
        ])->save();
    }

    protected function resolveCompetenceDate(RecurringExpenseTemplate $template, Carbon $fromDate): Carbon
    {
        return $this->calculateNextCompetenceDate($template, $fromDate);
    }

    protected function findGeneratedPayable(RecurringExpenseTemplate $template, Carbon $competenceDate): ?Payable
    {
        return Payable::query()
            ->where('company_id', $template->company_id)
            ->where('reference_key', "recurring:{$template->getKey()}:{$competenceDate->toDateString()}")
            ->first();
    }

    protected function ensureTemplateBelongsToCompany(Company $company, RecurringExpenseTemplate $template): void
    {
        if ((int) $template->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'recurring_expense_template_id' => 'O template informado não pertence a esta empresa.',
            ]);
        }
    }
}
