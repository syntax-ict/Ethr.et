<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: bold; src: url('{{ resource_path('fonts/NotoSansEthiopic-Bold.ttf') }}') format('truetype'); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 8.5px; color: #0F172A; padding: 18px; }
        h1 { font-size: 13px; color: #0F4C75; margin-bottom: 6px; }
        .meta { width: 100%; margin-bottom: 8px; }
        .meta td { padding: 2px 8px 2px 0; border: none; }
        .meta .label { color: #64748B; width: 90px; }
        .notice { border: 1px solid #E8A838; background: #FFF8E6; padding: 5px 8px; margin-bottom: 8px; }
        .draft { border: 1px solid #B91C1C; color: #B91C1C; padding: 5px 8px; margin-bottom: 8px; font-weight: bold; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #F1F5F9; text-align: left; padding: 4px 5px; font-size: 7.5px; color: #475569; text-transform: uppercase; }
        table.rows td { padding: 4px 5px; border-bottom: 1px solid #E2E8F0; }
        .amount { text-align: right; font-family: monospace; white-space: nowrap; }
        .missing { color: #B91C1C; }
        tr.total td { font-weight: bold; border-top: 2px solid #0F4C75; border-bottom: none; }
        .sign { margin-top: 22px; width: 100%; }
        .sign td { width: 33%; padding-top: 18px; border-top: 1px solid #94A3B8; color: #64748B; }
        .footer { margin-top: 10px; font-size: 7px; color: #94A3B8; }
    </style>
</head>
<body>
    @php($money = fn (int $cents): string => number_format($cents / 100, 2))
    <h1>{{ $header['title'] }}</h1>

    <table class="meta">
        <tr><td class="label">Employer</td><td>{{ $header['employer_name'] }}</td>
            <td class="label">Employer TIN</td><td class="{{ $header['employer_tin'] ? '' : 'missing' }}">{{ $header['employer_tin'] ?? 'Not set — Settings → Organization' }}</td></tr>
        <tr><td class="label">Period</td><td>{{ $header['period'] }}</td>
            @if($schedule === 'income-tax')
                <td class="label">Tax centre</td><td>{{ $header['tax_center'] ?? '—' }}</td>
            @else
                <td class="label">Scheme</td><td>{{ $header['scheme'] }}</td>
            @endif
        </tr>
    </table>

    @unless($header['approved'])
        <div class="draft">{{ $header['status'] }}</div>
    @endunless
    <div class="notice">{{ $header['notice'] }}</div>

    <table class="rows">
        <thead>
            <tr>
                <th>No</th><th>Employee TIN</th><th>Employee name</th><th>Code</th>
                @if($schedule === 'income-tax')
                    <th>Start date</th>
                    <th class="amount">Basic salary</th><th class="amount">Overtime</th><th class="amount">Allowances</th>
                    <th class="amount">Taxable income</th><th class="amount">Tax withheld</th>
                @else
                    <th class="amount">Basic salary</th><th class="amount">Employee 7%</th>
                    <th class="amount">Employer 11%</th><th class="amount">Total 18%</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr>
                    <td>{{ $r['no'] }}</td>
                    <td class="{{ $r['tin'] ? '' : 'missing' }}">{{ $r['tin'] ?? 'missing' }}</td>
                    <td>{{ $r['employee_name'] }}</td>
                    <td>{{ $r['employee_code'] }}</td>
                    @if($schedule === 'income-tax')
                        <td>{{ $r['start_date'] ?? '' }}</td>
                        <td class="amount">{{ $money($r['basic_cents']) }}</td>
                        <td class="amount">{{ $money($r['overtime_cents']) }}</td>
                        <td class="amount">{{ $money($r['allowances_cents']) }}</td>
                        <td class="amount">{{ $money($r['taxable_cents']) }}</td>
                        <td class="amount">{{ $money($r['income_tax_cents']) }}</td>
                    @else
                        <td class="amount">{{ $money($r['basic_cents']) }}</td>
                        <td class="amount">{{ $money($r['employee_pension_cents']) }}</td>
                        <td class="amount">{{ $money($r['employer_pension_cents']) }}</td>
                        <td class="amount">{{ $money($r['total_pension_cents']) }}</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="{{ $schedule === 'income-tax' ? 5 : 4 }}">Total — {{ count($rows) }} employees (ETB)</td>
                @foreach($totals as $cents)
                    <td class="amount">{{ $money($cents) }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <table class="sign">
        <tr><td>Prepared by</td><td>Checked by</td><td>Authorised signature and stamp</td></tr>
    </table>

    <p class="footer">Generated {{ $header['generated_at'] }} (EAT) by ETHR.</p>
</body>
</html>
