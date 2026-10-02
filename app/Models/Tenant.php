<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name', 'debtor_number', 'street', 'zip', 'city', 'phone', 'email',
        'price_factor', 'issues_invoices', 'send_invoices_by_email', 'is_active', 'active_from', 'active_until', 'legacy_id',
    ];

    protected $attributes = [
        'issues_invoices' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price_factor' => 'decimal:3',
            'issues_invoices' => 'boolean',
            'send_invoices_by_email' => 'boolean',
            'is_active' => 'boolean',
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MeterAssignment::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function effectivePriceFactor(): float
    {
        return (float) ($this->price_factor ?? Setting::get('price_factor'));
    }
}
