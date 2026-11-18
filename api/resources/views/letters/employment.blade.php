<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: bold; src: url('{{ resource_path('fonts/NotoSansEthiopic-Bold.ttf') }}') format('truetype'); }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 12px; color: #0F172A; padding: 40px 48px; line-height: 1.7; }
        .meta { margin-top: 16px; color: #475569; }
        .subject { margin-top: 28px; font-weight: bold; text-decoration: underline; }
        p { margin: 14px 0; }
        .sign { margin-top: 36px; }
        table.closing { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.closing td { vertical-align: bottom; padding: 0; border: none; }
        .sign-line { border-top: 1px solid #0F172A; margin-top: 56px; padding-top: 4px; width: 220px; color: #475569; font-size: 10px; }
        .stamp-box { border: 1px dashed #94A3B8; width: 130px; height: 100px; text-align: center; color: #94A3B8; font-size: 9px; padding-top: 4px; line-height: 1.3; }
        .doc-footer { position: fixed; bottom: 14px; left: 48px; right: 120px; font-size: 8px; color: #475569; line-height: 1.2; }
    </style>
</head>
<body>
    @include('pdf.partials.letterhead', ['letterhead' => $letterhead])
    <div class="meta">
        {{ __('letters.reference', [], $locale) }}: {{ $reference }}<br>
        {{ __('letters.date', [], $locale) }}: {{ $issued_on }}
    </div>

    <p>{{ $addressed_to ?: __('letters.to_whom', [], $locale) }}</p>

    <div class="subject">{{ __('letters.subject', [], $locale) }}</div>

    <p>
        @if ($left_on)
            {{ __('letters.body_former', ['name' => $name, 'code' => $code, 'organisation' => $organisation, 'from' => $hired_on, 'to' => $left_on], $locale) }}
        @else
            {{ __('letters.body_current', ['name' => $name, 'code' => $code, 'organisation' => $organisation, 'from' => $hired_on], $locale) }}
        @endif
        @if ($position !== '')
            {{ $department !== ''
                ? __('letters.role_department', ['position' => $position, 'department' => $department], $locale)
                : __('letters.role', ['position' => $position], $locale) }}
        @endif
    </p>

    @if ($salary)
        <p>{{ __('letters.salary', ['amount' => $salary], $locale) }}</p>
    @endif

    <p>{{ __('letters.purpose', ['purpose' => $purpose], $locale) }}</p>

    <div class="sign">
        {{ __('letters.signature', ['organisation' => $organisation], $locale) }}
    </div>
    <table class="closing">
        <tr>
            <td>
                <div class="sign-line">
                    @if ($issuer !== '')
                        <strong>{{ $issuer }}</strong><br>
                        @if (($issuer_position ?? '') !== ''){{ $issuer_position }}<br>@endif
                    @endif
                    {{ __('letters.signature_line', [], $locale) }}
                </div>
            </td>
            <td style="width: 140px;">
                <div class="stamp-box">{{ __('letters.stamp', [], $locale) }}</div>
            </td>
        </tr>
    </table>

    <div class="doc-footer">{{ __('letters.footer', ['reference' => $reference, 'date' => $issued_on], $locale) }}</div>
</body>
</html>
