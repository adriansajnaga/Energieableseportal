<?php

use App\Models\ActivityLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Änderungsprotokoll')] class extends Component {
    use WithPagination;

    #[Url(as: 'typ')]
    public string $type = '';

    #[Computed]
    public function entries()
    {
        return ActivityLog::query()
            ->with('user')
            ->when($this->type, fn ($q) => $q->where('subject_type', 'App\\Models\\'.$this->type))
            ->latest('id')
            ->paginate(50);
    }

    public function formatValue(mixed $value): string
    {
        return match (true) {
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? '1' : '0',
            $value === null => '–',
            default => \Illuminate\Support\Str::limit((string) $value, 40),
        };
    }
}; ?>

<div>
    <x-page-header :title="__('Änderungsprotokoll')" :subtitle="__('Wer hat wann was geändert')">
        <flux:select wire:model.live="type" class="max-w-52">
            <flux:select.option value="">{{ __('Alle') }}</flux:select.option>
            @foreach (['Tenant' => __('Mieter'), 'Meter' => __('Zähler'), 'Reading' => __('Zählerstände'), 'Settlement' => __('Abrechnungen'), 'ElectricityPrice' => __('Strompreise'), 'MeterAssignment' => __('Zuordnungen')] as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-page-header>

    <flux:table :paginate="$this->entries">
        <flux:table.columns>
            <flux:table.column>{{ __('Zeitpunkt') }}</flux:table.column>
            <flux:table.column>{{ __('Benutzer') }}</flux:table.column>
            <flux:table.column>{{ __('Aktion') }}</flux:table.column>
            <flux:table.column>{{ __('Objekt') }}</flux:table.column>
            <flux:table.column>{{ __('Änderungen') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->entries as $entry)
                <flux:table.row :key="$entry->id">
                    <flux:table.cell>{{ $entry->created_at->format('d.m.Y H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->user?->name ?? __('System / Mieter') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="['created' => 'green', 'updated' => 'sky', 'deleted' => 'red'][$entry->action] ?? 'zinc'">
                            {{ ['created' => __('angelegt'), 'updated' => __('geändert'), 'deleted' => __('gelöscht')][$entry->action] ?? $entry->action }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $entry->subjectLabel() }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal text-xs">
                        @if ($entry->action === 'updated')
                            @foreach ($entry->changes ?? [] as $field => $change)
                                <div><span class="text-zinc-500">{{ $field }}:</span> {{ $this->formatValue($change['old'] ?? null) }} → {{ $this->formatValue($change['new'] ?? null) }}</div>
                            @endforeach
                        @else
                            {{ collect($entry->changes)->except(['created_at', 'updated_at', 'id'])->map(fn ($v, $k) => $k.'='.$this->formatValue($v))->take(6)->join(', ') }}
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
