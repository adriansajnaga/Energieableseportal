<?php

namespace App\Models;

use App\Enums\Medium;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Meter extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'number', 'medium', 'location', 'calibration_year', 'factor', 'is_main', 'is_analyzer', 'parent_id', 'feed_id', 'tenant_id', 'is_active',
        'qr_token', 'legacy_hash', 'replaced_by_id', 'installed_on', 'removed_on', 'legacy_id',
    ];

    protected $hidden = ['qr_token'];

    protected $attributes = [
        'medium' => 'electricity',
    ];

    protected function casts(): array
    {
        return [
            'medium' => Medium::class,
            'calibration_year' => 'integer',
            'factor' => 'integer',
            'is_main' => 'boolean',
            'is_analyzer' => 'boolean',
            'is_active' => 'boolean',
            'installed_on' => 'date',
            'removed_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Meter $meter) {
            $meter->qr_token ??= static::newQrToken();
        });
    }

    public static function newQrToken(): string
    {
        return Str::random(40);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Meter::class, 'parent_id');
    }

    /** Vorgeschalteter Zähler im Leitungsschema (Abzweig mit Analysator-Messpunkt). */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'feed_id');
    }

    /** Zähler, die über diesen Zähler versorgt werden (Leitungsschema). */
    public function fedMeters(): HasMany
    {
        return $this->hasMany(Meter::class, 'feed_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(Meter::class, 'replaced_by_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MeterAssignment::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ElectricityPrice::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): void
    {
        $query->where('is_main', true);
    }

    /** Messpunkte des Analysators (werden nicht abgerechnet). */
    public function scopeAnalyzer(Builder $query): void
    {
        $query->where('is_analyzer', true);
    }

    /** Zähler, die im Monat eingebaut waren (aktiv oder erst in/nach diesem Monat ausgebaut). */
    public function scopeExistingIn(Builder $query, CarbonInterface $month): void
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();

        $query->where(fn (Builder $q) => $q->whereNull('installed_on')->orWhereDate('installed_on', '<', $start->addMonthNoOverflow()->toDateString()))
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereDate('removed_on', '>=', $start->toDateString()));
    }

    /** Nur Stromzähler (Abrechnung, Strompreise, Berichte). */
    public function scopeElectricity(Builder $query): void
    {
        $query->where('medium', Medium::Electricity->value);
    }

    public function scopeWater(Builder $query): void
    {
        $query->whereIn('medium', array_map(fn (Medium $m) => $m->value, Medium::water()));
    }

    /** Filter der Listen: '' = alle, 'water' = Kalt- und Warmwasser, sonst ein Medium. */
    public function scopeOfType(Builder $query, ?string $type): void
    {
        if ($type === 'water') {
            $query->water();
        } elseif ($medium = Medium::tryFrom((string) $type)) {
            $query->where('medium', $medium->value);
        }
    }

    public function isWater(): bool
    {
        return $this->medium->isWater();
    }

    public function unit(): string
    {
        return $this->medium->unit();
    }

    /** Gespeicherten Zählerstand anzeigen (Wasser: Liter -> m³ mit 3 Nachkommastellen). */
    public function formatValue(?int $value, bool $withUnit = false): string
    {
        return $value === null ? '–' : $this->medium->format($value, $withUnit);
    }

    /** Eingabe in gespeicherten Zählerstand umrechnen, null bei ungültiger Eingabe. */
    public function parseValue(string|int|float|null $input): ?int
    {
        return $this->medium->parse($input);
    }

    /** Letztes Jahr der Eichgültigkeit (Wasserzähler), null wenn unbekannt. */
    public function calibrationValidUntil(): ?int
    {
        $years = $this->medium->calibrationYears();

        return $years && $this->calibration_year ? $this->calibration_year + $years : null;
    }

    public function calibrationExpired(?int $year = null): bool
    {
        $until = $this->calibrationValidUntil();

        return $until !== null && $until < ($year ?? (int) now()->year);
    }

    /** Vorgeschalteter Knoten im Leitungsschema: Abzweig oder sonst der Hauptzähler. */
    public function upstreamId(): ?int
    {
        return $this->is_main ? null : ($this->feed_id ?? $this->parent_id);
    }

    /** Typ für Anzeige: Hauptzähler, Analysator-Messpunkt oder Unterzähler. */
    public function typeLabel(): string
    {
        return match (true) {
            $this->is_main => __('Hauptzähler'),
            $this->is_analyzer => __('Analysator-Messpunkt'),
            default => __('Unterzähler'),
        };
    }

    /** Der Hauptzähler, dessen Strompreis für diesen Zähler gilt. */
    public function priceMeterId(): ?int
    {
        return $this->is_main ? $this->id : $this->parent_id;
    }

    public function latestReading(): ?Reading
    {
        return $this->readings()->valid()->orderByDesc('read_on')->orderByDesc('value')->first();
    }

    public function tenantUrl(): string
    {
        return route('public.reading', $this->qr_token);
    }

    public function label(): string
    {
        return trim($this->number.($this->location ? ' / '.$this->location : ''));
    }
}
