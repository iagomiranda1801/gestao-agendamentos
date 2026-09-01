<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Receitas e gastos</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        p { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; }
        td.num, th.num { text-align: right; }
        .summary td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>Receitas e gastos</h1>
    <p>
        {{ $company->name }}<br>
        Período: {{ $report->periodStartLabel }} a {{ $report->periodEndLabel }}
    </p>
    <table class="summary">
        <tr>
            <td>Receitas</td>
            <td class="num">R$ {{ number_format((float) $report->incomeTotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Gastos</td>
            <td class="num">R$ {{ number_format((float) $report->expenseTotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Saldo</td>
            <td class="num">R$ {{ number_format((float) $report->balance, 2, ',', '.') }}</td>
        </tr>
    </table>
    <br>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th>Descrição</th>
                <th>Conta</th>
                <th>Movimento</th>
                <th class="num">Valor</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report->rows as $row)
                <tr>
                    <td>{{ $row->occurredAtLocal }}</td>
                    <td>{{ $row->typeLabel }}</td>
                    <td>{{ $row->description }}</td>
                    <td>{{ $row->accountName }}</td>
                    <td>{{ $row->directionLabel }}</td>
                    <td class="num">R$ {{ number_format((float) $row->amount, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Nenhum lançamento no período.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
