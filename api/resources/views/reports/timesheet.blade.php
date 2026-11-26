<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Ethiopic'; font-weight: bold; src: url('{{ resource_path('fonts/NotoSansEthiopic-Bold.ttf') }}') format('truetype'); }
        @page { margin: 22px 24px 30px 24px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif; font-size: 7.5px; color: #0F172A; }
        h1 { font-size: 13px; color: #0F4C75; }
        h1 .am { font-size: 12px; color: #475569; }
        h2 { font-size: 9px; color: #0F4C75; margin: 10px 0 4px 0; }
        .meta { color: #475569; margin-top: 2px; }
        .status { display: inline-block; border: 1px solid #94A3B8; padding: 1px 6px; font-weight: bold; color: #0F172A; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th { background: #F1F5F9; color: #475569; font-size: 6.5px; padding: 3px 1px; border: 1px solid #CBD5E1; text-align: center; }
        table.grid th.weekend { background: #E2E8F0; }
        table.grid th.left { text-align: left; padding-left: 3px; }
        table.grid td { border: 1px solid #CBD5E1; padding: 1px; text-align: center; font-size: 6.5px; height: 17px; }
        table.grid td.person { text-align: left; padding: 1px 3px; overflow: hidden; }
        table.grid td.person .sub { color: #64748B; font-size: 6px; }
        table.grid td.num { text-align: center; font-size: 7px; }
        table.grid td.locked { border: 1.5px solid #475569; }
        table.grid tr.total td { font-weight: bold; background: #F1F5F9; }
        .pagebreak { page-break-after: always; }
        .legend td { border: none; padding: 1px 10px 1px 0; text-align: left; font-size: 7px; }
        .legend .chip { display: inline-block; min-width: 14px; text-align: center; font-weight: bold; padding: 0 2px; border: 1px solid #CBD5E1; }
        ul.rules { margin: 2px 0 0 12px; }
        ul.rules li { margin-bottom: 1.5px; }
        .warn { color: #B91C1C; font-weight: bold; }
    </style>
</head>
<body>

@include('pdf.partials.letterhead', ['letterhead' => $letterhead, 'compact' => true])

<h1>{{ $titles['en'] }} <span class="am">/ {{ $titles['am'] }}</span></h1>
<p class="meta">{{ $period }} &nbsp;&bull;&nbsp; {{ $generated }}</p>
<p class="meta">
    @if($status !== null)<span class="status">{{ $labels['status'] }}: {{ $status }}</span>&nbsp;@endif
    @foreach($filters as $line){{ $line }}@if(! $loop->last) &nbsp;&bull;&nbsp; @endif @endforeach
</p>

{{-- Section 1: the days --}}
<h2>{{ $labels['days'] }}</h2>
<table class="grid">
    <colgroup>
        <col style="width: 18px"><col style="width: 40px"><col style="width: 112px">
        @foreach($days as $day)<col style="width: 15.5px">@endforeach
        <col style="width: 18px">
    </colgroup>
    <thead>
        <tr>
            <th>{{ $labels['no'] }}</th>
            <th>{{ $labels['id'] }}</th>
            <th class="left">{{ $labels['name'] }}</th>
            @foreach($days as $day)
            <th class="{{ $day['weekend'] ? 'weekend' : '' }}">{{ $day['number'] }}</th>
            @endforeach
            <th>&#10003;</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
        <tr>
            <td>{{ $row['no'] }}</td>
            <td>{{ $row['code'] }}</td>
            <td class="person">
                {{ $locale === 'am' && $row['name_am'] ? $row['name_am'] : $row['name'] }}
                <div class="sub">
                    @if($row['name_am'] && $locale !== 'am'){{ $row['name_am'] }} &bull; @endif
                    {{ collect([$row['department'], $row['position'], $row['shift'], $row['device'] ? $labels['device'].' '.$row['device'] : null])->filter()->implode(' · ') }}
                </div>
            </td>
            @foreach($row['cells'] as $cell)
            <td class="{{ $cell['locked'] ? 'locked' : '' }}" style="background: {{ $cell['tone'] }}">{{ $cell['short'] }}</td>
            @endforeach
            <td>@if($row['check_ok'])&#10003;@else<span class="warn" title="{{ $labels['check'] }}">!</span>@endif</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="pagebreak"></div>

{{-- Section 2: the summary, with the people in the same order --}}
<h2>{{ $labels['summary'] }}</h2>
<table class="grid">
    <thead>
        <tr>
            <th style="width: 18px">{{ $labels['no'] }}</th>
            <th class="left" style="width: 120px">{{ $labels['name'] }}</th>
            @foreach($columns as $column)
            <th>{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
        <tr>
            <td>{{ $row['no'] }}</td>
            <td class="person">{{ $locale === 'am' && $row['name_am'] ? $row['name_am'] : $row['name'] }}</td>
            @foreach($row['figures'] as $figure)
            <td class="num">{{ $figure }}</td>
            @endforeach
        </tr>
        @endforeach
        <tr class="total">
            <td></td>
            <td class="person">{{ $labels['grand_total'] }} ({{ $people }})</td>
            @foreach($totals as $figure)
            <td class="num">{{ $figure }}</td>
            @endforeach
        </tr>
    </tbody>
</table>

@if(count($departments) > 1)
<h2>{{ $labels['totals'] }}</h2>
<table class="grid">
    <thead>
        <tr>
            <th class="left" style="width: 138px">{{ $labels['department'] }}</th>
            @foreach($columns as $column)
            <th>{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($departments as $group)
        <tr>
            <td class="person">{{ $group['name'] }} ({{ $group['employees'] }})</td>
            @foreach($group['figures'] as $figure)
            <td class="num">{{ $figure }}</td>
            @endforeach
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2>{{ $labels['legend'] }}</h2>
<table class="legend">
    <tr>
        @foreach($legend as $item)
        <td><span class="chip" style="background: {{ $item['tone'] }}">{{ $item['short'] }}</span> {{ $item['label'] }}</td>
        @if($loop->iteration % 5 === 0 && ! $loop->last)</tr><tr>@endif
        @endforeach
    </tr>
</table>

<h2>{{ $labels['rules'] }}</h2>
<ul class="rules">
    @foreach($rules as $rule)
    <li>{{ $rule }}</li>
    @endforeach
</ul>

@include('pdf.partials.signatures', ['labels' => $signatures, 'stamp' => $stamp])
</body>
</html>
