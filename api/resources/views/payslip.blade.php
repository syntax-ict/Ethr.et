<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0F172A; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #0F4C75; padding-bottom: 10px; margin-bottom: 15px; }
        .header h1 { font-size: 14px; color: #0F4C75; }
        .header p { font-size: 9px; color: #64748B; margin-top: 2px; }
        .info-grid { display: flex; justify-content: space-between; margin-bottom: 15px; }
        .info-block { width: 48%; }
        .info-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .info-label { color: #64748B; font-size: 9px; }
        .info-value { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background: #F1F5F9; text-align: left; padding: 6px 8px; font-size: 9px; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 6px 8px; border-bottom: 1px solid #E2E8F0; }
        .amount { text-align: right; font-family: monospace; }
        .total-row td { font-weight: 700; border-top: 2px solid #0F4C75; border-bottom: none; }
        .net-row td { font-size: 12px; background: #F1F5F9; color: #0F4C75; }
        .footer { text-align: center; margin-top: 20px; font-size: 8px; color: #94A3B8; border-top: 1px solid #E2E8F0; padding-top: 8px; }
        .voided-watermark {
            position: fixed;
            top: 300px;
            left: 60px;
            font-size: 70px;
            font-weight: 700;
            color: #DC2626;
            opacity: 0.25;
            transform: rotate(-30deg);
        }
    </style>
</head>
<body>
    @if($is_voided)
    <div class="voided-watermark">VOIDED</div>
    @endif
    <div class="header">
        <h1>{{ $tenant_name }}</h1>
        <p>Payslip for {{ $period }}</p>
    </div>

    <table>
        <tr>
            <td class="info-label">Employee</td>
            <td><strong>{{ $employee_name }}</strong></td>
            <td class="info-label">Code</td>
            <td><strong>{{ $employee_code }}</strong></td>
        </tr>
        <tr>
            <td class="info-label">Department</td>
            <td>{{ $department }}</td>
            <td class="info-label">Position</td>
            <td>{{ $position }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr><th colspan="2">Earnings</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Basic Salary</td>
                <td class="amount">{{ number_format($basic_salary_cents / 100, 2) }} ETB</td>
            </tr>
            @foreach($allowances as $allowance)
            <tr>
                <td>{{ $allowance['type'] ?? 'Allowance' }}</td>
                <td class="amount">{{ number_format(($allowance['amount_cents'] ?? 0) / 100, 2) }} ETB</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td>Gross Salary</td>
                <td class="amount">{{ number_format($gross_cents / 100, 2) }} ETB</td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr><th colspan="2">Deductions</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Income Tax</td>
                <td class="amount">{{ number_format($income_tax_cents / 100, 2) }} ETB</td>
            </tr>
            <tr>
                <td>Employee Pension (7%)</td>
                <td class="amount">{{ number_format($employee_pension_cents / 100, 2) }} ETB</td>
            </tr>
            @foreach($deductions as $deduction)
            <tr>
                <td>{{ ucfirst($deduction['type'] ?? 'Other') }}</td>
                <td class="amount">{{ number_format(($deduction['amount_cents'] ?? 0) / 100, 2) }} ETB</td>
            </tr>
            @endforeach
            @if($other_deductions_cents > 0 && empty($deductions))
            <tr>
                <td>Other Deductions</td>
                <td class="amount">{{ number_format($other_deductions_cents / 100, 2) }} ETB</td>
            </tr>
            @endif
        </tbody>
    </table>

    <table>
        <tbody>
            <tr class="total-row net-row">
                <td>Net Pay</td>
                <td class="amount">{{ number_format($net_cents / 100, 2) }} ETB</td>
            </tr>
        </tbody>
    </table>

    <table>
        <tbody>
            <tr>
                <td class="info-label">Employer Pension Contribution (11%)</td>
                <td class="amount" style="color: #64748B;">{{ number_format($employer_pension_cents / 100, 2) }} ETB</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>Generated on {{ $generated_at }} (EAT) &bull; This is a computer-generated document</p>
        <p>{{ $tenant_name }} &mdash; Powered by ETHR</p>
    </div>
</body>
</html>
