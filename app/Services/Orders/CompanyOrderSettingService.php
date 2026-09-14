<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\CompanyOrderSetting;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyOrderSettingService
{
    public function getOrCreate(Company $company): CompanyOrderSetting
    {
        $setting = CompanyOrderSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if ($setting) {
            return $setting;
        }

        $setting = new CompanyOrderSetting([
            'online_ordering_enabled' => false,
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'dine_in_enabled' => $company->isRestaurant(),
            'delivery_fee_cents' => 0,
            'min_order_cents' => 0,
            'orders_whatsapp_notify' => true,
            'whatsapp_order_link_bot_enabled' => true,
        ]);
        $setting->company()->associate($company);
        $setting->save();

        return $setting->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): CompanyOrderSetting
    {
        return DB::transaction(function () use ($company, $data): CompanyOrderSetting {
            $setting = $this->getOrCreate($company);
            $payload = $this->preparePayload($data);

            $this->validatePayload(array_merge($setting->getAttributes(), $payload));

            $setting->fill($payload);
            $setting->save();

            return $setting->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id']);

        if (array_key_exists('page_title', $data)) {
            $data['page_title'] = PublicBookingTextSanitizer::bookingPageTitle($data['page_title']);
        }

        if (array_key_exists('page_description', $data)) {
            $data['page_description'] = PublicBookingTextSanitizer::bookingPageDescription($data['page_description']);
        }

        if (array_key_exists('confirmation_message', $data)) {
            $data['confirmation_message'] = PublicBookingTextSanitizer::bookingConfirmationMessage($data['confirmation_message']);
        }

        if (array_key_exists('delivery_radius_note', $data)) {
            $data['delivery_radius_note'] = PublicBookingTextSanitizer::sanitize(
                $data['delivery_radius_note'] !== null ? (string) $data['delivery_radius_note'] : null,
                2000,
            );
        }

        foreach (['delivery_fee_cents', 'min_order_cents'] as $centsField) {
            if (array_key_exists($centsField, $data)) {
                $data[$centsField] = max(0, (int) $data[$centsField]);
            }
        }

        if (array_key_exists('primary_color', $data) && filled($data['primary_color'])) {
            $color = (string) $data['primary_color'];
            $data['primary_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validatePayload(array $payload): void
    {
        $hasFulfillment = ($payload['pickup_enabled'] ?? false)
            || ($payload['delivery_enabled'] ?? false)
            || ($payload['dine_in_enabled'] ?? false);

        if (! $hasFulfillment) {
            throw ValidationException::withMessages([
                'pickup_enabled' => 'Habilite retirada, entrega ou consumo no local para receber pedidos online.',
            ]);
        }
    }
}
