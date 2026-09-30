@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $kwh = fn ($v) => number_format((int) $v, 0, ',', '.');
    $net = $settlements->sum('net_amount');
    $vat = $settlements->sum('vat_amount');
@endphp
<table cellspacing="0" cellpadding="0" border="0">
    <tr><td width="62%"><b>Mieter:</b></td><td width="38%"><b>Vermieter:</b></td></tr>
    <tr>
        <td>{{ $tenant->name }}<br>{{ $tenant->street }}<br>{{ $tenant->zip }} {{ $tenant->city }}<br>Kunden-Nr.: {{ $tenant->debtor_number }}</td>
        <td>{{ $landlord['name'] }}<br>{{ $landlord['street'] }}<br>{{ $landlord['city'] }}<br>USt-IdNr. {{ $landlord['vat_id'] }}</td>
    </tr>
</table>
<br><br>
<span style="font-size:11pt;font-weight:bold;">Stromverbrauchsbericht für das Jahr {{ $year }}</span>
<br><br>
<table cellspacing="0" cellpadding="0" border="0">
    <tr><td width="25%">Zähler:<br>Zählerfaktor:<br>Gebäude/Ort:</td><td width="75%"><b>{{ $meter->number }}<br>{{ $meter->factor }}<br>{{ $meter->location }}</b></td></tr>
</table>
<br><br>
<table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
    <tr style="background-color:#e8f5e9;">
        <td width="11%" align="center"><b>Rechnung</b></td>
        <td width="11%" align="center"><b>von</b></td>
        <td width="11%" align="center"><b>bis</b></td>
        <td width="12%" align="center"><b>Stand alt</b></td>
        <td width="12%" align="center"><b>Stand neu</b></td>
        <td width="15%" align="center"><b>Verbrauch kWh</b></td>
        <td width="13%" align="center"><b>Preis €/kWh</b></td>
        <td width="15%" align="right"><b>Entgelt EUR</b></td>
    </tr>
    @forelse ($settlements as $s)
        <tr>
            <td align="center">{{ $s->invoiceLabel() }}</td>
            <td align="center">{{ $s->starts_on->format('d.m.Y') }}</td>
            <td align="center">{{ $s->ends_on->format('d.m.Y') }}</td>
            <td align="center">{{ $kwh($s->startReading?->value) }}</td>
            <td align="center">{{ $s->endReading ? $kwh($s->endReading->value) : '' }}</td>
            <td align="center">{{ $kwh($s->consumption_kwh) }}@if ($s->meter_factor > 1) x {{ $s->meter_factor }}@endif</td>
            <td align="center">{{ $money($s->unit_price) }}</td>
            <td align="right">{{ $money($s->net_amount) }}</td>
        </tr>
    @empty
        <tr><td colspan="8" align="center">Keine Abrechnungen im Jahr {{ $year }}.</td></tr>
    @endforelse
</table>
<br><br>
<table cellspacing="0" cellpadding="4" border="0.5">
    <tr style="background-color:#e8f5e9;">
        <td width="85%"><b>Summe Verbrauch</b><br><b>Summe netto EUR</b><br>Umsatzsteuer<br><b>Summe brutto EUR</b></td>
        <td width="15%" align="right"><b>{{ $kwh($settlements->sum('billed_kwh')) }} kWh</b><br><b>{{ $money($net) }}</b><br>{{ $money($vat) }}<br><b>{{ $money($net + $vat) }}</b></td>
    </tr>
</table>
