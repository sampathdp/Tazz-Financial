<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    .sub { color: #555; font-size: 11px; margin-bottom: 14px; }
    .meta { color: #555; font-size: 10.5px; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #555; border-bottom: 1px solid #ccc; padding: 5px 6px; }
    td { padding: 5px 6px; border-bottom: 1px solid #eee; }
    .amount { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .section-title { font-size: 13px; font-weight: bold; margin: 18px 0 6px; }
    .total-row td { border-top: 1px solid #999; border-bottom: none; font-weight: bold; padding-top: 6px; }
    .net-profit { margin-top: 10px; padding: 10px 12px; background: #f4f4f4; }
    .net-profit table { margin: 0; }
    .net-profit table td { border: none; padding: 3px 0; font-size: 13px; font-weight: bold; }
</style>
</head>
<body>
    <h1>{{ $companyName }}</h1>
    <div class="sub">Profit &amp; Loss Statement — Cash and Accrual Basis</div>
    <div class="meta">
        Period: {{ $from ?: 'Inception' }} to {{ $to ?: 'Today' }} &nbsp;|&nbsp; Generated: {{ $generatedAt->format('Y-m-d H:i') }}
    </div>

    <div class="section-title">Income</div>
    <table>
        <thead><tr><th style="width:13%">Code</th><th>Name</th><th style="width:18%" class="amount">Cash Basis</th><th style="width:18%" class="amount">Accrual Basis</th></tr></thead>
        <tbody>
        @forelse ($income as $row)
            <tr><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="amount">{{ number_format((float) $row['cash_balance'], 2) }}</td><td class="amount">{{ number_format((float) $row['accrual_balance'], 2) }}</td></tr>
        @empty
            <tr><td colspan="4">No income posted in this range</td></tr>
        @endforelse
        <tr class="total-row"><td></td><td>Total Income</td><td class="amount">{{ number_format((float) $totalIncomeCash, 2) }}</td><td class="amount">{{ number_format((float) $totalIncomeAccrual, 2) }}</td></tr>
        </tbody>
    </table>

    <div class="section-title">Expense</div>
    <table>
        <thead><tr><th style="width:15%">Code</th><th>Name</th><th style="width:20%" class="amount">Amount</th></tr></thead>
        <tbody>
        @forelse ($expense as $row)
            <tr><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="amount">{{ number_format((float) $row['balance'], 2) }}</td></tr>
        @empty
            <tr><td colspan="3">No expense posted in this range</td></tr>
        @endforelse
        <tr class="total-row"><td></td><td>Total Expense</td><td class="amount">{{ number_format((float) $totalExpense, 2) }}</td></tr>
        </tbody>
    </table>

    <div class="net-profit">
        <table>
            <tr><td>Net Profit (Cash Basis)</td><td class="amount">{{ number_format((float) $netProfitCash, 2) }}</td></tr>
            <tr><td>Net Profit (Accrual Basis)</td><td class="amount">{{ number_format((float) $netProfitAccrual, 2) }}</td></tr>
        </table>
    </div>
</body>
</html>
