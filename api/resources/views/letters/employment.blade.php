<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: bold; src: url('{{ resource_path('fonts/NotoSansEthiopic-Bold.ttf') }}') format('truetype'); }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 12px; color: #0F172A; padding: 40px 48px; line-height: 1.7; }
        .org { font-size: 18px; font-weight: bold; color: #0F4C75; border-bottom: 2px solid #0F4C75; padding-bottom: 8px; }
        .meta { margin-top: 16px; color: #475569; }
        .subject { margin-top: 28px; font-weight: bold; text-decoration: underline; }
        p { margin: 14px 0; }
        .sign { margin-top: 56px; }
    </style>
</head>
<body>
    <div class="org">{{ $organisation }}</div>
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
        {{ __('letters.signature', ['organisation' => $organisation], $locale) }}<br>
        @if ($issuer !== '')
            {{ __('letters.issued_by', ['name' => $issuer], $locale) }}
        @endif
    </div>
</body>
</html>
