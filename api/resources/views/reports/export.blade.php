<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0F172A; padding: 20px; }
        .header { border-bottom: 2px solid #0F4C75; padding-bottom: 10px; margin-bottom: 15px; }
        .header h1 { font-size: 14px; color: #0F4C75; }
        .header p { font-size: 9px; color: #64748B; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #F1F5F9; text-align: left; padding: 6px 8px; font-size: 8px; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 6px 8px; border-bottom: 1px solid #E2E8F0; }
        .footer { text-align: center; margin-top: 20px; font-size: 8px; color: #94A3B8; border-top: 1px solid #E2E8F0; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ETHR Report Export</h1>
        <p>Generated on {{ now()->timezone('Africa/Addis_Ababa')->format('d/m/Y H:i') }} (EAT)</p>
    </div>

    <table>
        <thead>
            <tr>
                @foreach($columns as $column)
                <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                @foreach($columns as $column)
                <td>{{ $row[$column] ?? '' }}</td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>{{ count($rows) }} record(s) &bull; Powered by ETHR</p>
    </div>
</body>
</html>
