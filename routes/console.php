<?php

use Illuminate\Support\Facades\Schedule;

// Mit proc_open genügt ein Cronjob: * * * * * php /pfad/artisan schedule:run
// Beim aktuellen Hoster ist proc_open gesperrt, dort laufen stattdessen zwei Cronjobs direkt:
//   * * * * *  deploy/artisan.sh queue:work --stop-when-empty --tries=3
//   0 8 * * *  deploy/artisan.sh energie:reading-reminders

// Erinnerungen an fehlende Ablesungen (Tag in den Einstellungen).
Schedule::command('energie:reading-reminders')->dailyAt('08:00');

// E-Mails aus der Warteschlange versenden (ohne dauerhaft laufenden Worker, geeignet für Shared Hosting).
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();
