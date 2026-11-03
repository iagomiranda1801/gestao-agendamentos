<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderPhoto;
use App\Models\ServiceOrderQuote;
use App\Services\ServiceOrders\ServiceOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceOrderDocumentController extends Controller
{
    public function document(string $token)
    {
        $order = ServiceOrder::where('document_token', $token)->orWhere('receipt_token', $token)->firstOrFail();
        $receipt = hash_equals($order->receipt_token, $token);
        abort_if($receipt && $order->status !== 'delivered', 404);

        return response()->view('service-orders.document', compact('order', 'receipt'))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function quote(string $token)
    {
        $quote = ServiceOrderQuote::where('token', $token)->whereNotNull('published_at')->firstOrFail();
        $order = $quote->serviceOrder;
        $available = $order->latestQuote()?->id === $quote->id && $quote->decision === null
            && ! $quote->expired() && $order->status === 'awaiting_approval';

        return response()->view('service-orders.quote', compact('quote', 'order', 'available'))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function decide(Request $request, string $token)
    {
        $data = $request->validate(['decision' => 'required|in:approved,declined']);
        $quote = ServiceOrderQuote::where('token', $token)->whereNotNull('published_at')->firstOrFail();
        app(ServiceOrderService::class)->decide($quote, $data['decision']);

        return redirect()->route('service-orders.quote', $token)->with('status', 'Sua resposta foi registrada.');
    }

    public function photo(Request $request, ServiceOrderPhoto $photo)
    {
        abort_unless($request->user(), 401);
        $order = $photo->serviceOrder;
        app(ServiceOrderService::class)->authorize($order->company, $request->user());
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, no-store']);
    }
}
