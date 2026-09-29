<?php

use App\Enums\SettlementType;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Mail\InvoiceMail;
use App\Models\Settlement;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Abrechnung')] class extends Component {
    use WithWorkingMonth;

    public bool $bulkInvoice = true;

    #[Computed]
    public function candidates()
    {
        return app(SettlementService::class)->candidates($this->period());
    }

    #[Computed]
    public function cancellations()
    {
        return Settlement::query()
            ->where('type', SettlementType::Cancellation)
            ->whereDate('period', $this->period()->toDateString())
            ->with(['tenant', 'meter'])
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $settled = $this->candidates->pluck('settlement')->filter();

        return [
            'kwh' => $settled->sum('billed_kwh'),
            'net' => $settled->sum('net_amount'),
            'gross' => $settled->sum('gross_amount'),
        ];
    }

    public function settleAll(SettlementService $service): void
    {
        Gate::authorize('manage');
        $result = $service->settleAll($this->period(), $this->bulkInvoice, Auth::user());
        unset($this->candidates);

        Flux::modal('bulk-settle')->close();
        Flux::toast(__(':count Zähler abgerechnet.', ['count' => $result['settled']]), variant: 'success');

        foreach ($result['errors'] as $error) {
            Flux::toast($error, variant: 'danger');
        }
    }

    public function cancel(Settlement $settlement, SettlementService $service): void
    {
        Gate::authorize('manage');

        try {
            $cancellation = $service->cancel($settlement, Auth::user());
        } catch (RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->candidates, $this->cancellations);
        Flux::toast($cancellation
            ? __('Storniert, Stornorechnung :number erstellt.', ['number' => $cancellation->formattedNumber()])
            : __('Abrechnung storniert.'), variant: 'success');
    }

    public function sendEmails(): void
    {
        Gate::authorize('manage');

        $settlements = Settlement::query()->effective()
            ->where('is_invoiced', true)
            ->whereNull('emailed_at')
            ->whereDate('period', $this->period()->toDateString())
            ->whereHas('tenant', fn ($q) => $q->where('send_invoices_by_email', true)->whereNotNull('email'))
            ->with('tenant')
            ->get();

        foreach ($settlements as $settlement) {
            Mail::to($settlement->tenant->email)->queue(new InvoiceMail($settlement));
            $settlement->update(['emailed_at' => now()]);
        }

        Flux::toast(__(':count Rechnungen werden per E-Mail versendet.', ['count' => $settlements->count()]), variant: 'success');
    }

    public function badgeColor(string $status): string
    {
        return match ($status) {
            SettlementCandidate::SETTLED => 'green',
            SettlementCandidate::READY => 'amber',
            default => 'zinc',
        };
    }
}; ?>

