<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Legt den ersten Administrator auf dem Server an (ohne Demodaten aus dem Seeder).
 */
class CreateAdmin extends Command
{
    protected $signature = 'energie:create-admin';

    protected $description = 'Legt einen Administrator an (Passwort wird verdeckt abgefragt)';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Name'),
            'username' => $this->ask('Benutzername'),
            'email' => $this->ask('E-Mail'),
            'password' => $this->secret('Passwort (min. 8 Zeichen)'),
        ];

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

        return self::SUCCESS;
    }
}
