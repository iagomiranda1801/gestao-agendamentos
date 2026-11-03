@extends('service-orders.layout')
@section('content')
<p>{{ $order->company->name }}</p><h1>Orçamento da OS #{{ $order->number }}</h1>
<p>{{ $order->device }} · Versão {{ $quote->version }}</p>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<table><thead><tr><th>Item</th><th>Quantidade</th><th>Valor unitário</th></tr></thead><tbody>
@foreach($quote->items as $item)<tr><td>{{ $item['name'] }}</td><td>{{ (float)$item['quantity'] }}</td><td>R$ {{ number_format((float)$item['unit_price'], 2, ',', '.') }}</td></tr>@endforeach
</tbody></table>
<h2>Total: R$ {{ number_format((float)$quote->total, 2, ',', '.') }}</h2>
<p>Prazo: {{ $quote->deadline }}<br>Válido até: {{ $quote->valid_until->format('d/m/Y') }}</p>
<p class="text">{{ $quote->conditions }}</p>
@if($available)
<p>Confira os itens e as condições antes de responder.</p>
<form class="actions" method="post" action="{{ route('service-orders.decide', $quote->token) }}">@csrf
<button name="decision" value="approved">Aprovar orçamento</button><button class="decline" name="decision" value="declined">Recusar orçamento</button></form>
@else
<p class="notice">{{ $order->latestQuote()?->id !== $quote->id ? 'Esta versão foi substituída. Solicite o orçamento atual à loja.' : ($quote->decision === 'approved' ? 'Aprovado' : ($quote->decision === 'declined' ? 'Recusado' : 'Este orçamento expirou ou não está disponível para resposta.')) }}</p>
@endif
<div class="actions"><button onclick="window.print()">Imprimir / Salvar PDF</button></div>
@endsection
