<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * Schreibt Anlegen, Ändern und Löschen eines Modells in das Änderungsprotokoll.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => $model->logActivity('created', $model->getAttributes()));

        static::updated(function ($model) {
            $changes = collect($model->getChanges())->except('updated_at');

            if ($changes->isEmpty()) {
                return;
            }

            $model->logActivity('updated', $changes->mapWithKeys(fn ($new, $key) => [
                $key => ['old' => $model->getOriginal($key), 'new' => $new],
            ])->all());
        });

        static::deleted(fn ($model) => $model->logActivity('deleted', $model->getAttributes()));
    }

    protected function logActivity(string $action, array $changes): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => static::class,
            'subject_id' => $this->getKey(),
            'changes' => collect($changes)->except(['password', 'remember_token', 'qr_token'])->all(),
        ]);
    }
}
