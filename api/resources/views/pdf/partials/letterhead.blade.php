{{-- The top of a printed document: logo, name, contact lines, a rule. $letterhead comes from the Letterhead support class; $compact joins the contact lines into one, for a small page. --}}
<style>
    table.lh { width: 100%; border-collapse: collapse; margin-bottom: 0; line-height: 1.3; }
    table.lh td { padding: 0; border: none; vertical-align: middle; }
    table.lh td.lh-logo { width: 1%; padding-right: 12px; }
    table.lh img { height: 48px; }
    table.lh .lh-name { font-size: 15px; font-weight: bold; color: #0F4C75; }
    table.lh .lh-line { font-size: 8.5px; color: #475569; margin-top: 1px; }
    div.lh-rule { border-top: 2px solid #0F4C75; margin: 8px 0 14px 0; }
</style>
<table class="lh">
    <tr>
        @if(! empty($letterhead['logo']))
        <td class="lh-logo"><img src="{{ $letterhead['logo'] }}" alt=""></td>
        @endif
        <td>
            <div class="lh-name">{{ $letterhead['name'] }}</div>
            @if(! empty($compact))
            @if($letterhead['lines'] !== [])<div class="lh-line">{{ implode('   |   ', $letterhead['lines']) }}</div>@endif
            @else
            @foreach($letterhead['lines'] as $line)
            <div class="lh-line">{{ $line }}</div>
            @endforeach
            @endif
        </td>
    </tr>
</table>
<div class="lh-rule"></div>
