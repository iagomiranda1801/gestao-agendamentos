<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento de tatuagem</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .studio { margin: 0 0 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { width: 34%; background: #f3f4f6; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Orçamento de tatuagem</h1>
    <p class="studio">{{ $company->name }} · Versão {{ $quote->version }} · {{ $quote->situationLabel() }}</p>
    <table>
        <tr>
            <th>Cliente</th>
            <td>{{ $request->client?->name }}</td>
        </tr>
        <tr>
            <th>Desenho</th>
            <td>{{ $request->description }}</td>
        </tr>
        <tr>
            <th>Local do corpo</th>
            <td>{{ $request->body_placement }}</td>
        </tr>
        @if (filled($request->size_description))
            <tr>
                <th>Tamanho aproximado</th>
                <td>{{ $request->size_description }}</td>
            </tr>
        @endif
        <tr>
            <th>Valor</th>
            <td>{{ $quote->priceLabel() }}</td>
        </tr>
        <tr>
            <th>Sessões previstas</th>
            <td>{{ $quote->sessions }}</td>
        </tr>
        @if (filled($quote->deposit_amount) && (float) $quote->deposit_amount > 0)
            <tr>
                <th>Sinal</th>
                <td>R$ {{ number_format((float) $quote->deposit_amount, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if ($quote->valid_until)
            <tr>
                <th>Válido até</th>
                <td>{{ $quote->valid_until->format('d/m/Y') }}</td>
            </tr>
        @endif
        @if (filled($quote->conditions))
            <tr>
                <th>Condições</th>
                <td>{{ $quote->conditions }}</td>
            </tr>
        @endif
    </table>
</body>
</html>
