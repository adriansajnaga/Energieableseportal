<?php

namespace App\Models;

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Reading extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'meter_id', 'tenant_id', 'value', 'read_on', 'is_base', 'source', 'reader_name',
        'photo_path', 'status', 'check_note', 'created_by', 'updated_by', 'legacy_id',
    ];

    protected $attributes = [
        'status' => 'approved',
        'source' => 'admin',
        'is_base' => false,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'read_on' => 'date',
            'is_base' => 'boolean',
            'source' => ReadingSource::class,
            'status' => ReadingStatus::class,
        ];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Freigegebene Ablesungen, die für Berechnungen verwendet werden dürfen. */
    public function scopeValid(Builder $query): void
    {
        $query->where('status', ReadingStatus::Approved);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('readings.photo', $this) : null;
    }

    public function deletePhoto(): void
    {
        if ($this->photo_path) {
            Storage::disk('local')->delete($this->photo_path);
        }
    }
}
