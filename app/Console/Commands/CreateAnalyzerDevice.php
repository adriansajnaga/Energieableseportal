<?php

namespace App\Console\Commands;

use App\Models\AnalyzerDevice;
use Illuminate\Console\Command;

/**
 * Legt einen Analysator an und gibt den Token einmalig aus (gespeichert wird nur der SHA-256-Hash).
 *
 *   php artisan analyzer:create analizator-1
 */
class CreateAnalyzerDevice extends Command
{
    protected $signature = 'analyzer:create {name : Gerätename wie im Analysator eingestellt (max. 31 Zeichen)}';

    protected $description = 'Legt einen Energie-Analysator an und gibt URL und Token für das Gerät aus';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || mb_strlen($name) > 31) {
            $this->error('Der Name muss 1 bis 31 Zeichen lang sein.');

            return self::FAILURE;
        }

        if (AnalyzerDevice::query()->where('name', $name)->exists()) {
            $this->error("Ein Analysator mit dem Namen \"{$name}\" existiert bereits.");

            return self::FAILURE;
        }

        [, $token] = AnalyzerDevice::register($name);

        $this->info("Analysator \"{$name}\" angelegt.");
        $this->line('URL:   '.route('api.analyzer.readings'));
        $this->line('Token: '.$token);
        $this->warn('Der Token wird nur jetzt angezeigt. Bitte im Analysator eintragen.');

        return self::SUCCESS;
    }
}
