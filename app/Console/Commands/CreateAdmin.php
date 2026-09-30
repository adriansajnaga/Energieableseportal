<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Legt den ersten Administrator auf dem Server an (ohne Demodaten aus dem Seeder).
 *
 * Interaktiv:        php artisan energie:create-admin
 * Ohne Rückfragen:   php artisan energie:create-admin --username=admin --email=a@b.de --name="Admin"
 *                    (z. B. per Cron). Das Passwort wird dann zufällig erzeugt und ausgegeben.
 */
class CreateAdmin extends Command
{
    protected $signature = 'energie:create-admin
        {--name= : Name}
        {--username= : Benutzername}
        {--email= : E-Mail}';

    protected $description = 'Legt einen Administrator an';

    public function handle(): int
    {
        $generated = false;

        if ($this->option('username')) {
            $data = [
                'name' => $this->option('name') ?: $this->option('username'),
                'username' => $this->option('username'),
                'email' => $this->option('email'),
                'password' => Str::password(16, symbols: false),
            ];
            $generated = true;
        } else {
            $data = [
                'name' => $this->ask('Name'),
                'username' => $this->ask('Benutzername'),
                'email' => $this->ask('E-Mail'),
                'password' => $this->secret('Passwort (min. 8 Zeichen)'),
            ];
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create($data + ['role' => Role::Admin, 'is_active' => true, 'email_verified_at' => now()]);

        $this->info("Administrator {$data['username']} angelegt.");

        if ($generated) {
            $this->line("Passwort: {$data['password']}");
            $this->warn('Bitte nach der ersten Anmeldung unter Einstellungen > Passwort ändern und diese Ausgabe löschen.');
        }

        return self::SUCCESS;
    }
}
