{{-- The signature lines at the foot of a printed document. $labels is the list of lines to sign, already in the reader's language; $stamp, when set, adds a box for the organisation's stamp. Each is a rule with its label under it, and room above it to sign. --}}
<style>
    table.sign { width: 100%; margin-top: 22px; border-collapse: separate; border-spacing: 14px 0; margin-left: -14px; }
    table.sign td { padding-top: 18px; border-top: 1px solid #94A3B8; color: #64748B; }
    table.sign td.stamp { width: 90px; border: 1px dashed #94A3B8; text-align: center; padding: 28px 0 4px 0; }
</style>
<table class="sign">
    <tr>
        @foreach($labels as $label)
        <td style="width: {{ floor(100 / count($labels)) }}%">{{ $label }}</td>
        @endforeach
        @if(! empty($stamp))
        <td class="stamp">{{ $stamp }}</td>
        @endif
    </tr>
</table>
