<?php

namespace App\Services\Tattoo;

use App\Models\Company;
use App\Models\TattooAiConversation;
use App\Models\TattooRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TattooRequestDeletionService
{
    public function blockReason(TattooRequest $request): ?string
    {
        if ($request->appointment_id || in_array($request->status, ['quote_sent', 'accepted', 'booked'], true)) {
            return 'Este pedido já foi enviado, aceito ou agendado.';
        }
        if ($request->quotes()->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('accepted_at'))->exists()) {
            return 'Um orçamento deste pedido já foi enviado ou aceito.';
        }
        if ($request->receipts()->exists()) {
            return 'Este pedido possui comprovantes de pagamento.';
        }

        return null;
    }

    public function delete(Company $company, TattooRequest $request): void
    {
        abort_unless((int) $request->company_id === (int) $company->getKey(), 404);

        $quoteId = $request->quotes()->latest('version')->value('id');
        $delete = function () use ($company, $request): array {
            return DB::transaction(function () use ($company, $request): array {
                $request = TattooRequest::query()->where('company_id', $company->getKey())
                    ->lockForUpdate()->findOrFail($request->getKey());
                if ($reason = $this->blockReason($request)) {
                    throw ValidationException::withMessages(['delete' => $reason]);
                }

                $files = $request->images()->get(['disk', 'path'])->map(fn ($image) => [$image->disk, $image->path])->all();
                TattooAiConversation::query()->where('company_id', $company->getKey())
                    ->where('tattoo_request_id', $request->getKey())
                    ->update(['tattoo_request_id' => null, 'human_takeover' => true, 'status' => 'human_takeover']);
                $request->delete();

                return $files;
            });
        };

        $files = $quoteId
            ? Cache::lock('tattoo-quote-send:'.$quoteId, 60)->block(5, $delete)
            : $delete();

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }
}
