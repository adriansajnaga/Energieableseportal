<?php

use App\Enums\SettlementType;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Mail\InvoiceMail;
use App\Models\Settlement;
use App\Models\Tenant;
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

    // Sammelrechnung: Mieter und ausgewählte Monatsabrechnungen
    public ?int $collectTenantId = null;

    /** @var array<int, string> */
    public array $collectIds = [];

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
            ->with(['tenant', 'meter', 'cancels'])
            ->get();
    }

    #[Computed]
    public function collectives()
    {
        return Settlement::query()
            ->where('type', SettlementType::Collective)
            ->whereDate('period', $this->period()->toDateString())
            ->with(['tenant', 'items'])
            ->orderBy('invoice_number')
            ->get();
    }

    /** Offene Monatsabrechnungen des gewählten Mieters für die Sammelrechnung. */
    #[Computed]
    public function collectOptions()
    {
        if (! $this->collectTenantId) {
            return collect();
        }

        return Settlement::query()->openForCollection()
            ->where('tenant_id', $this->collectTenantId)
            ->with('meter')
            ->orderBy('period')
            ->orderBy('meter_id')
            ->get();
    }

    /** Anzahl Mieter mit offenen Monatsabrechnungen bis einschließlich des gewählten Monats. */
    #[Computed]
    public function openTenantsCount(): int
    {
        return Settlement::query()->openForCollection()
            ->whereDate('period', '<=', $this->period()->toDateString())
            ->distinct()
            ->count('tenant_id');
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
        unset($this->candidates, $this->openTenantsCount);

        Flux::modal('bulk-settle')->close();
        Flux::toast(__(':count Zähler abgerechnet.', ['count' => $result['settled']]), variant: 'success');

        foreach ($result['errors'] as $error) {
            Flux::toast($error, variant: 'danger');
        }
    }

    public function openCollect(int $tenantId): void
    {
        Gate::authorize('manage');
        $this->collectTenantId = $tenantId;
        unset($this->collectOptions);
        $this->collectIds = $this->collectOptions->pluck('id')->map(fn ($id) => (string) $id)->all();
        Flux::modal('collect')->show();
    }

    public function collect(SettlementService $service): void
    {
        Gate::authorize('manage');

        try {
            $collective = $service->collect(
                Settlement::query()->whereKey($this->collectIds)->where('tenant_id', $this->collectTenantId)->get(),
                Auth::user(),
            );
        } catch (RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        unset($this->candidates, $this->collectives, $this->openTenantsCount);
        Flux::modal('collect')->close();
        Flux::toast(__('Sammelrechnung :number erstellt.', ['number' => $collective->formattedNumber()]), variant: 'success');
    }

    public function collectAll(SettlementService $service): void
    {
        Gate::authorize('manage');
        $result = $service->collectAll($this->period(), Auth::user());
        unset($this->candidates, $this->collectives, $this->openTenantsCount);

        Flux::toast(__(':count Sammelrechnungen erstellt.', ['count' => $result['created']]), variant: 'success');

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

        unset($this->candidates, $this->cancellations, $this->collectives, $this->openTenantsCount);
        Flux::toast($cancellation
            ? __('Storniert, Stornorechnung :number erstellt.', ['number' => $cancellation->formattedNumber()])
            : __('Abrechnung storniert.'), variant: 'success');
    }

    public function sendEmails(): void
    {
        Gate::authorize('manage');

        $settlements = Settlement::query()
            ->whereIn('type', [SettlementType::Invoice, SettlementType::Collective])
            ->numbered()
            ->whereNull('cancelled_at')
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
        <flux:button icon="rectangle-stack" wire:click="collectAll" :disabled="$this->openTenantsCount === 0"
            wire:confirm="{{ __('Für jeden Mieter eine Sammelrechnung über alle noch nicht in Rechnung gestellten Abrechnungen bis :month erstellen?', ['month' => $this->period()->format('m/Y')]) }}">
            {{ __('Sammelrechnungen erstellen (:count)', ['count' => $this->openTenantsCount]) }}
        </flux:button>
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
                @php($inCollective = $s && ! $s->is_invoiced ? $s->activeCollective() : null)
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
                        @if ($inCollective)
                            <flux:badge size="sm" color="sky">{{ __('Sammelrechnung') }} {{ $inCollective->formattedNumber() }}</flux:badge>
                        @elseif ($s && ! $s->is_invoiced)
                            <flux:badge size="sm" color="zinc">{{ __('ohne Rechnung') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $s ? number_format($s->billed_kwh, 0, ',', '.') : '' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $s ? number_format($s->net_amount, 2, ',', '.').' €' : '' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($s)
                            @if ($inCollective)
                                <flux:button size="sm" variant="ghost" icon="document-text" :href="route('pdf.invoice', $inCollective)" target="_blank" :tooltip="$inCollective->formattedNumber()" />
                            @elseif ($s->is_invoiced)
                                <flux:button size="sm" variant="ghost" icon="document-text" :href="route('pdf.invoice', $s)" target="_blank" :tooltip="$s->formattedNumber()" />
                            @else
                                @can('manage')
                                    <flux:button size="sm" icon="rectangle-stack" wire:click="openCollect({{ $s->tenant_id }})">{{ __('Sammelrechnung') }}</flux:button>
                                @endcan
                            @endif
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

    @if ($this->collectives->isNotEmpty())
        <flux:heading class="mt-8">{{ __('Sammelrechnungen') }}</flux:heading>
        <ul class="mt-2 space-y-1 text-sm">
            @foreach ($this->collectives as $c)
                <li class="flex flex-wrap items-center gap-2">
                    <flux:link :href="route('pdf.invoice', $c)" target="_blank" class="{{ $c->isCancelled() ? 'line-through' : '' }}">{{ $c->formattedNumber() }}</flux:link>
                    <span>· {{ $c->tenant->name }} · {{ trans_choice(':count Position|:count Positionen', $c->items->count()) }} · {{ $c->starts_on->format('d.m.Y') }} – {{ $c->ends_on->format('d.m.Y') }} · {{ number_format($c->gross_amount, 2, ',', '.') }} €</span>
                    @if ($c->emailed_at) <flux:icon.envelope variant="micro" class="text-emerald-600" /> @endif
                    @can('manage')
                        @unless ($c->isCancelled())
                            <flux:button size="xs" variant="ghost" icon="x-circle" wire:click="cancel({{ $c->id }})" wire:confirm="{{ __('Sammelrechnung stornieren? Die Monate können danach erneut abgerechnet werden.') }}" :tooltip="__('Stornieren')" />
                        @endunless
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->cancellations->isNotEmpty())
        <flux:heading class="mt-8">{{ __('Stornorechnungen') }}</flux:heading>
        <ul class="mt-2 text-sm">
            @foreach ($this->cancellations as $c)
                <li><flux:link :href="route('pdf.invoice', $c)" target="_blank">{{ $c->formattedNumber() }}</flux:link> · {{ $c->tenant->name }} · {{ $c->meterNumbers() }} · {{ number_format($c->gross_amount, 2, ',', '.') }} €</li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="bulk-settle" class="md:w-md">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Alle bereiten Zähler abrechnen') }}</flux:heading>
            <flux:text>{{ __('Für jeden Zähler wird die Ablesung verwendet, die dem Monatsende am nächsten liegt, und der Preisfaktor des Mieters.') }}</flux:text>
            <flux:checkbox wire:model="bulkInvoice" :label="__('Rechnungen mit Rechnungsnummer erstellen')" />
            <flux:text class="text-xs">{{ __('Ohne Rechnungsnummer werden die Monate nur gespeichert und können später zu einer Sammelrechnung zusammengefasst werden.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="settleAll">{{ __('Abrechnen') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="collect" class="md:w-2xl">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Sammelrechnung') }}: {{ $collectTenantId ? Tenant::find($collectTenantId)?->name : '' }}</flux:heading>
            <flux:text>{{ __('Alle Monate und Zähler dieses Mieters, die noch nicht in Rechnung gestellt wurden. Auf der Rechnung erscheint jeder Monat mit seinem eigenen Preis.') }}</flux:text>

            <div class="max-h-80 space-y-1 overflow-y-auto">
                @foreach ($this->collectOptions as $option)
                    <label class="flex items-center justify-between gap-3 rounded px-2 py-1 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700" wire:key="collect-{{ $option->id }}">
                        <span class="flex items-center gap-2">
                            <input type="checkbox" value="{{ $option->id }}" wire:model.live="collectIds" class="rounded">
                            <strong>{{ $option->period->format('m/Y') }}</strong> · {{ $option->meter?->number }}
                        </span>
                        <span>{{ number_format($option->billed_kwh, 0, ',', '.') }} kWh · {{ number_format((float) $option->net_amount, 2, ',', '.') }} €</span>
                    </label>
                @endforeach
            </div>

            @php($selected = $this->collectOptions->whereIn('id', array_map('intval', $collectIds)))
            <div class="flex justify-between border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
                <span>{{ trans_choice(':count Position|:count Positionen', $selected->count()) }}</span>
                <strong>{{ __('Netto') }} {{ number_format($selected->sum(fn ($s) => (float) $s->net_amount), 2, ',', '.') }} €</strong>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="collect" :disabled="$selected->isEmpty()">{{ __('Sammelrechnung erstellen') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
