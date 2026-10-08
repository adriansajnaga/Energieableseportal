<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Rzut obiektu (Lageplan) zum Leitungsschema: Bild oder PDF auf dem privaten Speicher. */
class SitePlan extends Model
{
    public const MIMES = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

    protected $fillable = ['title', 'path', 'original_name', 'mime', 'size', 'position', 'uploaded_by'];

    protected static function booted(): void
    {
        static::deleted(fn (SitePlan $plan) => Storage::disk('local')->delete($plan->path));
    }

    public function markers(): HasMany
    {
        return $this->hasMany(SitePlanMarker::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function url(): string
    {
        return route('analyzer.plans.show', $this);
    }
}
