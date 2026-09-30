@php
    $money = fn ($v) => number_format((float) $v * $sign, 2, ',', '.');
    $kwh = fn ($v) => number_format((int) $v * $sign, 0, ',', '.');
    $isCancellation = $s->type === \App\Enums\SettlementType::Cancellation;
@endphp
<table cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td width="62%"><b>Mieter:</b></td>
        <td width="38%"><b>Vermieter:</b></td>
    </tr>
    <tr>
        <td>{{ $s->tenant->name }}<br>{{ $s->tenant->street }}<br>{{ $s->tenant->zip }} {{ $s->tenant->city }}<br>Kunden-Nr.: {{ $s->tenant->debtor_number }}</td>
        <td>{{ $landlord['name'] }}<br>{{ $landlord['street'] }}<br>{{ $landlord['city'] }}<br>USt-IdNr. {{ $landlord['vat_id'] }}</td>
    </tr>
</table>
<br><br>
<table cellspacing="0" cellpadding="0" border="0">
    <tr><td width="50%"></td><td width="20%">Rechnungsdatum:</td><td width="30%">{{ $s->invoice_date?->format('d.m.Y') ?? '---' }}</td></tr>
    <tr><td></td><td>{{ $isCancellation ? 'Stornorechnung Nr.:' : 'Rechnungsnr.:' }}</td><td>{{ $s->formattedNumber() }}</td></tr>
    @if ($isCancellation)
        <tr><td></td><td>zu Rechnung:</td><td>{{ $s->cancels?->formattedNumber() }}</td></tr>
    @endif
</table>
<br><br>
<span style="font-size:11pt;font-weight:bold;">
    {{ $isCancellation ? 'Stornierung Ihrer Stromverbrauchsabrechnung' : 'Ihre Stromverbrauchsabrechnung' }} für den Zeitraum vom {{ $s->starts_on->format('d.m.Y') }} bis {{ $s->ends_on->format('d.m.Y') }}
</span>
<br><br>
<table cellspacing="0" cellpadding="0" border="0">
    <tr><td width="25%">Verbrauchsstelle:</td><td width="75%">{!! nl2br(e($site)) !!}</td></tr>
</table>
<br><br>
@foreach ($items as $meterItems)
    @php($meter = $meterItems->first()->meter)
    <table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
        <tr style="background-color:#e8f5e9;">
            <td width="100%"><b>Zähler {{ $meter->number }}</b>@if ($meter->location) &nbsp;·&nbsp; {{ $meter->location }}@endif @if ($meter->factor > 1) &nbsp;·&nbsp; Zählerfaktor {{ $meter->factor }}@endif</td>
        </tr>
    </table>
    <table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
        <tr style="background-color:#f5f5f5;">
            <td width="10%" align="center"><b>Monat</b></td>
            <td width="13%" align="center"><b>von</b></td>
            <td width="13%" align="center"><b>bis</b></td>
            <td width="12%" align="center"><b>Stand alt</b></td>
            <td width="12%" align="center"><b>Stand neu</b></td>
            <td width="16%" align="right"><b>Verbrauch</b></td>
            <td width="10%" align="right"><b>€/kWh</b></td>
            <td width="14%" align="right"><b>Netto EUR</b></td>
        </tr>
        @foreach ($meterItems as $item)
            <tr>
                <td align="center">{{ $item->period->format('m/Y') }}</td>
                <td align="center">{{ $item->starts_on->format('d.m.Y') }}</td>
                <td align="center">{{ $item->ends_on->format('d.m.Y') }}</td>
                <td align="center">{{ number_format((int) $item->startReading?->value, 0, ',', '.') }}</td>
                <td align="center">{{ $item->endReading ? number_format((int) $item->endReading->value, 0, ',', '.') : '' }}</td>
                <td align="right">{{ $kwh($item->consumption_kwh) }}@if ($item->meter_factor > 1) x {{ $item->meter_factor }}@endif kWh</td>
                <td align="right">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                <td align="right">{{ $money($item->net_amount) }}</td>
            </tr>
        @endforeach
        <tr style="background-color:#f5f5f5;">
            <td colspan="5"><b>Summe Zähler {{ $meter->number }}</b></td>
            <td align="right"><b>{{ $kwh($meterItems->sum('billed_kwh')) }} kWh</b></td>
            <td></td>
            <td align="right"><b>{{ $money($meterItems->sum(fn ($i) => (float) $i->net_amount)) }}</b></td>
        </tr>
    </table>
    <br><br>
@endforeach
<table cellspacing="0" cellpadding="4" border="0.5">
    <tr>
        <td width="84%"><b>Summe netto EUR</b><br>Umsatzsteuer ({{ rtrim(rtrim(number_format((float) $s->vat_rate, 2, ',', ''), '0'), ',') }} %)<br><b>Rechnungsbetrag brutto EUR</b></td>
        <td width="16%" align="right"><b>{{ number_format((float) $s->net_amount, 2, ',', '.') }}</b><br>{{ number_format((float) $s->vat_amount, 2, ',', '.') }}<br><b>{{ number_format((float) $s->gross_amount, 2, ',', '.') }}</b></td>
    </tr>
</table>
<br><br>
@unless ($isCancellation)
    <div style="text-align:center;">Wir bedanken uns für Ihr Vertrauen und haben auf Basis Ihrer Verbrauchswerte Ihre Abrechnung erstellt.</div>
@endunless
