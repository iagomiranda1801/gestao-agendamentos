<div style="display:grid;gap:18px;overflow-wrap:anywhere">
@php($quote = $order?->latestQuote())
@if($quote)
<div><strong>Orçamento versão {{ $quote->version }} — R$ {{ number_format((float)$quote->total, 2, ',', '.') }}</strong>
<p>{{ $quote->decision === 'approved' ? 'Aprovado' : ($quote->decision === 'declined' ? 'Recusado' : ($quote->published_at ? 'Aguardando resposta' : 'Ainda não enviado')) }} · Prazo: {{ $quote->deadline }}</p>
@foreach($quote->items as $item)<p>{{ (float)$item['quantity'] }} × {{ $item['name'] }} · R$ {{ number_format((float)$item['unit_price'], 2, ',', '.') }}</p>@endforeach
@if($quote->published_at)<a href="{{ route('service-orders.quote', $quote->token) }}" target="_blank" rel="noreferrer">Abrir orçamento do cliente</a>@endif
</div>
@endif
@if($order?->sale)<p>Venda #{{ $order->sale_id }} · Total R$ {{ number_format((float)$order->sale->final_amount, 2, ',', '.') }} · Saldo R$ {{ number_format((float)$order->sale->receivable?->outstanding_amount, 2, ',', '.') }}</p>@endif
<div><strong>Fotos internas</strong>
@forelse($order?->photos ?? [] as $photo)<p><a href="{{ route('service-orders.photo', $photo) }}" target="_blank" rel="noopener">Abrir foto {{ $loop->iteration }}</a></p>@empty<p>Nenhuma foto adicionada.</p>@endforelse</div>
<div><strong>Mensagens</strong>
@forelse($order?->messages()->latest('id')->get() ?? [] as $message)
<p>{{ \App\Support\CompanyDateTime::formatLocal($order->company, $message->created_at, 'd/m H:i') }} · {{ ['order'=>'OS','quote'=>'Orçamento','ready'=>'Pronto para retirada','receipt'=>'Comprovante'][$message->kind] }} · {{ ['queued'=>'Na fila','sending'=>'Enviando — confirme na conversa se demorar','sent'=>'Aceito pelo provedor','failed'=>'Falhou','uncertain'=>'Envio não confirmado'][$message->status] ?? $message->status }} · {{ $message->recipient }}<br>{{ $message->error }}</p>
@empty<p>Nenhuma mensagem enviada.</p>@endforelse</div>
<details><summary>Histórico</summary>@foreach($order?->events()->with('user')->latest('id')->get() ?? [] as $event)<p>{{ \App\Support\CompanyDateTime::formatLocal($order->company, $event->created_at) }} · {{ $event->description }} · {{ $event->user?->name ?? 'Cliente pelo link privado' }}</p>@endforeach</details>
</div>
