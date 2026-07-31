<?php

namespace App\Services\WhatsApp\Campaigns;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Jobs\SendWhatsAppCampaignRecipientJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Support\Facades\DB;
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
        $this->ensureDraft($campaign);

        $campaign->fill($this->preparePayload($data));
        $campaign->save();

        return $campaign->refresh();
    }

    public function prepareRecipients(Company $company, WhatsAppCampaign $campaign): int
    {
        $this->ensureBelongsToCompany($company, $campaign);
        $this->ensureDraft($campaign);

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
        $this->ensureDraft($campaign);

        if ($campaign->recipients()->where('status', WhatsAppCampaignRecipientStatus::Pending)->doesntExist()) {
            throw ValidationException::withMessages([
                'recipients' => 'Prepare os destinatários antes de enviar a campanha.',
            ]);
        }

        return DB::transaction(function () use ($campaign): WhatsAppCampaign {
            $campaign->forceFill([
                'status' => WhatsAppCampaignStatus::Sending,
                'started_at' => now(),
                'completed_at' => null,
                'cancelled_at' => null,
            ])->save();

            $delay = 0;
            $interval = max(10, (int) $campaign->send_interval_seconds);

            $campaign->recipients()
                ->where('status', WhatsAppCampaignRecipientStatus::Pending)
                ->orderBy('id')
                ->chunkById(200, function ($recipients) use (&$delay, $interval): void {
                    foreach ($recipients as $recipient) {
                        $recipient->forceFill([
                            'status' => WhatsAppCampaignRecipientStatus::Queued,
                            'queued_at' => now(),
                        ])->save();

                        SendWhatsAppCampaignRecipientJob::dispatch($recipient->getKey())
                            ->delay(now()->addSeconds($delay));

                        $delay += $interval;
                    }
                });

            return $campaign->refresh();
        });
    }

    public function cancel(Company $company, WhatsAppCampaign $campaign, User $user): WhatsAppCampaign
    {
        $this->ensureBelongsToCompany($company, $campaign);

        $campaign->forceFill([
            'status' => WhatsAppCampaignStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $user->getKey(),
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

    protected function ensureDraft(WhatsAppCampaign $campaign): void
    {
        if ($campaign->status !== WhatsAppCampaignStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Somente campanhas em rascunho podem ser alteradas ou preparadas.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['created_by'], $data['cancelled_by']);

        $interval = (int) ($data['send_interval_seconds'] ?? 20);
        $data['send_interval_seconds'] = max(10, min(300, $interval));
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

        if ($audienceType !== WhatsAppCampaignAudience::SelectedClients->value) {
            $data['selected_client_ids'] = [];
        }

        return $data;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Client>
     */
    protected function audienceQuery(Company $company, WhatsAppCampaign $campaign): \Illuminate\Database\Eloquent\Builder
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
                ->whereNotNull('phone_normalized')
                ->where('phone_normalized', '!=', ''),
        };
    }

    /**
     * @param  mixed  $ids
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
        ]);
    }
}
