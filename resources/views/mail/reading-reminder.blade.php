<x-mail::message>
# Zählerstand melden

@if ($forCaretaker)
Folgende Zähler sind in diesem Monat noch nicht abgelesen:

<x-mail::table>
| Zähler | Mieter | Ort |
|:--|:--|:--|
@foreach ($meters as $meter)
| {{ $meter->number }} | {{ $meter->tenant?->name }} | {{ $meter->location }} |
@endforeach
</x-mail::table>

<x-mail::button :url="route('readings.status')">
Ablesestatus öffnen
</x-mail::button>
@else
bitte übermitteln Sie Ihren Zählerstand bis zum {{ $deadline }}. des Monats. Es dauert nur eine Minute: Stand eintragen und den Zähler fotografieren.

@foreach ($meters as $meter)
<x-mail::button :url="$meter->tenantUrl()">
Zähler {{ $meter->number }} ablesen
</x-mail::button>
@endforeach
@endif

Vielen Dank!<br>
{{ $landlord }}
</x-mail::message>
