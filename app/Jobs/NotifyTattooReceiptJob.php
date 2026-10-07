<?php

namespace App\Jobs;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\TattooPaymentReceipt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyTattooReceiptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $receiptId) {}

    public function handle(): void
    {
        $receipt = TattooPaymentReceipt::query()->with(['request.client', 'request.professional.user', 'request.company', 'quote'])->find($this->receiptId);
        if (! $receipt) {
            return;
        }
        $company = $receipt->request->company;
        $users = $company->users()->wherePivot('is_active', true)->where('users.is_active', true)->get()
            ->filter(fn ($user) => in_array($user->pivot->role instanceof CompanyRole ? $user->pivot->role->value : $user->pivot->role,
                [CompanyRole::CompanyAdmin->value, CompanyRole::Manager->value], true))
            ->keyBy('id');
        $professionalUser = $receipt->request->professional?->user;
        if ($professionalUser && $professionalUser->hasActiveCompanyMembershipWith($company)) {
            $users->put($professionalUser->id, $professionalUser);
        }
        if ($users->isEmpty()) {
            return;
        }
        $url = TattooRequestResource::getUrl('edit', ['record' => $receipt->tattoo_request_id], panel: 'app', tenant: $company);
        Notification::make()->title('Comprovante PIX recebido')
            ->body(($receipt->request->client?->name ?? 'Cliente').' · Sinal esperado R$ '
                .number_format((float) $receipt->quote->deposit_amount, 2, ',', '.')
                .' · Leitura '.$receipt->receipt_analysis_status
                .(($receipt->analysis['warnings'] ?? []) !== [] ? ' · Alertas: '.implode('; ', $receipt->analysis['warnings']) : ''))
            ->actions([Action::make('view')->label('Conferir')->url($url)->markAsRead()])
            ->sendToDatabase($users->values());
    }
}
