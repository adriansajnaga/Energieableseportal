<?php

namespace App\Models;

use App\Enums\SettlementType;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Settlement extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type', 'invoice_number', 'invoice_date', 'period', 'tenant_id', 'meter_id', 'main_meter_id',
        'start_reading_id', 'end_reading_id', 'sample_reading_id', 'starts_on', 'ends_on',
        'consumption_kwh', 'meter_factor', 'billed_kwh', 'base_price', 'price_factor', 'unit_price',
        'net_amount', 'vat_rate', 'vat_amount', 'gross_amount', 'is_invoiced', 'cancels_id',
        'cancelled_at', 'emailed_at', 'emailed_to', 'created_by', 'legacy_id',
    ];

    protected $attributes = [
        'type' => 'invoice',
    ];

    protected function casts(): array
    {
        return [
            'type' => SettlementType::class,
            'invoice_date' => 'date',
            'period' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'consumption_kwh' => 'integer',
            'billed_kwh' => 'integer',
            'meter_factor' => 'integer',
            'base_price' => 'decimal:5',
            'price_factor' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'is_invoiced' => 'boolean',
            'cancelled_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function mainMeter(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'main_meter_id');
    }

    public function startReading(): BelongsTo
    {
        return $this->belongsTo(Reading::class, 'start_reading_id');
    }

    public function endReading(): BelongsTo
    {
        return $this->belongsTo(Reading::class, 'end_reading_id');
    }

    public function cancels(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'cancels_id');
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(Settlement::class, 'cancels_id');
    }

    /** Monatsabrechnungen, die in einer Sammelrechnung stehen (nur bei type = collective). */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'collective_items', 'collective_id', 'settlement_id')
            ->withTimestamps()
            ->orderBy('period');
    }

    /** Sammelrechnungen, in denen diese Monatsabrechnung vorkommt (auch stornierte). */
    public function collectives(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'collective_items', 'settlement_id', 'collective_id')
            ->withTimestamps();
    }

    public function activeCollective(): ?Settlement
    {
        return $this->collectives->first(fn (Settlement $c) => ! $c->isCancelled());
    }

    /** Rechnungsnummer der Abrechnung selbst oder der Sammelrechnung, in der sie steht. */
    public function invoiceLabel(): string
    {
        if ($this->invoice_number) {
            return $this->formattedNumber();
        }

        return $this->activeCollective()?->formattedNumber() ?? '---';
    }

    /** Zählernummer(n) des Belegs; bei Sammelrechnungen alle enthaltenen Zähler. */
    public function meterNumbers(): string
    {
        $collective = $this->type === SettlementType::Cancellation ? $this->cancels : $this;

        if ($collective?->type === SettlementType::Collective) {
            return $collective->items()->with('meter')->get()->pluck('meter.number')->unique()->join(', ');
        }

        return (string) $this->meter?->number;
    }

    /** Rechnungsbelege mit Nummer (Einzel-, Sammel- und Stornorechnungen). */
    public function scopeNumbered(Builder $query): void
    {
        $query->whereNotNull('invoice_number');
    }

    /** Monatsabrechnungen ohne eigene Rechnung, die noch in keiner gültigen Sammelrechnung stehen. */
    public function scopeOpenForCollection(Builder $query): void
    {
        $query->effective()
            ->where('is_invoiced', false)
            ->whereHas('tenant', fn (Builder $q) => $q->where('issues_invoices', true))
            ->whereDoesntHave('collectives', fn (Builder $q) => $q->whereNull('cancelled_at'));
    }

    /** Gültige (nicht stornierte) Abrechnungen. */
    public function scopeEffective(Builder $query): void
    {
        $query->where('type', SettlementType::Invoice)->whereNull('cancelled_at');
    }

    public function formattedNumber(): string
    {
        if (! $this->invoice_number) {
            return '---';
        }

        return Setting::get('invoice_prefix')
            .str_pad((string) $this->invoice_number, (int) Setting::get('invoice_digits'), '0', STR_PAD_LEFT);
    }

    /** Abrechnung eines Pauschalmieters (nur zur Kontrolle, nie eine Rechnung). */
    public function isFlatRate(): bool
    {
        return $this->type === SettlementType::Invoice && ! $this->invoice_number && ! $this->tenant->issues_invoices;
    }

    /** Nur Belege mit Rechnungsnummer können per E-Mail verschickt werden. */
    public function canBeEmailed(): bool
    {
        return $this->invoice_number !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
