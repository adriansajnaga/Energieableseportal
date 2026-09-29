<?php

namespace App\Services;

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\MeterAssignment;
use App\Models\Reading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

class ReadingService
{
    public function __construct(private PlausibilityChecker $checker) {}

    public function record(
        Meter $meter,
        int $value,
        CarbonInterface $date,
        ReadingSource $source,
        ?string $readerName = null,
        ?UploadedFile $photo = null,
        ?User $user = null,
        bool $isBase = false,
    ): Reading {
        $check = $this->checker->check($meter, $value, $date);

        return Reading::create([
            'meter_id' => $meter->id,
            'tenant_id' => $this->tenantIdAt($meter, $date),
            'value' => $value,
            'read_on' => $date->toDateString(),
            'is_base' => $isBase,
            'source' => $source,
            'reader_name' => $readerName ?? $user?->name,
            'photo_path' => $photo ? $this->storePhoto($meter, $photo) : null,
            'status' => $check['status'],
            'check_note' => $check['note'],
            'created_by' => $user?->id,
        ]);
    }

    public function update(Reading $reading, int $value, CarbonInterface $date, bool $isBase, ?UploadedFile $photo, ?User $user): Reading
    {
        $check = $this->checker->check($reading->meter, $value, $date, $reading->id);

        if ($photo) {
            $reading->deletePhoto();
            $reading->photo_path = $this->storePhoto($reading->meter, $photo);
        }

        $reading->fill([
            'value' => $value,
            'read_on' => $date->toDateString(),
            'is_base' => $isBase,
            'tenant_id' => $this->tenantIdAt($reading->meter, $date),
            'updated_by' => $user?->id,
        ]);

        // Manuelle Korrekturen der Verwaltung gelten als geprüft, nur Auffälligkeiten werden vermerkt.
        $reading->check_note = $check['note'];
        $reading->status = $user?->isAdmin() ? ReadingStatus::Approved : $check['status'];
        $reading->save();

        return $reading;
    }

    public function approve(Reading $reading, ?User $user): void
    {
        $reading->update(['status' => ReadingStatus::Approved, 'updated_by' => $user?->id]);
    }

    public function reject(Reading $reading, ?User $user): void
    {
        $reading->update(['status' => ReadingStatus::Rejected, 'is_base' => false, 'updated_by' => $user?->id]);
    }

    public function tenantIdAt(Meter $meter, CarbonInterface $date): ?int
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return MeterAssignment::query()
            ->where('meter_id', $meter->id)
            ->overlapping($day, $day->addDay())
            ->orderByDesc('starts_on')
            ->value('tenant_id') ?? $meter->tenant_id;
    }

    private function storePhoto(Meter $meter, UploadedFile $photo): string
    {
        $name = $meter->id.'_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$photo->extension();

        return $photo->storeAs('readings', $name, 'local');
    }
}
