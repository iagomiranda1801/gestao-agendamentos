<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\TattooQuote;
use App\Services\Tattoo\TattooQuoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTattooQuoteWhatsAppJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public function __construct(public int $quoteId) {}

    public function handle(TattooQuoteService $quotes): void
    {
        $quote = TattooQuote::query()->with('request.company')->find($this->quoteId);
        if (! $quote || $quote->sent_at !== null) {
            return;
        }
        if (! $this->deferUntilOutboundSlot($quote->request->company, WhatsAppOutboundKind::Confirmation)) {
            return;
        }

        try {
            $quotes->send($quote);
            $this->rememberOutboundSuccess($quote->request->company);
        } catch (\Throwable $exception) {
            $this->rememberOutboundFailure($quote->request->company);
            throw $exception;
        }
    }
}
