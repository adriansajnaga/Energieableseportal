@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $kwh = fn ($v) => number_format((int) $v, 0, ',', '.');
    $settled = $candidates->pluck('settlement')->filter();
@endphp
<span style="font-size:13pt;font-weight:bold;">Abrechnungsübersicht {{ $month->locale('de')->isoFormat('MMMM YYYY') }}</span>
<br>
<span style="font-size:8pt;">Stand {{ now()->format('d.m.Y H:i') }} · {{ $settled->count() }} von {{ $candidates->count() }} Zählern abgerechnet</span>
<br><br>
<table cellspacing="0" cellpadding="3" border="0.5" style="font-size:7.5pt;">
    <tr style="background-color:#e8f5e9;">
        <td width="11%"><b>Zähler</b></td>
        <td width="10%"><b>Ort</b></td>
        <td width="16%"><b>Mieter</b></td>
        <td width="11%" align="center"><b>Zeitraum</b></td>
        <td width="7%" align="right"><b>Stand alt</b></td>
        <td width="7%" align="right"><b>Stand neu</b></td>
        <td width="8%" align="right"><b>kWh</b></td>
        <td width="5%" align="right"><b>€/kWh</b></td>
        <td width="7%" align="right"><b>Netto</b></td>
        <td width="7%" align="right"><b>Brutto</b></td>
        <td width="11%"><b>Rechnung / Status</b></td>
    </tr>
    @foreach ($candidates as $c)
        @php($s = $c->settlement)
        <tr>
            <td>{{ $c->meter->number }}</td>
            <td>{{ $c->meter->location }}</td>
            <td>{{ $c->tenant->name }}</td>
            <td align="center">{{ ($s?->starts_on ?? $c->startReading?->read_on)?->format('d.m.') ?? '–' }} – {{ ($s?->ends_on ?? $c->endsOn)->format('d.m.Y') }}</td>
            <td align="right">{{ $s ? $kwh($s->startReading?->value) : ($c->startReading ? $kwh($c->startReading->value) : '') }}</td>
            <td align="right">{{ $s?->endReading ? $kwh($s->endReading->value) : '' }}</td>
            <td align="right">@if ($s){{ $kwh($s->consumption_kwh) }}@if ($s->meter_factor > 1) x {{ $s->meter_factor }}@endif @endif</td>
            <td align="right">{{ $s ? $money($s->unit_price) : '' }}</td>
            <td align="right">{{ $s ? $money($s->net_amount) : '' }}</td>
            <td align="right">{{ $s ? $money($s->gross_amount) : '' }}</td>
            <td>
                @if (! $s)
                    {{ $c->statusLabel() }}
                @elseif ($s->invoice_number)
                    {{ $s->formattedNumber() }}@if ($s->emailed_at) · versendet @endif
                @elseif ($collective = $s->activeCollective())
                    Sammelrechnung {{ $collective->formattedNumber() }}
                @elseif ($s->is_invoiced)
                    extern abgerechnet
                @else
                    ohne Rechnung
                @endif
            </td>
        </tr>
    @endforeach
    <tr style="background-color:#f5f5f5;">
        <td colspan="6"><b>Summe</b></td>
        <td align="right"><b>{{ $kwh($settled->sum('billed_kwh')) }}</b></td>
        <td></td>
        <td align="right"><b>{{ $money($settled->sum(fn ($s) => (float) $s->net_amount)) }}</b></td>
        <td align="right"><b>{{ $money($settled->sum(fn ($s) => (float) $s->gross_amount)) }}</b></td>
        <td></td>
    </tr>
</table>
@if ($collectives->isNotEmpty())
    <br><br>
    <b>Sammelrechnungen {{ $month->format('m/Y') }}</b>
    <table cellspacing="0" cellpadding="3" border="0.5" style="font-size:7.5pt;">
        @foreach ($collectives as $col)
            <tr>
                <td width="12%">{{ $col->formattedNumber() }}</td>
                <td width="35%">{{ $col->tenant->name }}</td>
                <td width="25%">{{ $col->starts_on->format('d.m.Y') }} – {{ $col->ends_on->format('d.m.Y') }}</td>
                <td width="14%" align="right">{{ $money($col->net_amount) }} netto</td>
                <td width="14%" align="right">{{ $money($col->gross_amount) }} brutto</td>
            </tr>
        @endforeach
    </table>
@endif
