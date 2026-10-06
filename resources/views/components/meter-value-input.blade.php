{{-- Eingabe eines Zählerstands: Strom ganze kWh, Wasser m³ mit Nachkommastellen (Komma oder Punkt). --}}
@props(['medium', 'label' => null, 'description' => null])

@php
    $medium = $medium instanceof \App\Enums\Medium ? $medium : \App\Enums\Medium::from($medium);
    $label = $label ? $label.' ('.$medium->unit().')' : null;
@endphp

@if ($medium->isWater())
    <flux:input {{ $attributes }} :$label :$description inputmode="decimal" placeholder="0,000" autocomplete="off" required />
@else
    <flux:input {{ $attributes }} :$label :$description type="number" inputmode="numeric" min="0" step="1" required />
@endif
