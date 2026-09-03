<?php

namespace App\Services\WhatsApp\Campaigns;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Jobs\SendWhatsAppCampaignRecipientJob;
use App\Jobs\StartScheduledWhatsAppCampaignJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\WhatsApp\Automations\InactiveClientQuery;
use App\Support\VehiclePlate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WhatsAppCampaignService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $user, array $data): WhatsAppCampaign
    {
        $payload = $this->preparePayload($data);

        return DB::transaction(function () use ($company, $user, $payload): WhatsAppCampaign {
            $campaign = new WhatsAppCampaign($payload);
            $campaign->status = WhatsAppCampaignStatus::Draft;
            $campaign->company()->associate($company);
            $campaign->creator()->associate($user);
            $campaign->save();

            return $campaign->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, WhatsAppCampaign $campaign, array $data): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);
        $this->ensureEditable($campaign);
        $deliveryType = $data['delivery_type'] ?? 'now';

        $campaign->fill($this->preparePayload($data));
        $campaign->save();

        if ($campaign->status === WhatsAppCampaignStatus::Scheduled && $deliveryType === 'scheduled') {
            $this->prepareRecipients($company, $campaign);
            $this->schedule($company, $campaign, $campaign->scheduled_at);
        } elseif ($campaign->status === WhatsAppCampaignStatus::Scheduled) {
            $campaign->forceFill([
                'status' => WhatsAppCampaignStatus::Draft,
                'scheduled_at' => null,
            ])->save();
        }

        return $campaign->refresh();
    }

    public function prepareRecipients(Company $company, WhatsAppCampaign $campaign): int
    {
        $this->ensureBelongsToCompany($company, $campaign);
        $this->ensureEditable($campaign);

        return DB::transaction(function () use ($company, $campaign): int {
            $campaign->recipients()->delete();

            $count = 0;
            $this->audienceQuery($company, $campaign)
                ->orderBy('name')
                ->chunkById(200, function ($clients) use ($campaign, &$count): void {
                    foreach ($clients as $client) {
                        $phone = preg_replace('/\D+/', '', (string) $client->phone_normalized) ?? '';

                        if ($phone === '') {
                            continue;
                        }

                        $recipient = new WhatsAppCampaignRecipient([
                            'phone' => $phone,
                            'email_snapshot' => filled($client->email) ? $client->email : null,
                            'name_snapshot' => $client->name,
                            'message_snapshot' => $this->renderMessage($campaign, $client),
                            'status' => WhatsAppCampaignRecipientStatus::Pending,
                        ]);
                        $recipient->company()->associate($campaign->company);
                        $recipient->campaign()->associate($campaign);
                        $recipient->client()->associate($client);
                        $recipient->save();
                        $count++;
                    }
                });

            $campaign->forceFill([
                'total_recipients' => $count,
                'sent_count' => 0,
                'accepted_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
            ])->save();

            return $count;
        });
    }

    public function startSending(Company $company, WhatsAppCampaign $campaign): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);
        $this->ensureReadyToSend($campaign);

        if ($campaign->recipients()->where('status', WhatsAppCampaignRecipientStatus::Pending)->doesntExist()) {
            throw ValidationException::withMessages([
                'recipients' => 'Prepare os destinatários antes de enviar a campanha.',
            ]);
        }

        $dispatches = [];

        $campaign = DB::transaction(function () use ($campaign, &$dispatches): WhatsAppCampaign {
            $campaign->forceFill([
                'status' => WhatsAppCampaignStatus::Sending,
                'started_at' => now(),
                'completed_at' => null,
                'cancelled_at' => null,
                'scheduled_at' => null,
            ])->save();

            $delay = 0;
            $interval = max(30, (int) $campaign->send_interval_seconds);

            $campaign->recipients()
                ->where('status', WhatsAppCampaignRecipientStatus::Pending)
                ->orderBy('id')
                ->chunkById(200, function ($recipients) use (&$delay, $interval, &$dispatches): void {
                    foreach ($recipients as $recipient) {
                        $recipient->forceFill([
                            'status' => WhatsAppCampaignRecipientStatus::Queued,
                            'queued_at' => now(),
                        ])->save();

                        $dispatches[] = [
                            'id' => $recipient->getKey(),
                            'delay' => $delay,
                        ];

                        $delay += $interval;
                    }
                });

            return $campaign->refresh();
        });

        $this->dispatchRecipientJobs($dispatches);

        return $campaign;
    }

    /**
     * @param  list<array{id: int, delay: int}>  $dispatches
     */
    public function dispatchRecipientJobs(array $dispatches): void
    {
        foreach ($dispatches as $dispatch) {
            SendWhatsAppCampaignRecipientJob::dispatch($dispatch['id'])
                ->delay(now()->addSeconds((int) $dispatch['delay']));
        }
    }

    public function requeueStuckQueuedRecipients(int $stuckAfterMinutes = 5): int
    {
        $queued = 0;
        $cutoff = now()->subMinutes(max(1, $stuckAfterMinutes));

        WhatsAppCampaignRecipient::query()
            ->where('status', WhatsAppCampaignRecipientStatus::Queued)
            ->whereNull('attempted_at')
            ->where('queued_at', '<=', $cutoff)
            ->whereHas('campaign', function ($query): void {
                $query->where('status', WhatsAppCampaignStatus::Sending);
            })
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use (&$queued): void {
                foreach ($recipients as $recipient) {
                    if ($this->recipientJobIsPending($recipient->getKey())) {
                        continue;
                    }

                    SendWhatsAppCampaignRecipientJob::dispatch($recipient->getKey());
                    $queued++;
                }
            });

        return $queued;
    }

    protected function recipientJobIsPending(int $recipientId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%SendWhatsAppCampaignRecipientJob%')
            ->where('payload', 'like', '%recipientId";i:'.$recipientId.';%')
            ->exists();
    }

    public function cancel(Company $company, WhatsAppCampaign $campaign, User $user): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);

        $campaign->forceFill([
            'status' => WhatsAppCampaignStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $user->getKey(),
            'scheduled_at' => null,
        ])->save();

        $campaign->recipients()
            ->whereIn('status', [WhatsAppCampaignRecipientStatus::Pending, WhatsAppCampaignRecipientStatus::Queued])
            ->update([
                'status' => WhatsAppCampaignRecipientStatus::Skipped,
                'error_message' => 'Campanha cancelada antes do envio.',
                'updated_at' => now(),
            ]);

        $this->refreshCounters($campaign);

        return $campaign->refresh();
    }

    public function duplicateForResend(Company $company, WhatsAppCampaign $campaign, User $user): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);

        return DB::transaction(function () use ($company, $campaign, $user): WhatsAppCampaign {
            $copy = new WhatsAppCampaign([
                'name' => $campaign->name.' - reenvio',
                'audience_type' => $campaign->audience_type->value,
                'selected_client_ids' => $campaign->selected_client_ids,
                'inactive_since_days' => $campaign->inactive_since_days,
                'message_template' => $campaign->message_template,
                'image_path' => $campaign->image_path,
                'image_disk' => $campaign->image_disk,
                'image_mime' => $campaign->image_mime,
                'send_interval_seconds' => $campaign->send_interval_seconds,
                'scheduled_at' => null,
                'status' => WhatsAppCampaignStatus::Draft->value,
                'total_recipients' => 0,
                'sent_count' => 0,
                'accepted_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
            ]);
            $copy->company()->associate($company);
            $copy->creator()->associate($user);
            $copy->save();

            return $copy->refresh();
        });
    }

    public function resend(Company $company, WhatsAppCampaign $campaign, User $user): WhatsAppCampaign
    {
        $copy = $this->duplicateForResend($company, $campaign, $user);
        $this->prepareRecipients($company, $copy);

        return $this->startSending($company, $copy->refresh());
    }

    public function sendNow(Company $company, WhatsAppCampaign $campaign): WhatsAppCampaign
    {
        $count = $this->prepareRecipients($company, $campaign);

        if ($count === 0) {
            throw ValidationException::withMessages([
                'recipients' => 'Não há clientes ativos e autorizados para receber esta campanha.',
            ]);
        }

        return $this->startSending($company, $campaign->refresh());
    }

    public function schedule(Company $company, WhatsAppCampaign $campaign, mixed $scheduledAt): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);
        $this->ensureEditable($campaign);

        $scheduledAt = $scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse($scheduledAt);

        if ($scheduledAt->lte(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Escolha uma data e hora futuras para agendar a campanha.',
            ]);
        }

        if ($campaign->recipients()->where('status', WhatsAppCampaignRecipientStatus::Pending)->doesntExist()) {
            throw ValidationException::withMessages([
                'recipients' => 'Não há destinatários autorizados para esta campanha.',
            ]);
        }

        $campaign->forceFill([
            'status' => WhatsAppCampaignStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ])->save();

        StartScheduledWhatsAppCampaignJob::dispatch($campaign->getKey())->delay($scheduledAt);

        return $campaign->refresh();
    }

    public function refreshCounters(WhatsAppCampaign $campaign): void
    {
        $counts = $campaign->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $remaining = $campaign->recipients()
            ->whereIn('status', [WhatsAppCampaignRecipientStatus::Pending, WhatsAppCampaignRecipientStatus::Queued])
            ->exists();

        $campaign->forceFill([
            'total_recipients' => (int) $counts->sum(),
            'sent_count' => (int) ($counts[WhatsAppCampaignRecipientStatus::Sent->value] ?? 0)
                + (int) ($counts[WhatsAppCampaignRecipientStatus::Delivered->value] ?? 0)
                + (int) ($counts[WhatsAppCampaignRecipientStatus::Read->value] ?? 0),
            'accepted_count' => (int) ($counts[WhatsAppCampaignRecipientStatus::Accepted->value] ?? 0),
            'failed_count' => (int) ($counts[WhatsAppCampaignRecipientStatus::Failed->value] ?? 0),
            'skipped_count' => (int) ($counts[WhatsAppCampaignRecipientStatus::Skipped->value] ?? 0),
            'status' => $campaign->status === WhatsAppCampaignStatus::Sending && ! $remaining
                ? WhatsAppCampaignStatus::Completed
                : $campaign->status,
            'completed_at' => $campaign->status === WhatsAppCampaignStatus::Sending && ! $remaining
                ? now()
                : $campaign->completed_at,
        ])->save();
    }

    protected function ensureBelongsToCompany(Company $company, WhatsAppCampaign $campaign): void
    {
        if ((int) $campaign->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    protected function ensureEditable(WhatsAppCampaign $campaign): void
    {
        if (! in_array($campaign->status, [WhatsAppCampaignStatus::Draft, WhatsAppCampaignStatus::Scheduled], true)) {
            throw ValidationException::withMessages([
                'status' => 'Somente campanhas em rascunho ou agendadas podem ser alteradas.',
            ]);
        }
    }

    protected function ensureReadyToSend(WhatsAppCampaign $campaign): void
    {
        $this->ensureEditable($campaign);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['created_by'], $data['cancelled_by']);
        unset($data['delivery_type'], $data['message_suggestion']);

        if (blank($data['name'] ?? null)) {
            $data['name'] = 'Campanha '.now()->format('d/m/Y H:i');
        }

        $interval = (int) ($data['send_interval_seconds'] ?? 40);
        $data['send_interval_seconds'] = max(30, min(300, $interval));
        $data['selected_client_ids'] = $this->normalizeSelectedClientIds($data['selected_client_ids'] ?? []);
        $audienceType = $data['audience_type'] instanceof WhatsAppCampaignAudience
            ? $data['audience_type']->value
            : ($data['audience_type'] ?? null);

        if (blank($data['message_template'] ?? null)) {
            throw ValidationException::withMessages([
                'message_template' => 'Informe a mensagem da campanha.',
            ]);
        }

        if ($audienceType === WhatsAppCampaignAudience::SelectedClients->value && $data['selected_client_ids'] === []) {
            throw ValidationException::withMessages([
                'selected_client_ids' => 'Selecione pelo menos um cliente.',
            ]);
        }

        if ($audienceType === WhatsAppCampaignAudience::InactiveSinceDays->value) {
            $days = (int) ($data['inactive_since_days'] ?? 30);

            if ($days < 7 || $days > 365) {
                throw ValidationException::withMessages([
                    'inactive_since_days' => 'Informe entre 7 e 365 dias sem visita.',
                ]);
            }

            $data['inactive_since_days'] = $days;
        } else {
            $data['inactive_since_days'] = null;
        }

        if ($audienceType !== WhatsAppCampaignAudience::SelectedClients->value) {
            $data['selected_client_ids'] = [];
        }

        $data = $this->normalizeImagePayload($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeImagePayload(array $data): array
    {
        $path = $data['image_path'] ?? null;

        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        $path = is_string($path) ? trim($path) : '';

        if ($path === '') {
            $data['image_path'] = null;
            $data['image_disk'] = null;
            $data['image_mime'] = null;

            return $data;
        }

        $disk = (string) ($data['image_disk'] ?? config('filesystems.company_logo_disk', 's3'));
        $mime = (string) (Storage::disk($disk)->mimeType($path) ?: '');
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $fromExtension = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => '',
        };

        if (! in_array($mime, $allowed, true)) {
            $mime = $fromExtension;
        }

        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'image_path' => 'Envie uma imagem JPEG, PNG ou WebP de até 2 MB.',
            ]);
        }

        $data['image_path'] = $path;
        $data['image_disk'] = $disk;
        $data['image_mime'] = $mime;

        return $data;
    }

    /**
     * @return Builder<Client>
     */
    protected function audienceQuery(Company $company, WhatsAppCampaign $campaign): Builder
    {
        return match ($campaign->audience_type) {
            WhatsAppCampaignAudience::OptedInActiveClients => Client::query()
                ->where('company_id', $company->getKey())
                ->active()
                ->whatsappMarketingOptedIn()
                ->whereNotNull('phone_normalized')
                ->where('phone_normalized', '!=', ''),
            WhatsAppCampaignAudience::SelectedClients => Client::query()
                ->where('company_id', $company->getKey())
                ->whereKey($campaign->selected_client_ids ?? [])
                ->active()
                ->whatsappMarketingOptedIn()
                ->whereNotNull('phone_normalized')
                ->where('phone_normalized', '!=', ''),
            WhatsAppCampaignAudience::InactiveSinceDays => app(InactiveClientQuery::class)
                ->optedInInactive($company, (int) ($campaign->inactive_since_days ?: 30)),
        };
    }

    /**
     * @return list<int>
     */
    protected function normalizeSelectedClientIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function renderMessage(WhatsAppCampaign $campaign, Client $client): string
    {
        return strtr($campaign->message_template, [
            '{nome}' => $client->name,
            '{empresa}' => $campaign->company->name,
            '{placa}' => VehiclePlate::format($client->vehicle_plate) ?? ($client->vehicle_plate ?: ''),
        ]);
    }
}
