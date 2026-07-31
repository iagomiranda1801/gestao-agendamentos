<?php

namespace App\Services\Scheduling;

use App\Enums\CompanyRole;
use App\Models\Appointment;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Collection;

class AppointmentNotificationRecipientService
{
    /**
     * @return Collection<int, User>
     */
    public function staffUsers(Appointment $appointment): Collection
    {
        $company = $appointment->company;

        if ($company === null) {
            return collect();
        }

        $allowedRoles = [
            CompanyRole::CompanyAdmin->value,
            CompanyRole::Manager->value,
        ];

        $admins = $company->users()
            ->where('users.is_active', true)
            ->wherePivot('is_active', true)
            ->get()
            ->filter(function (User $user) use ($allowedRoles): bool {
                $role = $user->pivot->role;
                $value = $role instanceof CompanyRole ? $role->value : (string) $role;

                return in_array($value, $allowedRoles, true);
            });

        $recipients = $admins->keyBy(fn (User $user): int => (int) $user->getKey());

        $professionalUser = $appointment->professional?->user;
        if ($professionalUser !== null
            && $professionalUser->is_active
            && $professionalUser->hasActiveCompanyMembershipWith($company)) {
            $recipients->put((int) $professionalUser->getKey(), $professionalUser);
        }

        return $recipients->values();
    }

    /**
     * @return array<string, string>
     */
    public function staffPhones(Appointment $appointment, ?string $senderPhoneFallback = null): array
    {
        $companyPhone = PhoneNormalizer::normalize($appointment->company?->phone)
            ?? PhoneNormalizer::normalize($senderPhoneFallback);

        $candidates = [
            'company' => $companyPhone,
            'professional' => PhoneNormalizer::normalize($appointment->professional?->phone),
        ];

        $seen = [];
        $recipients = [];

        foreach ($candidates as $label => $phone) {
            if (! filled($phone) || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $recipients[$label] = $phone;
        }

        return $recipients;
    }
}
