<?php

namespace App\Models;

use App\Enums\SettlementType;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Settlement extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type', 'invoice_number', 'invoice_date', 'period', 'tenant_id', 'meter_id', 'main_meter_id',
        'start_reading_id', 'end_reading_id', 'sample_reading_id', 'starts_on', 'ends_on',
        'consumption_kwh', 'meter_factor', 'billed_kwh', 'base_price', 'price_factor', 'unit_price',
        'net_amount', 'vat_rate', 'vat_amount', 'gross_amount', 'is_invoiced', 'cancels_id',
        'cancelled_at', 'emailed_at', 'created_by', 'legacy_id',
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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
