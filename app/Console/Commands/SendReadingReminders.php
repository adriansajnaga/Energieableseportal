<?php

namespace App\Console\Commands;

use App\Mail\ReadingReminderMail;
use App\Models\Setting;
use App\Services\Statistics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReadingReminders extends Command
{
    protected $signature = 'energie:reading-reminders {--force : Unabhängig vom eingestellten Erinnerungstag senden}';

    protected $description = 'Erinnert Mieter und Hausmeister an fehlende Zählerstände des laufenden Monats';

    public function handle(Statistics $statistics): int
    {
        $day = (int) Setting::get('reminder_day');

        if (! $this->option('force') && ($day === 0 || now()->day !== $day)) {
            $this->info('Heute ist kein Erinnerungstag.');

            return self::SUCCESS;
        }

        $missing = $statistics->metersWithoutReading(now());

        $byTenant = $missing->filter(fn ($m) => $m->tenant?->email)->groupBy('tenant_id');

        foreach ($byTenant as $meters) {
            Mail::to($meters->first()->tenant->email)->queue(new ReadingReminderMail($meters));
        }

        if (($caretaker = Setting::get('caretaker_email')) && $missing->isNotEmpty()) {
            Mail::to($caretaker)->queue(new ReadingReminderMail($missing, forCaretaker: true));
        }

        $this->info("Erinnerungen an {$byTenant->count()} Mieter, offene Zähler: {$missing->count()}.");

        return self::SUCCESS;
    }
}
