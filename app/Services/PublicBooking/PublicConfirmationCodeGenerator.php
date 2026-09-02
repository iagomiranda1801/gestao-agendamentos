<?php

namespace App\Services\PublicBooking;

use App\Enums\AppointmentOrigin;
use App\Models\Appointment;
use App\Models\Company;
use Illuminate\Support\Str;

class PublicConfirmationCodeGenerator
{
    /** Characters excluding visually confusing 0, O, 1, I, L. */
    private const SAFE_CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function generate(Company $company): string
    {
        $prefix = $this->resolvePrefix($company);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffixLength = max(4, 12 - strlen($prefix) - 1);
            $suffix = $this->randomSuffix($suffixLength);
            $code = $prefix.'-'.$suffix;

            if (strlen($code) < 8 || strlen($code) > 12) {
                continue;
            }

            $exists = Appointment::query()
                ->where('company_id', $company->getKey())
                ->where('public_confirmation_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return $prefix.'-'.strtoupper(Str::random(6));
    }

    public function ensureForOnlineAppointment(Appointment $appointment): string
    {
        if (filled($appointment->public_confirmation_code)) {
            return (string) $appointment->public_confirmation_code;
        }

        if ($appointment->origin !== AppointmentOrigin::Online) {
            return '';
        }

        $company = $appointment->company;

        if ($company === null) {
            return '';
        }

        $code = $this->generate($company);
        $appointment->forceFill(['public_confirmation_code' => $code])->save();

        return $code;
    }

    protected function resolvePrefix(Company $company): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $company->name) ?? '';
        $prefix = strtoupper(substr($letters, 0, 3));

        if (strlen($prefix) < 3) {
            $prefix = str_pad($prefix, 3, 'X');
        }

        return $prefix;
    }

    protected function randomSuffix(int $length): string
    {
        $charset = self::SAFE_CHARSET;
        $maxIndex = strlen($charset) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $charset[random_int(0, $maxIndex)];
        }

        return $result;
    }
}
