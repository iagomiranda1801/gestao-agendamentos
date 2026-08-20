<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $plan->title }} — {{ $plan->client->name }}</title>
    <style>
        body { margin:0; color:#172033; font:14px/1.5 Arial,sans-serif; background:#f1f5f9 }
        .sheet { width:190mm; min-height:270mm; margin:12mm auto; padding:14mm; box-sizing:border-box; background:#fff; box-shadow:0 8px 30px #0f172a22 }
        header { display:flex; justify-content:space-between; gap:24px; border-bottom:2px solid #0f766e; padding-bottom:14px }
        h1 { margin:0; color:#0f766e; font-size:24px } h2 { margin:24px 0 8px; font-size:16px }
        .muted { color:#64748b } .meta { display:grid; grid-template-columns:1fr 1fr; gap:6px 24px; margin-top:18px }
        table { width:100%; border-collapse:collapse; margin-top:10px } th,td { padding:9px; border-bottom:1px solid #dbe3ed; text-align:left; vertical-align:top }
        th { color:#475569; background:#f8fafc } .money { text-align:right; white-space:nowrap }
        .totals { width:280px; margin:20px 0 0 auto } .totals div { display:flex; justify-content:space-between; padding:5px }
        .totals .final { border-top:2px solid #0f766e; color:#0f766e; font-size:18px; font-weight:bold }
        .signatures { display:grid; grid-template-columns:1fr 1fr; gap:40px; margin-top:60px } .signature { padding-top:8px; border-top:1px solid #334155; text-align:center }
        .actions { position:fixed; right:20px; top:20px } button { padding:10px 16px; color:#fff; border:0; border-radius:6px; background:#0f766e; cursor:pointer }
        @media print { body { background:#fff } .sheet { width:auto; min-height:auto; margin:0; box-shadow:none } .actions { display:none } }
    </style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Imprimir ou salvar em PDF</button></div>
<main class="sheet">
    <header><div><h1>{{ $company->name }}</h1><div class="muted">Plano de tratamento odontológico</div></div><div><strong>{{ $plan->title }}</strong><br><span class="muted">{{ $plan->plan_date->format('d/m/Y') }}</span></div></header>
    <section class="meta">
        <div><strong>Paciente:</strong> {{ $plan->client->name }}</div>
        <div><strong>Prontuário:</strong> {{ $plan->client->dentalProfile?->record_number ?? '—' }}</div>
        <div><strong>Dentista:</strong> {{ $plan->professional->name }}</div>
        <div><strong>Validade:</strong> {{ $plan->valid_until?->format('d/m/Y') ?? '—' }}</div>
    </section>
    <h2>Procedimentos propostos</h2>
    <table><thead><tr><th>Procedimento</th><th>Dente/faces</th><th>Qtd.</th><th class="money">Valor</th><th class="money">Total</th></tr></thead><tbody>
    @foreach ($plan->items as $item)
        <tr><td>{{ $item->description }}</td><td>{{ $item->tooth ?: '—' }} @if($item->surfaces)({{ implode(', ', $item->surfaces) }})@endif</td><td>{{ $item->quantity }}</td><td class="money">R$ {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td><td class="money">R$ {{ number_format((float) $item->total_amount, 2, ',', '.') }}</td></tr>
    @endforeach
    </tbody></table>
    <div class="totals"><div><span>Subtotal</span><strong>R$ {{ number_format((float) $plan->subtotal, 2, ',', '.') }}</strong></div><div><span>Desconto</span><strong>R$ {{ number_format((float) $plan->discount_amount, 2, ',', '.') }}</strong></div><div class="final"><span>Total</span><span>R$ {{ number_format((float) $plan->total_amount, 2, ',', '.') }}</span></div></div>
    @if($plan->commercial_notes)<h2>Observações</h2><p>{{ $plan->commercial_notes }}</p>@endif
    <div class="signatures"><div class="signature">{{ $plan->client->name }}</div><div class="signature">{{ $plan->professional->name }}</div></div>
</main>
</body>
</html>
