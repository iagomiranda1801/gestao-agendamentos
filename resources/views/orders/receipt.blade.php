@php
    use App\Enums\OrderStatus;
    use App\Support\CompanyDateTime;

    $width = (int) $paperMm;
    $contentWidth = max(48, $width - 4);
    $openedAt = $order->received_at ?? $order->created_at;
    $closedAt = $order->status === OrderStatus::Completed ? $order->completed_at : null;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cupom não fiscal {{ $order->displayNumber() }}</title>
    <style>
        @page { size: {{ $width }}mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0 auto;
            padding: 2mm;
            width: {{ $contentWidth }}mm;
            color: #111;
            background: #fff;
            font-family: "Courier New", ui-monospace, monospace;
            font-size: 12px;
            line-height: 1.35;
        }
        h1, h2, p { margin: 0; }
        .center { text-align: center; }
        .muted { font-size: 11px; }
        .banner {
            margin: 8px 0;
            padding: 6px 4px;
            border: 1px dashed #111;
            text-align: center;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        td { vertical-align: top; padding: 2px 0; }
        .right { text-align: right; white-space: nowrap; }
        .rule { border-top: 1px dashed #111; margin: 8px 0; }
        .total { font-size: 14px; font-weight: 700; }
        .note { font-size: 11px; }
        .actions { margin-top: 12px; }
        button {
            font: inherit;
            padding: 6px 10px;
            cursor: pointer;
        }
        @media print {
            .actions { display: none; }
            body { width: auto; }
        }
    </style>
</head>
<body>
    <h1 class="center">{{ $company->name }}</h1>
    @if ($company->formattedDocument())
        <p class="center muted">CNPJ {{ $company->formattedDocument() }}</p>
    @endif
    @if (filled($company->phone))
        <p class="center muted">{{ $company->phone }}</p>
    @endif

    <p class="banner">Cupom não fiscal</p>

    <p><strong>{{ $order->isFloorOrder() ? 'Comanda' : 'Pedido' }} {{ $order->displayNumber() }}</strong></p>
    <p>{{ $order->fulfillment?->label() }}</p>
    @if ($order->diningTableLabel())
        <p>{{ $order->diningTableLabel() }}</p>
    @endif
    @if ($order->isFloorOrder() && $order->customer_name !== $order->diningTableLabel())
        <p>Cliente: {{ $order->customer_name }}</p>
    @elseif (! $order->isFloorOrder())
        <p>Cliente: {{ $order->customer_name }}</p>
    @endif
    @if ($openedAt)
        <p>Abertura: {{ CompanyDateTime::formatLocal($company, $openedAt) }}</p>
    @endif
    @if ($closedAt)
        <p>Fechamento: {{ CompanyDateTime::formatLocal($company, $closedAt) }}</p>
    @endif

    <div class="rule"></div>

    <table>
        <tbody>
            @forelse ($order->items as $item)
                <tr>
                    <td>{{ $item->quantity }}× {{ $item->name }}</td>
                    <td class="right">{{ $item->formattedLineTotal() }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="note">{{ $item->formattedUnitPrice() }} un.@if (filled($item->notes)) · {{ $item->notes }}@endif</td>
                </tr>
            @empty
                <tr><td>Nenhum item lançado.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="rule"></div>
    <table>
        <tr class="total">
            <td>Total</td>
            <td class="right">{{ $order->formattedTotal() }}</td>
        </tr>
    </table>

    @if ($order->status === OrderStatus::Completed)
        <p>Pagamento: {{ $order->payment_method?->label() ?? 'Não informado' }}</p>
    @elseif ($order->status === OrderStatus::Cancelled)
        <p><strong>{{ $order->isFloorOrder() ? 'Comanda cancelada' : 'Pedido cancelado' }}</strong></p>
        @if (filled($order->cancel_reason))
            <p>Motivo: {{ $order->cancel_reason }}</p>
        @endif
    @else
        <p><strong>Conferência da conta — ainda não paga</strong></p>
    @endif

    <p class="center muted" style="margin-top: 10px;">Este documento não tem valor fiscal.</p>
    <p class="center">Obrigado e volte sempre.</p>

    <div class="actions">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    @endif
</body>
</html>
