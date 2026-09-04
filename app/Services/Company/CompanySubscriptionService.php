<?php

namespace App\Services\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\ModulePrice;
use App\Models\PlatformInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanySubscriptionService
{
    /**
     * @param  list<CompanyModule|string>  $modules
     */
    public function quoteCents(array $modules, BillingInterval $interval): int
    {
        return collect($this->quoteItems($modules, $interval))->sum('price_cents');
    }

    /**
     * @param  list<CompanyModule|string>  $modules
     * @return list<array{module: string, label: string, price_cents: int}>
     */
    public function quoteItems(array $modules, BillingInterval $interval): array
    {
        $resolved = collect($modules)
            ->map(function (mixed $module): ?CompanyModule {
                if ($module instanceof CompanyModule) {
                    return $module;
                }

                return CompanyModule::tryFrom((string) $module);
            })
            ->filter()
            ->unique()
            ->values();

        if ($resolved->isEmpty()) {
            return [];
        }

        $prices = ModulePrice::query()
            ->where('interval', $interval->value)
            ->whereIn('module', $resolved->map(fn (CompanyModule $module) => $module->value)->all())
            ->get()
            ->keyBy(fn (ModulePrice $price): string => $price->module->value);

        return $resolved
            ->map(function (CompanyModule $module) use ($prices): array {
                $row = $prices->get($module->value);

                return [
                    'module' => $module->value,
                    'label' => $module->label(),
                    'price_cents' => (int) ($row?->price_cents ?? 0),
                ];
            })
            ->all();
    }

    public function quoteForCompany(Company $company, ?BillingInterval $interval = null): int
    {
        $interval ??= $company->billing_interval;

        if (! $interval instanceof BillingInterval) {
            return 0;
        }

        return $this->quoteCents(
            app(CompanyModuleService::class)->enabledModules($company),
            $interval,
        );
    }

    public function outstandingInvoice(Company $company): ?PlatformInvoice
    {
        return $company->platformInvoices()
            ->whereIn('status', array_map(
                fn (PlatformInvoiceStatus $status): string => $status->value,
                PlatformInvoiceStatus::outstanding(),
            ))
            ->latest('id')
            ->first();
    }

    public function issueInvoice(Company $company, ?BillingInterval $interval = null): PlatformInvoice
    {
        $interval ??= $company->billing_interval;

        if (! $interval instanceof BillingInterval) {
            throw ValidationException::withMessages([
                'billing_interval' => 'Escolha o ciclo de cobrança.',
            ]);
        }

        $modules = app(CompanyModuleService::class)->enabledModules($company);

        if ($modules === []) {
            throw ValidationException::withMessages([
                'enabled_modules' => 'Selecione ao menos um módulo.',
            ]);
        }

        if ($this->outstandingInvoice($company) !== null) {
            throw ValidationException::withMessages([
                'invoice' => 'Já existe uma fatura aberta ou vencida para esta empresa.',
            ]);
        }

        $items = $this->quoteItems($modules, $interval);
        $amount = (int) collect($items)->sum('price_cents');
        $now = Date::now();
        $periodStart = $company->current_period_end instanceof CarbonInterface
            && $company->current_period_end->greaterThan($now)
            ? $company->current_period_end->copy()
            : $now->copy();

        $dueAt = $this->invoiceDueAt($company, $now);

        return DB::transaction(function () use ($company, $interval, $items, $amount, $periodStart, $dueAt): PlatformInvoice {
            $invoice = new PlatformInvoice([
                'number' => $this->nextInvoiceNumber(),
                'status' => PlatformInvoiceStatus::Open,
                'billing_interval' => $interval,
                'amount_cents' => $amount,
                'items' => $items,
                'period_start' => $periodStart,
                'period_end' => $periodStart->copy()->addMonthsNoOverflow($interval->months()),
                'due_at' => $dueAt,
            ]);
            $invoice->company()->associate($company);
            $invoice->save();

            return $invoice->refresh();
        });
    }

    public function payInvoice(PlatformInvoice $invoice): PlatformInvoice
    {
        if (! $invoice->isOutstanding()) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível receber faturas abertas ou vencidas.',
            ]);
        }

        return DB::transaction(function () use ($invoice): PlatformInvoice {
            $company = $invoice->company;
            $now = Date::now();
            $from = $company->current_period_end instanceof CarbonInterface
                && $company->current_period_end->greaterThan($now)
                ? $company->current_period_end->copy()
                : $now->copy();

            $invoice->forceFill([
                'status' => PlatformInvoiceStatus::Paid,
                'paid_at' => $now,
            ])->save();

            $company->forceFill([
                'billing_interval' => $invoice->billing_interval,
                'quoted_price_cents' => $invoice->amount_cents,
                'current_period_end' => $from->addMonthsNoOverflow($invoice->billing_interval->months()),
                'subscription_status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
            ])->save();

            return $invoice->refresh();
        });
    }

    public function cancelInvoice(PlatformInvoice $invoice): PlatformInvoice
    {
        if ($invoice->status === PlatformInvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => 'Não é possível cancelar uma fatura paga.',
            ]);
        }

        if ($invoice->status === PlatformInvoiceStatus::Cancelled) {
            return $invoice;
        }

        $invoice->forceFill([
            'status' => PlatformInvoiceStatus::Cancelled,
            'cancelled_at' => Date::now(),
        ])->save();

        return $invoice->refresh();
    }

    public function markInvoiceOverdue(PlatformInvoice $invoice): PlatformInvoice
    {
        if ($invoice->status !== PlatformInvoiceStatus::Open) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível marcar como vencida uma fatura aberta.',
            ]);
        }

        $invoice->update(['status' => PlatformInvoiceStatus::Overdue]);

        return $invoice->refresh();
    }

    public function markOverdueInvoices(?CarbonInterface $now = null): int
    {
        $now ??= Date::now();
        $updated = 0;

        PlatformInvoice::query()
            ->where('status', PlatformInvoiceStatus::Open)
            ->where('due_at', '<', $now)
            ->each(function (PlatformInvoice $invoice) use (&$updated): void {
                $invoice->update(['status' => PlatformInvoiceStatus::Overdue]);
                $updated++;
            });

        return $updated;
    }

    public function issueDueInvoices(?CarbonInterface $now = null): int
    {
        $now ??= Date::now();
        $issued = 0;
        $renewalLimit = $now->copy()->addDays(max(1, (int) config('subscriptions.renewal_warning_days', 7)));
        $trialLimit = $now->copy()->addDays(3);

        Company::query()
            ->where('is_active', true)
            ->whereNotNull('billing_interval')
            ->where(function ($query) use ($now, $renewalLimit, $trialLimit): void {
                $query->where(function ($query) use ($now, $renewalLimit): void {
                    $query->where('subscription_status', SubscriptionStatus::Active)
                        ->whereNotNull('current_period_end')
                        ->where('current_period_end', '>=', $now)
                        ->where('current_period_end', '<=', $renewalLimit);
                })->orWhere(function ($query) use ($now, $trialLimit): void {
                    $query->where('subscription_status', SubscriptionStatus::Trial)
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '>=', $now)
                        ->where('trial_ends_at', '<=', $trialLimit);
                });
            })
            ->get()
            ->each(function (Company $company) use (&$issued): void {
                if ($this->outstandingInvoice($company) !== null) {
                    return;
                }

                try {
                    $this->issueInvoice($company);
                    $issued++;
                } catch (ValidationException) {
                    // Sem módulos ou ciclo inválido: ignora nesta passagem.
                }
            });

        return $issued;
    }

    public function isPaidPeriodActive(Company $company, ?CarbonInterface $now = null): bool
    {
        $now ??= Date::now();

        if ($company->current_period_end === null) {
            return true;
        }

        $graceDays = max(0, (int) config('subscriptions.grace_days', 3));

        return $now->lte($company->current_period_end->copy()->addDays($graceDays));
    }

    public function billingDaysRemaining(Company $company, ?CarbonInterface $now = null): ?int
    {
        if ($company->subscription_status !== SubscriptionStatus::Active || $company->current_period_end === null) {
            return null;
        }

        $now ??= Date::now();

        if ($company->current_period_end->copy()->startOfDay()->lt($now->copy()->startOfDay())) {
            return 0;
        }

        return max(0, (int) $now->copy()->startOfDay()->diffInDays(
            $company->current_period_end->copy()->startOfDay(),
            false,
        ));
    }

    public function shouldShowBillingBanner(Company $company, ?CarbonInterface $now = null): bool
    {
        $days = $this->billingDaysRemaining($company, $now);
        $warningDays = max(1, (int) config('subscriptions.renewal_warning_days', 7));

        return $days !== null && $days <= $warningDays;
    }

    public function expireOverdue(?CarbonInterface $now = null): int
    {
        $now ??= Date::now();
        $expired = 0;

        Company::query()
            ->where('subscription_status', SubscriptionStatus::Trial)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->each(function (Company $company) use (&$expired): void {
                $company->update(['subscription_status' => SubscriptionStatus::Expired]);
                $expired++;
            });

        Company::query()
            ->where('subscription_status', SubscriptionStatus::Active)
            ->whereNotNull('current_period_end')
            ->get()
            ->each(function (Company $company) use ($now, &$expired): void {
                if ($this->isPaidPeriodActive($company, $now)) {
                    return;
                }

                $company->update(['subscription_status' => SubscriptionStatus::Expired]);
                $expired++;
            });

        return $expired;
    }

    public function formatReais(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    protected function invoiceDueAt(Company $company, CarbonInterface $now): CarbonInterface
    {
        if ($company->subscription_status === SubscriptionStatus::Trial
            && $company->trial_ends_at instanceof CarbonInterface
            && $company->trial_ends_at->greaterThan($now)) {
            return $company->trial_ends_at->copy();
        }

        if ($company->current_period_end instanceof CarbonInterface
            && $company->current_period_end->greaterThan($now)) {
            return $company->current_period_end->copy();
        }

        return $now->copy()->addDays(max(0, (int) config('subscriptions.grace_days', 3)));
    }

    protected function nextInvoiceNumber(): string
    {
        $year = Date::now()->year;
        $prefix = "AQ-{$year}-";

        $last = PlatformInvoice::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
