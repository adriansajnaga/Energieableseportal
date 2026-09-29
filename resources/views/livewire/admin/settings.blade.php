<?php

use App\Models\Setting;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Einstellungen')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public function mount(): void
    {
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $this->values[$key] = (string) Setting::get($key);
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.price_factor' => ['required', 'numeric', 'min:0.5', 'max:5'],
            'values.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'values.invoice_prefix' => ['nullable', 'string', 'max:10'],
            'values.invoice_digits' => ['required', 'integer', 'min:1', 'max:10'],
            'values.reading_deadline_day' => ['required', 'integer', 'min:1', 'max:28'],
            'values.reminder_day' => ['required', 'integer', 'min:0', 'max:28'],
            'values.plausibility_multiplier' => ['required', 'numeric', 'min:1', 'max:20'],
            'values.landlord_name' => ['required', 'string', 'max:255'],
            'values.landlord_street' => ['nullable', 'string', 'max:255'],
            'values.landlord_city' => ['nullable', 'string', 'max:255'],
            'values.landlord_vat_id' => ['nullable', 'string', 'max:50'],
            'values.site_address' => ['nullable', 'string', 'max:500'],
            'values.caretaker_email' => ['nullable', 'email'],
            'values.datev_revenue_account' => ['nullable', 'string', 'max:10'],
            'values.datev_tax_key' => ['nullable', 'string', 'max:5'],
        ]);

        // Der Rechnungszähler wird nur über die Abrechnung fortgeschrieben.
        foreach (collect($this->values)->except('invoice_counter') as $key => $value) {
            Setting::put($key, $value);
        }

        Flux::toast(__('Einstellungen gespeichert.'), variant: 'success');
    }
}; ?>

<div class="max-w-3xl">
    <x-page-header :title="__('Einstellungen')" :subtitle="__('Rechnungsangaben, Preise, Ablesung und Buchhaltung')" />

    <form wire:submit="save" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Vermieter / Rechnungssteller') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.landlord_name" :label="__('Firma')" />
                <flux:input wire:model="values.landlord_vat_id" :label="__('USt-IdNr.')" />
                <flux:input wire:model="values.landlord_street" :label="__('Straße')" />
                <flux:input wire:model="values.landlord_city" :label="__('PLZ Ort')" />
            </div>
            <flux:textarea wire:model="values.site_address" :label="__('Verbrauchsstelle')" rows="2" class="mt-4" />
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Abrechnung') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.price_factor" :label="__('Standard-Preisfaktor')" type="number" step="0.001" />
                <flux:input wire:model="values.vat_rate" :label="__('Umsatzsteuer (%)')" type="number" step="0.01" />
                <flux:input wire:model="values.invoice_prefix" :label="__('Präfix Rechnungsnummer')" />
                <flux:input wire:model="values.invoice_digits" :label="__('Stellen Rechnungsnummer')" type="number" />
            </div>
            <flux:text class="mt-2">{{ __('Letzte vergebene Rechnungsnummer') }}: {{ $values['invoice_counter'] }}</flux:text>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Ablesung und Erinnerungen') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="values.reading_deadline_day" :label="__('Ablesen bis zum Tag')" type="number" />
                <flux:input wire:model="values.reminder_day" :label="__('Erinnerung am Tag')" :description="__('0 = keine Erinnerungen')" type="number" />
                <flux:input wire:model="values.plausibility_multiplier" :label="__('Plausibilität: Faktor über Ø')" type="number" step="0.1" />
            </div>
            <flux:input wire:model="values.caretaker_email" :label="__('E-Mail Hausmeister (Liste offener Ablesungen)')" type="email" class="mt-4" />
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('DATEV-Export') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="values.datev_revenue_account" :label="__('Erlöskonto')" />
                <flux:input wire:model="values.datev_tax_key" :label="__('BU-Schlüssel (optional)')" />
            </div>
        </flux:fieldset>

        <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
    </form>
</div>
