<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricityPrice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'meter_id', 'month', 'supplier_invoice_number', 'consumption_kwh', 'net_amount',
        'net_price', 'updated_by', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'consumption_kwh' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'net_price' => 'decimal:5',
        ];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}
