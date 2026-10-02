@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $kwh = fn ($v) => number_format((int) $v, 0, ',', '.');
    $isCancellation = $s->type === \App\Enums\SettlementType::Cancellation;
    $isFlatRate = $s->isFlatRate();
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
    @unless ($isFlatRate)
        <tr><td></td><td>{{ $isCancellation ? 'Stornorechnung Nr.:' : 'Rechnungsnr.:' }}</td><td>{{ $s->formattedNumber() }}</td></tr>
    @endunless
    @if ($isCancellation)
        <tr><td></td><td>zu Rechnung:</td><td>{{ $s->cancels?->formattedNumber() }}</td></tr>
    @endif
</table>
<br><br>
<span style="font-size:11pt;font-weight:bold;">
    {{ $isFlatRate ? 'Kontrollabrechnung (Pauschale – keine Rechnung)' : ($isCancellation ? 'Stornierung Ihrer Stromverbrauchsabrechnung' : 'Ihre Stromverbrauchsabrechnung') }} für den Zeitraum vom {{ $s->starts_on->format('d.m.Y') }} bis {{ $s->ends_on->format('d.m.Y') }}
</span>
<br><br>
<table cellspacing="0" cellpadding="0" border="0">
    <tr><td width="25%">Verbrauchsstelle:</td><td width="75%">{!! nl2br(e($site)) !!}</td></tr>
</table>
<br><br>
<table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
    <tr style="background-color:#e8f5e9;">
        <td rowspan="2" width="18%"><b>Zähler</b></td>
        <td rowspan="2" width="18%" align="center"><b>Gebäude/Ort</b></td>
        <td colspan="2" width="24%" align="center"><b>Zeitraum</b></td>
        <td colspan="2" width="24%" align="center"><b>Zählerstand</b></td>
        <td rowspan="2" width="16%" align="right"><b>Verbrauch</b></td>
    </tr>
    <tr style="background-color:#e8f5e9;">
        <td align="center">von</td><td align="center">bis</td><td align="center">alt</td><td align="center">neu</td>
    </tr>
    <tr>
        <td>{{ $s->meter->number }}</td>
        <td align="center">{{ $s->meter->location }}</td>
        <td align="center">{{ $s->starts_on->format('d.m.Y') }}</td>
        <td align="center">{{ $s->ends_on->format('d.m.Y') }}</td>
        <td align="center">{{ $kwh($s->startReading?->value) }}</td>
        <td align="center">{{ $s->endReading ? $kwh($s->endReading->value) : $kwh(($s->startReading?->value ?? 0) + abs($s->consumption_kwh)) }}</td>
        <td align="right">{{ $kwh($s->consumption_kwh) }} kWh</td>
    </tr>
</table>
<br><br>
<table cellspacing="0" cellpadding="3" border="0.5" style="font-size:8pt;">
    <tr style="background-color:#e8f5e9;">
        <td width="84%"><b>Ihre Preise</b></td>
        <td width="16%" align="right">Netto EUR</td>
    </tr>
    <tr>
        <td>Verbrauchspreis – {{ $s->meter->number }}:
            {{ $kwh($s->consumption_kwh) }} kWh @if ($s->meter_factor > 1) x Zählerfaktor {{ $s->meter_factor }} = {{ $kwh($s->billed_kwh) }} kWh @endif
            x {{ $money($s->unit_price) }} €/kWh</td>
        <td align="right">{{ $money($s->net_amount) }}</td>
    </tr>
</table>
<br><br>
<table cellspacing="0" cellpadding="4" border="0.5">
    <tr>
        <td width="84%"><b>Summe netto EUR</b><br>Umsatzsteuer ({{ rtrim(rtrim(number_format((float) $s->vat_rate, 2, ',', ''), '0'), ',') }} %)<br><b>Rechnungsbetrag brutto EUR</b></td>
        <td width="16%" align="right"><b>{{ $money($s->net_amount) }}</b><br>{{ $money($s->vat_amount) }}<br><b>{{ $money($s->gross_amount) }}</b></td>
    </tr>
</table>
<br><br>
@unless ($isCancellation || $isFlatRate)
    <div style="text-align:center;">Wir bedanken uns für Ihr Vertrauen und haben auf Basis Ihrer Verbrauchswerte Ihre Abrechnung erstellt.</div>
@endunless
