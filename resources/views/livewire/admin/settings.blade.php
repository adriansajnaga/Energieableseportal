<?php

use App\Models\Setting;
use App\Support\MailSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Einstellungen')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    // Neues SMTP-Passwort; leer = unverändert
    public string $mailPassword = '';

    public string $testEmail = '';

    public function mount(): void
    {
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $this->values[$key] = (string) Setting::get($key);
        }

        // Das gespeicherte Passwort wird nie an den Browser geschickt.
        $this->values['mail_password'] = '';
        $this->testEmail = (string) Auth::user()->email;
    }

    public function save(): void
    {
        $this->validate([
            'values.price_factor' => ['required', 'numeric', 'min:0.5', 'max:5'],
            'values.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'values.invoice_prefix' => ['nullable', 'string', 'max:10'],
            'values.invoice_digits' => ['required', 'integer', 'min:1', 'max:10'],
            'values.pdf_file_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'],
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
            'values.mail_host' => ['nullable', 'string', 'max:255'],
            'values.mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'values.mail_encryption' => ['required', 'in:ssl,tls,none'],
            'values.mail_username' => ['nullable', 'string', 'max:255'],
            'values.mail_from_address' => ['nullable', 'email'],
            'values.mail_from_name' => ['nullable', 'string', 'max:255'],
            'values.mail_invoice_subject' => ['required', 'string', 'max:255'],
            'values.mail_invoice_body' => ['required', 'string', 'max:5000'],
            'mailPassword' => ['nullable', 'string', 'max:255'],
        ]);

        // Der Rechnungszähler wird nur über die Abrechnung fortgeschrieben.
        foreach (collect($this->values)->except(['invoice_counter', 'mail_password', 'status_token']) as $key => $value) {
            Setting::put($key, $value);
        }

        if ($this->mailPassword !== '') {
            MailSettings::storePassword($this->mailPassword);
            $this->mailPassword = '';
        }

        MailSettings::apply();

        Flux::toast(__('Einstellungen gespeichert.'), variant: 'success');
    }

    public function generateStatusLink(): void
    {
        Setting::put('status_token', Str::random(40));
        $this->values['status_token'] = (string) Setting::get('status_token');
        Flux::toast(__('Neuer Link erzeugt. Der bisherige Link funktioniert nicht mehr.'), variant: 'success');
    }

    public function disableStatusLink(): void
    {
        Setting::put('status_token', '');
        $this->values['status_token'] = '';
        Flux::toast(__('Link deaktiviert.'));
    }

    public function sendTestMail(): void
    {
        $this->validate(['testEmail' => ['required', 'email']]);
        MailSettings::apply();

        try {
            Mail::raw(__('Test-E-Mail aus dem Energieableseportal. Der E-Mail-Versand funktioniert.'), function ($message) {
                $message->to($this->testEmail)->subject(__('Test-E-Mail'));
            });
        } catch (Throwable $e) {
            report($e);
            $this->addError('testEmail', __('Versand fehlgeschlagen: :message', ['message' => $e->getMessage()]));

            return;
        }

        Flux::toast(__('Test-E-Mail an :email versendet.', ['email' => $this->testEmail]), variant: 'success');
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
                <flux:input wire:model="values.pdf_file_prefix" :label="__('Präfix PDF-Dateiname')" :description="__('z. B. KuB → KuB_E-0001.pdf (nur Buchstaben, Ziffern, - und _)')" />
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

        <flux:fieldset>
            <flux:legend>{{ __('E-Mail-Versand (SMTP)') }}</flux:legend>
            <flux:text class="mb-4">{{ __('Leer lassen, um die Werte aus der .env zu verwenden. Beim Hoster webd.pl als Host den Servernamen angeben (z. B. mn04.webd.pl), da das Zertifikat auf *.webd.pl ausgestellt ist.') }}</flux:text>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="values.mail_host" :label="__('SMTP-Server')" placeholder="mn04.webd.pl" class="sm:col-span-2" />
                <flux:input wire:model="values.mail_port" :label="__('Port')" type="number" />
                <flux:select wire:model="values.mail_encryption" :label="__('Verschlüsselung')">
                    <flux:select.option value="ssl">SSL (465)</flux:select.option>
                    <flux:select.option value="tls">STARTTLS (587)</flux:select.option>
                    <flux:select.option value="none">{{ __('keine') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="values.mail_username" :label="__('Benutzername')" autocomplete="off" />
                <flux:input wire:model="mailPassword" :label="__('Passwort')" type="password" autocomplete="new-password" :placeholder="\App\Models\Setting::get('mail_password') ? '••••••••' : ''" :description="__('Leer lassen = unverändert')" />
                <flux:input wire:model="values.mail_from_address" :label="__('Absender-Adresse')" type="email" class="sm:col-span-2" />
                <flux:input wire:model="values.mail_from_name" :label="__('Absender-Name')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Vorlage Rechnungs-E-Mail') }}</flux:legend>
            <flux:input wire:model="values.mail_invoice_subject" :label="__('Betreff')" />
            <flux:textarea wire:model="values.mail_invoice_body" :label="__('Text')" rows="8" class="mt-4" />
            <flux:text class="mt-2 text-xs">{{ __('Platzhalter') }}: {{ implode(', ', \App\Support\MailSettings::PLACEHOLDERS) }}. {{ __('Die Rechnung wird als PDF angehängt.') }}</flux:text>
        </flux:fieldset>

        <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
    </form>

    <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading>{{ __('Ablesestatus ohne Anmeldung') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Geheimer Link für den Hausmeister: zeigt die noch abzulesenden Zähler mit Mieter und Telefon, ohne Login. Nur an vertrauenswürdige Personen weitergeben.') }}</flux:text>
        @if ($values['status_token'])
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <flux:input readonly :value="route('public.status', $values['status_token'])" class="min-w-0 flex-1" onclick="this.select()" />
                <flux:button size="sm" icon="arrow-top-right-on-square" :href="route('public.status', $values['status_token'])" target="_blank" />
            </div>
            <div class="mt-3 flex gap-2">
                <flux:button size="sm" icon="arrow-path" wire:click="generateStatusLink" wire:confirm="{{ __('Neuen Link erzeugen? Der bisherige Link funktioniert danach nicht mehr.') }}">{{ __('Neuen Link erzeugen') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="disableStatusLink">{{ __('Link deaktivieren') }}</flux:button>
            </div>
        @else
            <flux:button size="sm" class="mt-3" icon="link" wire:click="generateStatusLink">{{ __('Link erzeugen') }}</flux:button>
        @endif
    </div>

    <form wire:submit="sendTestMail" class="mt-8 flex flex-wrap items-end gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:input wire:model="testEmail" type="email" :label="__('Test-E-Mail senden an')" class="max-w-sm" />
        <flux:button type="submit" icon="paper-airplane">{{ __('Test-E-Mail senden') }}</flux:button>
    </form>
</div>
