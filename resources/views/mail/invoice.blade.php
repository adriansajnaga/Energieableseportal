<x-mail::message>
# Ihre Stromabrechnung {{ $settlement->period->format('m/Y') }}

Guten Tag {{ $settlement->tenant->name }},

anbei erhalten Sie die Stromverbrauchsabrechnung für den Zeitraum vom {{ $settlement->starts_on->format('d.m.Y') }} bis {{ $settlement->ends_on->format('d.m.Y') }}.

<x-mail::table>
| | |
|:--|--:|
| Rechnungsnummer | {{ $settlement->formattedNumber() }} |
| Zähler | {{ $settlement->meter->number }} |
| Verbrauch | {{ number_format($settlement->billed_kwh, 0, ',', '.') }} kWh |
| Betrag brutto | {{ number_format((float) $settlement->gross_amount, 2, ',', '.') }} € |
</x-mail::table>

Ihren Zählerstand können Sie jederzeit über den QR-Code am Zähler melden:

<x-mail::button :url="$settlement->meter->tenantUrl()">
Zählerstand melden
</x-mail::button>

Mit freundlichen Grüßen<br>
{{ $landlord }}
</x-mail::message>