<div>
    <x-page-header :title="__('Abrechnung')" :subtitle="__('Monatliche Stromabrechnung je Zähler und Mieter')">
        <x-month-switcher :period="$this->period()" />
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @php($ready = $this->candidates->filter->isReady()->count())
        @can('manage')
        <flux:modal.trigger name="bulk-settle">
            <flux:button variant="primary" icon="bolt" :disabled="$ready === 0">{{ __('Alle bereiten abrechnen (:count)', ['count' => $ready]) }}</flux:button>
        </flux:modal.trigger>
        <flux:button icon="document-arrow-down" :href="route('pdf.invoices', $month)" target="_blank">{{ __('Alle Rechnungen (PDF)') }}</flux:button>
        <flux:button icon="envelope" wire:click="sendEmails" wire:confirm="{{ __('Alle noch nicht versendeten Rechnungen dieses Monats per E-Mail senden?') }}">{{ __('Per E-Mail senden') }}</flux:button>
        <flux:dropdown>
            <flux:button icon="arrow-down-tray" icon-trailing="chevron-down">{{ __('Export') }}</flux:button>
            <flux:menu>
                <flux:menu.item :href="route('export.settlements', $month)">{{ __('Abrechnungen (CSV)') }}</flux:menu.item>
                <flux:menu.item :href="route('export.datev', $month)">{{ __('DATEV Buchungsstapel (CSV)') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
        @else
            <flux:button icon="document-arrow-down" :href="route('pdf.invoices', $month)" target="_blank">{{ __('Alle Rechnungen (PDF)') }}</flux:button>
        @endcan
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Zähler / Ort') }}</flux:table.column>
            <flux:table.column>{{ __('Mieter') }}</flux:table.column>
            <flux:table.column>{{ __('Zeitraum') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('kWh') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Netto') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->candidates as $candidate)
                @php($s = $candidate->settlement)
                <flux:table.row :key="$candidate->key()">
                    <flux:table.cell variant="strong">
                        {{ $candidate->meter->number }}
                        <div class="text-xs font-normal text-zinc-500">{{ $candidate->meter->location }} @if ($candidate->meter->factor > 1) · {{ __('Faktor') }} {{ $candidate->meter->factor }} @endif</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $candidate->tenant->name }}</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ $candidate->startReading?->read_on->format('d.m.') ?? '–' }} – {{ $candidate->endsOn->format('d.m.Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$this->badgeColor($candidate->status())">{{ $candidate->statusLabel() }}</flux:badge>
                        @if ($s?->emailed_at) <flux:icon.envelope variant="micro" class="ms-1 inline text-emerald-600" /> @endif
                        @if ($s && ! $s->is_invoiced) <flux:badge size="sm" color="zinc">{{ __('ohne Rechnung') }}</flux:badge> @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $s ? number_format($s->billed_kwh, 0, ',', '.') : '' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $s ? number_format($s->net_amount, 2, ',', '.').' €' : '' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($s)
                            <flux:button size="sm" variant="ghost" icon="document-text" :href="route('pdf.invoice', $s)" target="_blank" :tooltip="$s->formattedNumber()" />
                            @can('manage')
                                <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="cancel({{ $s->id }})" wire:confirm="{{ __('Abrechnung stornieren? Es wird eine Stornorechnung erstellt.') }}" :tooltip="__('Stornieren')" />
                            @endcan
                        @elseif ($candidate->isReady() && Gate::allows('manage'))
                            <flux:button size="sm" variant="primary" :href="route('settlements.create', ['meter' => $candidate->meter, 'tenant' => $candidate->tenant, 'monat' => $month])" wire:navigate>{{ __('Abrechnen') }}</flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" icon="calendar" :href="route('pdf.year', [$candidate->meter, $candidate->tenant, $this->period()->year])" target="_blank" :tooltip="__('Jahresübersicht')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">{{ __('Keine Zähler mit Mietern in diesem Monat.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4 flex flex-wrap justify-end gap-6 text-sm">
        <span>{{ __('Summe') }}: <strong>{{ number_format($this->totals['kwh'], 0, ',', '.') }} kWh</strong></span>
        <span>{{ __('Netto') }}: <strong>{{ number_format($this->totals['net'], 2, ',', '.') }} €</strong></span>
        <span>{{ __('Brutto') }}: <strong>{{ number_format($this->totals['gross'], 2, ',', '.') }} €</strong></span>
    </div>

    @if ($this->cancellations->isNotEmpty())
        <flux:heading class="mt-8">{{ __('Stornorechnungen') }}</flux:heading>
        <ul class="mt-2 text-sm">
            @foreach ($this->cancellations as $c)
                <li><flux:link :href="route('pdf.invoice', $c)" target="_blank">{{ $c->formattedNumber() }}</flux:link> · {{ $c->tenant->name }} · {{ $c->meter->number }} · {{ number_format($c->gross_amount, 2, ',', '.') }} €</li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="bulk-settle" class="md:w-md">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Alle bereiten Zähler abrechnen') }}</flux:heading>
            <flux:text>{{ __('Für jeden Zähler wird die Ablesung verwendet, die dem Monatsende am nächsten liegt, und der Preisfaktor des Mieters.') }}</flux:text>
            <flux:checkbox wire:model="bulkInvoice" :label="__('Rechnungen mit Rechnungsnummer erstellen')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="settleAll">{{ __('Abrechnen') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
