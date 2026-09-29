@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $kwh = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp
<span style="font-size:13pt;font-weight:bold;">Bericht Hauptzähler {{ $month->format('m/Y') }}</span>
<br><br>
@foreach ($meters as $row)
    <table cellspacing="0" cellpadding="4" border="0.5">
        <tr style="background-color:#e8f5e9;"><td width="100%"><b>{{ $row['meter']->location }} – {{ $row['meter']->number }}</b></td></tr>
        <tr>
            <td>
                @if ($row['price'])
                    Rechnung {{ $row['price']->supplier_invoice_number }}: {{ $kwh($row['price']->consumption_kwh) }} kWh,
                    {{ $money($row['price']->net_amount) }} € netto, Strompreis {{ number_format((float) $row['price']->net_price, 5, ',', '.') }} €/kWh
                @else
                    Kein Strompreis erfasst.
                @endif
            </td>
        </tr>
    </table>
    <table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
        <tr style="background-color:#f5f5f5;">
            <td width="22%"><b>Unterzähler</b></td>
            <td width="34%"><b>Mieter</b></td>
            <td width="16%" align="right"><b>kWh</b></td>
            <td width="12%" align="right"><b>€/kWh</b></td>
            <td width="16%" align="right"><b>Netto EUR</b></td>
        </tr>
        @foreach ($row['settlements'] as $s)
            <tr>
                <td>{{ $s->meter->number }}</td>
                <td>{{ $s->tenant->name }}</td>
                <td align="right">{{ $kwh($s->billed_kwh) }}</td>
                <td align="right">{{ $money($s->unit_price) }}</td>
                <td align="right">{{ $money($s->net_amount) }}</td>
            </tr>
        @endforeach
        <tr style="background-color:#f5f5f5;">
            <td colspan="2"><b>Summe Unterzähler</b></td>
            <td align="right"><b>{{ $kwh($row['settlements']->sum('billed_kwh')) }}</b></td>
            <td></td>
            <td align="right"><b>{{ $money($row['settlements']->sum('net_amount')) }}</b></td>
        </tr>
        @if ($row['price'])
            <tr>
                <td colspan="2">Abweichung zur Versorgerrechnung</td>
                <td align="right">{{ $kwh($row['price']->consumption_kwh - $row['settlements']->sum('billed_kwh')) }}</td>
                <td></td>
                <td align="right">{{ $money($row['settlements']->sum('net_amount') - $row['price']->net_amount) }}</td>
            </tr>
        @endif
    </table>
    <br><br>
@endforeach
