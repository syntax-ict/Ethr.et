<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: bold; src: url('{{ resource_path('fonts/NotoSansEthiopic-Bold.ttf') }}') format('truetype'); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 8.5px; color: #0F172A; padding: 18px; }
        h1 { font-size: 13px; color: #0F4C75; margin-bottom: 2px; }
        .agency { color: #475569; margin-bottom: 6px; }
        .meta { width: 100%; margin-bottom: 8px; }
        .meta td { padding: 2px 8px 2px 0; border: none; }
        .meta .label { color: #64748B; width: 90px; }
        .basis { border: 1px solid #CBD5E1; background: #F8FAFC; padding: 5px 8px; margin-bottom: 6px; }
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
    <h1>{{ $header['title'] }}</h1>
    <p class="agency">{{ $header['agency'] }}</p>

    <table class="meta">
        <tr><td class="label">Employer</td><td>{{ $header['employer_name'] }}</td>
            <td class="label">Employer TIN</td><td class="{{ $header['employer_tin'] ? '' : 'missing' }}">{{ $header['employer_tin'] ?? 'Not set — Settings → Payroll' }}</td></tr>
        <tr><td class="label">Period</td><td>{{ $header['period'] }}</td>
            <td class="label">Tax centre</td><td>{{ $header['tax_center'] ?? '—' }}</td></tr>
    </table>

    @unless($header['approved'])
        <div class="draft">{{ $header['status'] }}</div>
    @endunless
    <div class="basis"><strong>Rules applied.</strong> {{ $header['basis'] }}</div>
    <div class="notice">{{ $header['notice'] }}</div>

    <table class="rows">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th class="{{ $column['money'] ? 'amount' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($cells as $row)
                <tr>
                    @foreach($row as $i => $value)
                        <td class="{{ $columns[$i]['money'] ? 'amount' : '' }} {{ $columns[$i]['key'] === 'tin' && $value === 'missing' ? 'missing' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                @foreach($total_cells as $i => $value)
                    <td class="{{ $columns[$i]['money'] ? 'amount' : '' }}">{{ $value }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <table class="sign">
        <tr><td>Prepared by</td><td>Checked by</td><td>Authorised signature and stamp</td></tr>
    </table>

    <p class="footer">Generated {{ $header['generated_at'] }} (EAT) by ETHR. Amounts in ETB.</p>
</body>
</html>
