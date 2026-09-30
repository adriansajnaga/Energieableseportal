<x-mail::message>
@foreach ($paragraphs as $paragraph)
{!! nl2br(e($paragraph)) !!}

@endforeach

<x-mail::table>
| | |
|:--|--:|
| Rechnungsnummer | {{ $settlement->formattedNumber() }} |
| Zeitraum | {{ $settlement->starts_on->format('d.m.Y') }} – {{ $settlement->ends_on->format('d.m.Y') }} |
| Zähler | {{ $settlement->meterNumbers() }} |
| Verbrauch | {{ number_format($settlement->billed_kwh, 0, ',', '.') }} kWh |
| Betrag brutto | {{ number_format((float) $settlement->gross_amount, 2, ',', '.') }} € |
</x-mail::table>

@if ($settlement->meter)
<x-mail::button :url="$settlement->meter->tenantUrl()">
Zählerstand melden
</x-mail::button>
@endif
</x-mail::message>
