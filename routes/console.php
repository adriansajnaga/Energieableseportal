<?php

use Illuminate\Support\Facades\Schedule;

// Auf dem Server genügt ein Cronjob: * * * * * php /pfad/artisan schedule:run

// Erinnerungen an fehlende Ablesungen (Tag in den Einstellungen).
Schedule::command('energie:reading-reminders')->dailyAt('08:00');

// E-Mails aus der Warteschlange versenden (ohne dauerhaft laufenden Worker, geeignet für Shared Hosting).
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();
