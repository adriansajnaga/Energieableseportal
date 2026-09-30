<?php

use App\Enums\SettlementType;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Services\InvoiceMailer;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
use App\Support\MailSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    // Detailansicht mit Einzelversand
    public ?int $detailId = null;

    public string $emailTo = '';

    public bool $saveEmail = false;

    public string $emailSubject = '';

    public string $emailBody = '';

    #[Computed]
    public function detail(): ?Settlement
    {
        return $this->detailId
            ? Settlement::with(['tenant', 'meter', 'startReading', 'endReading', 'cancels', 'items.meter'])->find($this->detailId)
            : null;
    }

    public function showDetail(int $id): void
    {
        $this->detailId = $id;
        unset($this->detail);
        $this->emailTo = (string) ($this->detail?->emailed_to ?: $this->detail?->tenant->email);
        $this->saveEmail = false;
        $this->emailSubject = $this->detail?->canBeEmailed() ? MailSettings::subject($this->detail) : '';
        $this->emailBody = $this->detail?->canBeEmailed() ? MailSettings::body($this->detail) : '';
        $this->resetValidation();
        Flux::modal('detail')->show();
    }

    /** Einzelne Monatsabrechnung ohne Rechnungsnummer als extern abgerechnet markieren bzw. zurücksetzen. */
    public function toggleExternal(): void
    {
        Gate::authorize('manage');
        $settlement = $this->detail;

        if (! $settlement || $settlement->type !== SettlementType::Invoice || $settlement->invoice_number || $settlement->activeCollective()) {
            return;
        }

        $settlement->update(['is_invoiced' => ! $settlement->is_invoiced]);
        unset($this->detail, $this->candidates, $this->openCount, $this->openTenantsCount);

        Flux::toast($settlement->is_invoiced
            ? __('Als extern abgerechnet markiert.')
            : __('Markierung aufgehoben. Die Abrechnung kann wieder in eine Sammelrechnung.'), variant: 'success');
    }

    public function deleteDetail(SettlementService $service): void
    {
        Gate::authorize('manage');
        $number = $this->detail?->formattedNumber();

        try {
            $service->deleteInvoice($this->detail);
        } catch (RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->detailId = null;
        unset($this->detail, $this->candidates, $this->collectives, $this->openTenantsCount);
        Flux::modal('detail')->close();
        Flux::toast(__('Rechnung :number gelöscht. Die Nummer wird erneut vergeben.', ['number' => $number]), variant: 'success');
    }

    public function sendDetail(InvoiceMailer $mailer): void
    {
        Gate::authorize('manage');
        $this->validate([
            'emailTo' => ['required', 'email'],
            'emailSubject' => ['required', 'string', 'max:255'],
            'emailBody' => ['required', 'string', 'max:5000'],
        ]);

        $settlement = $this->detail;

        try {
            $mailer->send($settlement, $this->emailTo, $this->emailSubject, $this->emailBody);
        } catch (Throwable $e) {
            report($e);
            $this->addError('emailTo', __('Versand fehlgeschlagen: :message', ['message' => $e->getMessage()]));

            return;
        }

        if ($this->saveEmail) {
            $settlement->tenant->update(['email' => $this->emailTo]);
        }

        unset($this->detail, $this->candidates, $this->collectives, $this->pendingEmailCount);
        Flux::toast(__('Rechnung :number an :email versendet.', ['number' => $settlement->formattedNumber(), 'email' => $this->emailTo]), variant: 'success');
    }

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

    /** Offene Monatsabrechnungen (ohne Rechnung) bis einschließlich des gewählten Monats. */
    #[Computed]
    public function openCount(): int
    {
        return Settlement::query()->openForCollection()
            ->whereDate('period', '<=', $this->period()->toDateString())
            ->count();
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

        unset($this->candidates, $this->collectives, $this->openTenantsCount, $this->openCount);
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

    public function markExternal(SettlementService $service): void
    {
        Gate::authorize('manage');
        $count = $service->markExternallyInvoiced($this->period());
        unset($this->candidates, $this->openCount, $this->openTenantsCount);

        Flux::toast(__(':count Abrechnungen als extern abgerechnet markiert.', ['count' => $count]), variant: 'success');
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

    public function sendEmails(InvoiceMailer $mailer): void
    {
        Gate::authorize('manage');

        $settlements = $this->pendingEmailQuery()->with('tenant')->get();

        $sent = 0;

        foreach ($settlements as $settlement) {
            try {
                $mailer->send($settlement, $settlement->tenant->email);
                $sent++;
            } catch (Throwable $e) {
                report($e);
                Flux::toast($settlement->formattedNumber().': '.$e->getMessage(), variant: 'danger');
            }
        }

        unset($this->candidates, $this->collectives, $this->pendingEmailCount);
        Flux::toast(__(':count Rechnungen per E-Mail versendet.', ['count' => $sent]), variant: 'success');
    }

    /** Rechnungen des Monats, die noch nicht versendet wurden und deren Mieter E-Mail-Versand gewählt hat. */
    private function pendingEmailQuery()
    {
        return Settlement::query()
            ->whereIn('type', [SettlementType::Invoice, SettlementType::Collective])
            ->numbered()
            ->whereNull('cancelled_at')
            ->whereNull('emailed_at')
            ->whereDate('period', $this->period()->toDateString())
            ->whereHas('tenant', fn ($q) => $q->where('send_invoices_by_email', true)->whereNotNull('email'));
    }

    #[Computed]
    public function pendingEmailCount(): int
    {
        return $this->pendingEmailQuery()->count();
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
        <flux:dropdown>
            <flux:button icon="ellipsis-horizontal" icon-trailing="chevron-down">{{ __('Weitere Aktionen') }}</flux:button>
            <flux:menu>
                <flux:menu.item icon="check-badge" wire:click="markExternal" :disabled="$this->openCount === 0"
                    wire:confirm="{{ __('Alle :count noch nicht in Rechnung gestellten Abrechnungen bis einschließlich :month als extern abgerechnet markieren? Sie erscheinen danach nicht mehr bei den Sammelrechnungen.', ['count' => $this->openCount, 'month' => $this->period()->format('m/Y')]) }}">
                    {{ __('Bis :month als extern abgerechnet markieren (:count)', ['month' => $this->period()->format('m/Y'), 'count' => $this->openCount]) }}
                </flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item icon="envelope" wire:click="sendEmails" :disabled="$this->pendingEmailCount === 0"
                    wire:confirm="{{ __(':count Rechnungen dieses Monats jetzt an alle Mieter mit E-Mail-Versand senden?', ['count' => $this->pendingEmailCount]) }}">
                    {{ __('Alle Rechnungen des Monats per E-Mail senden (:count)', ['count' => $this->pendingEmailCount]) }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
        <flux:button icon="document-arrow-down" :href="route('pdf.invoices', $month)" target="_blank">{{ __('Alle Rechnungen (PDF)') }}</flux:button>
        <flux:button icon="table-cells" :href="route('pdf.overview', $month)" target="_blank">{{ __('Alle Abrechnungen (PDF)') }}</flux:button>
        <flux:dropdown>
            <flux:button icon="arrow-down-tray" icon-trailing="chevron-down">{{ __('Export') }}</flux:button>
            <flux:menu>
                <flux:menu.item :href="route('export.settlements', $month)">{{ __('Abrechnungen (CSV)') }}</flux:menu.item>
                <flux:menu.item :href="route('export.datev', $month)">{{ __('DATEV Buchungsstapel (CSV)') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
        @else
            <flux:button icon="document-arrow-down" :href="route('pdf.invoices', $month)" target="_blank">{{ __('Alle Rechnungen (PDF)') }}</flux:button>
            <flux:button icon="table-cells" :href="route('pdf.overview', $month)" target="_blank">{{ __('Alle Abrechnungen (PDF)') }}</flux:button>
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
                @php($document = $inCollective ?? ($s?->is_invoiced ? $s : null))
                <flux:table.row :key="$candidate->key()">
                    <flux:table.cell variant="strong">
                        {{ $candidate->meter->number }}
                        <div class="text-xs font-normal text-zinc-500">{{ $candidate->meter->location }} @if ($candidate->meter->factor > 1) · {{ __('Faktor') }} {{ $candidate->meter->factor }} @endif</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($s)
                            <button type="button" wire:click="showDetail({{ ($document ?? $s)->id }})" class="text-start font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                                {{ $candidate->tenant->name }}
                            </button>
                        @else
                            {{ $candidate->tenant->name }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ $candidate->startReading?->read_on->format('d.m.') ?? '–' }} – {{ $candidate->endsOn->format('d.m.Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$this->badgeColor($candidate->status())">{{ $candidate->statusLabel() }}</flux:badge>
                        @if ($document?->emailed_at)
                            <flux:tooltip :content="__('am :date an :email', ['date' => $document->emailed_at->format('d.m.Y H:i'), 'email' => $document->emailed_to ?? $candidate->tenant->email])">
                                <flux:badge size="sm" color="green" icon="envelope">{{ __('Versendet') }}</flux:badge>
                            </flux:tooltip>
                        @endif
                        @if ($inCollective)
                            <flux:badge size="sm" color="sky">{{ __('Sammelrechnung') }} {{ $inCollective->formattedNumber() }}</flux:badge>
                        @elseif ($s && ! $s->is_invoiced)
                            <flux:badge size="sm" color="zinc">{{ __('ohne Rechnung') }}</flux:badge>
                        @elseif ($s && ! $s->invoice_number)
                            <flux:badge size="sm" color="zinc" icon="check-badge">{{ __('extern abgerechnet') }}</flux:badge>
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
                    <button type="button" wire:click="showDetail({{ $c->id }})" class="font-medium text-emerald-700 hover:underline dark:text-emerald-400 {{ $c->isCancelled() ? 'line-through' : '' }}">{{ $c->formattedNumber() }}</button>
                    <span>· {{ $c->tenant->name }} · {{ trans_choice(':count Position|:count Positionen', $c->items->count()) }} · {{ $c->starts_on->format('d.m.Y') }} – {{ $c->ends_on->format('d.m.Y') }} · {{ number_format($c->gross_amount, 2, ',', '.') }} €</span>
                    @if ($c->emailed_at) <flux:badge size="sm" color="green" icon="envelope">{{ __('Versendet') }}</flux:badge> @endif
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
                <li>
                    <button type="button" wire:click="showDetail({{ $c->id }})" class="font-medium text-emerald-700 hover:underline dark:text-emerald-400">{{ $c->formattedNumber() }}</button>
                    · {{ $c->tenant->name }} · {{ $c->meterNumbers() }} · {{ number_format($c->gross_amount, 2, ',', '.') }} €
                    @if ($c->emailed_at) <flux:badge size="sm" color="green" icon="envelope">{{ __('Versendet') }}</flux:badge> @endif
                </li>
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

    <flux:modal name="detail" class="w-full md:w-2xl">
        @if ($d = $this->detail)
            @php($money = fn ($v) => number_format((float) $v, 2, ',', '.').' €')
            <div class="space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <flux:heading size="lg">{{ $d->type->label() }} {{ $d->formattedNumber() }}</flux:heading>
                        <flux:subheading>{{ $d->tenant->name }} · {{ __('Kundennr.') }} {{ $d->tenant->debtor_number }}</flux:subheading>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        @if ($d->isCancelled()) <flux:badge color="red">{{ __('Storniert') }}</flux:badge> @endif
                        @if (! $d->canBeEmailed())
                            <flux:badge color="zinc">{{ $d->is_invoiced ? __('extern abgerechnet') : __('ohne Rechnung') }}</flux:badge>
                        @endif
                        @if ($d->emailed_at)
                            <flux:badge color="green" icon="envelope">{{ __('Versendet am :date', ['date' => $d->emailed_at->format('d.m.Y H:i')]) }}</flux:badge>
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500">{{ __('Zeitraum') }}</dt><dd>{{ $d->starts_on->format('d.m.Y') }} – {{ $d->ends_on->format('d.m.Y') }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Zähler') }}</dt><dd>{{ $d->meterNumbers() }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Rechnungsdatum') }}</dt><dd>{{ $d->invoice_date?->format('d.m.Y') ?? '–' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Verbrauch') }}</dt><dd>{{ number_format($d->billed_kwh, 0, ',', '.') }} kWh</dd></div>
                </dl>

                @if ($d->type === \App\Enums\SettlementType::Collective)
                    <div class="max-h-56 overflow-y-auto rounded border border-zinc-200 text-sm dark:border-zinc-700">
                        <table class="w-full">
                            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                                <tr><th class="px-2 py-1 text-start">{{ __('Monat') }}</th><th class="px-2 py-1 text-start">{{ __('Zähler') }}</th><th class="px-2 py-1 text-end">kWh</th><th class="px-2 py-1 text-end">€/kWh</th><th class="px-2 py-1 text-end">{{ __('Netto') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($d->items as $item)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                        <td class="px-2 py-1">{{ $item->period->format('m/Y') }}</td>
                                        <td class="px-2 py-1">{{ $item->meter?->number }}</td>
                                        <td class="px-2 py-1 text-end">{{ number_format($item->billed_kwh, 0, ',', '.') }}</td>
                                        <td class="px-2 py-1 text-end">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                                        <td class="px-2 py-1 text-end">{{ $money($item->net_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif ($d->type === \App\Enums\SettlementType::Invoice)
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                        <div><dt class="text-zinc-500">{{ __('Stand alt') }}</dt><dd>{{ number_format((int) $d->startReading?->value, 0, ',', '.') }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Stand neu') }}</dt><dd>{{ $d->endReading ? number_format($d->endReading->value, 0, ',', '.') : '–' }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Zählerfaktor') }}</dt><dd>{{ $d->meter_factor }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Preis') }}</dt><dd>{{ number_format((float) $d->unit_price, 2, ',', '.') }} €/kWh</dd></div>
                    </dl>
                @endif

                <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                    <div class="flex justify-between"><span>{{ __('Netto') }}</span><span>{{ $money($d->net_amount) }}</span></div>
                    <div class="flex justify-between"><span>{{ __('USt. :rate %', ['rate' => (float) $d->vat_rate]) }}</span><span>{{ $money($d->vat_amount) }}</span></div>
                    <div class="mt-1 flex justify-between border-t border-zinc-200 pt-1 font-semibold dark:border-zinc-700"><span>{{ __('Brutto') }}</span><span>{{ $money($d->gross_amount) }}</span></div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @if ($d->canBeEmailed())
                        <flux:button icon="document-text" :href="route('pdf.invoice', $d)" target="_blank">{{ __('PDF öffnen') }}</flux:button>
                    @endif
                    @can('manage')
                        @if ($d->canBeEmailed() && in_array($d->type, [\App\Enums\SettlementType::Invoice, \App\Enums\SettlementType::Collective], true))
                            @php($blocker = app(\App\Services\SettlementService::class)->deletionBlocker($d))
                            @if ($blocker)
                                <flux:text class="text-xs">{{ __('Löschen nicht möglich') }}: {{ $blocker }}</flux:text>
                            @else
                                <flux:button variant="danger" icon="trash" wire:click="deleteDetail"
                                    wire:confirm="{{ __('Rechnung :number endgültig löschen? Die Nummer wird für die nächste Rechnung wieder verwendet.', ['number' => $d->formattedNumber()]) }}">
                                    {{ __('Rechnung löschen') }}
                                </flux:button>
                            @endif
                        @endif
                    @endcan
                </div>

                @can('manage')
                    @if ($d->canBeEmailed())
                        <form wire:submit="sendDetail" class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:input wire:model="emailTo" type="email" :label="__('Rechnung per E-Mail senden an')" required />
                            <flux:input wire:model="emailSubject" :label="__('Betreff')" required />
                            <flux:textarea wire:model="emailBody" :label="__('Text')" rows="7" required />
                            <flux:text class="text-xs">{{ __('Die Rechnung wird als PDF angehängt. Die Vorlage ändern Sie unter Einstellungen.') }}</flux:text>
                            @if ($emailTo !== (string) $d->tenant->email)
                                <flux:checkbox wire:model="saveEmail" :label="__('Diese Adresse beim Mieter speichern')" />
                            @endif
                            @if ($d->emailed_at)
                                <flux:text class="text-xs">{{ __('Bereits am :date an :email versendet.', ['date' => $d->emailed_at->format('d.m.Y H:i'), 'email' => $d->emailed_to]) }}</flux:text>
                            @endif
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Schließen') }}</flux:button></flux:modal.close>
                                <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled">
                                    {{ $d->emailed_at ? __('Erneut senden') : __('Senden') }}
                                </flux:button>
                            </div>
                        </form>
                    @elseif ($d->activeCollective())
                        <flux:callout icon="information-circle" :text="__('Diese Abrechnung steht in der Sammelrechnung :number.', ['number' => $d->activeCollective()->formattedNumber()])" />
                    @elseif ($d->is_invoiced)
                        <flux:callout icon="information-circle" :text="__('Diese Abrechnung wurde außerhalb des Portals in Rechnung gestellt (z. B. im Altsystem).')" />
                        <div class="flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="toggleExternal">{{ __('Markierung aufheben') }}</flux:button>
                        </div>
                    @else
                        <flux:callout icon="information-circle" :text="__('Für diesen Monat gibt es noch keine Rechnung. Erstellen Sie eine Sammelrechnung, um ihn zu versenden.')" />
                        <div class="flex justify-end">
                            <flux:button size="sm" icon="check-badge" wire:click="toggleExternal">{{ __('Als extern abgerechnet markieren') }}</flux:button>
                        </div>
                    @endif
                @endcan
            </div>
        @endif
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
