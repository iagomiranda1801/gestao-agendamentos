@extends('service-orders.layout')
@section('content')
<p>{{ $order->company->name }}</p>
<h1>{{ $receipt ? 'Comprovante de entrega' : 'Ordem de serviço' }} #{{ $order->number }}</h1>
<p>Recebido em {{ \App\Support\CompanyDateTime::formatLocal($order->company, $order->created_at) }}</p>
<p>Cliente: {{ $order->client->name }}<br>Aparelho: {{ $order->device }}</p>
<h2>Problema informado</h2><p class="text">{{ $order->problem }}</p>
@if(!$receipt)
<h2>Recebimento</h2><p class="text">{{ $order->condition ?: 'Estado de entrada não informado.' }}</p>
<p class="text">Acessórios: {{ $order->accessories ?: 'Não informados.' }}</p>
@else
<h2>Serviço realizado</h2>
@foreach($order->latestQuote()?->items ?? [] as $item)<p>{{ $item['quantity'] }} × {{ $item['name'] }}</p>@endforeach
<p>Total: R$ {{ number_format((float)$order->sale?->final_amount, 2, ',', '.') }}<br>Pago: R$ {{ number_format((float)$order->sale?->receivable?->paid_amount, 2, ',', '.') }}<br>Saldo: R$ {{ number_format((float)$order->sale?->receivable?->outstanding_amount, 2, ',', '.') }}</p>
<h2>Informações de garantia</h2><p class="text">{{ $order->warranty ?: 'Consulte a loja para obter as informações de garantia.' }}</p>
@endif
<div class="actions"><button onclick="window.print()">Imprimir / Salvar PDF</button></div>
@endsection
