<?php

use App\Enums\Role;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Benutzer')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $role = 'caretaker';
    public string $locale = 'de';
    public bool $is_active = true;
    public string $password = '';

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('user-form')->show();
    }

    public function edit(User $user): void
    {
        $this->resetForm();
        $this->editingId = $user->id;
        $this->fill([
            'name' => $user->name,
            'username' => (string) $user->username,
            'email' => $user->email,
            'role' => $user->role->value,
            'locale' => $user->locale,
            'is_active' => $user->is_active,
        ]);
        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($this->editingId)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->editingId)],
            'role' => ['required', new Enum(Role::class)],
            'locale' => ['required', Rule::in(array_keys(SetLocale::LOCALES))],
            'is_active' => ['boolean'],
            'password' => [$this->editingId ? 'nullable' : 'required', Password::defaults()],
        ]);

        // Der eigene Zugang darf nicht gesperrt oder herabgestuft werden.
        if ($this->editingId === Auth::id() && (! $this->is_active || $this->role !== Role::Admin->value)) {
            $this->addError('role', __('Sie können Ihre eigene Administratorrolle nicht entfernen.'));

            return;
        }

        if (! $data['password']) {
            unset($data['password']);
        }

        $data['username'] = $data['username'] ?: null;

        User::updateOrCreate(['id' => $this->editingId], $data + ['email_verified_at' => now()]);

        unset($this->users);
        Flux::modal('user-form')->close();
        Flux::toast(__('Benutzer gespeichert.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'username', 'email', 'password']);
        $this->role = Role::Caretaker->value;
        $this->locale = 'de';
        $this->is_active = true;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header :title="__('Benutzer')" :subtitle="__('Zugänge für Verwaltung und Hausmeister')">
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Benutzer anlegen') }}</flux:button>
    </x-page-header>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Benutzername') }}</flux:table.column>
            <flux:table.column>{{ __('E-Mail') }}</flux:table.column>
            <flux:table.column>{{ __('Rolle') }}</flux:table.column>
            <flux:table.column>{{ __('Sprache') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Aktiv') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell variant="strong">{{ $user->name }}</flux:table.cell>
                    <flux:table.cell>{{ $user->username }}</flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm">{{ $user->role->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ SetLocale::LOCALES[$user->locale] ?? $user->locale }}</flux:table.cell>
                    <flux:table.cell align="center"><flux:badge size="sm" :color="$user->is_active ? 'green' : 'zinc'">{{ $user->is_active ? __('Ja') : __('Nein') }}</flux:badge></flux:table.cell>
                    <flux:table.cell align="end"><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $user->id }})" /></flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Benutzer bearbeiten') : __('Benutzer anlegen') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="username" :label="__('Benutzername')" />
                <flux:input wire:model="email" :label="__('E-Mail')" type="email" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="role" :label="__('Rolle')">
                    @foreach (Role::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="locale" :label="__('Sprache')">
                    @foreach (SetLocale::LOCALES as $code => $label)
                        <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:input wire:model="password" :label="$editingId ? __('Neues Passwort (optional)') : __('Passwort')" type="password" autocomplete="new-password" />
            <flux:switch wire:model="is_active" :label="__('Aktiv')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
