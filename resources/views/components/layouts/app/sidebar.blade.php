<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('Übersicht')" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
                    <flux:navlist.item icon="clipboard-document-check" :href="route('readings.status')" :current="request()->routeIs('readings.status')" wire:navigate>{{ __('Ablesestatus') }}</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Stammdaten')" class="grid">
                    <flux:navlist.item icon="users" :href="route('tenants.index')" :current="request()->routeIs('tenants.*')" wire:navigate>{{ __('Mieter') }}</flux:navlist.item>
                    <flux:navlist.item icon="bolt" :href="route('meters.index')" :current="request()->routeIs('meters.*')" wire:navigate>{{ __('Zähler') }}</flux:navlist.item>
                    <flux:navlist.item icon="pencil-square" :href="route('readings.index')" :current="request()->routeIs('readings.index')" wire:navigate>{{ __('Zählerstände') }}</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Analysator')" class="grid">
                    <flux:navlist.item icon="share" :href="route('analyzer.schema')" :current="request()->routeIs('analyzer.schema')" wire:navigate>{{ __('Leitungsschema') }}</flux:navlist.item>
                    <flux:navlist.item icon="cpu-chip" :href="route('analyzer.devices')" :current="request()->routeIs('analyzer.devices')" wire:navigate>{{ __('Analysator-Geräte') }}</flux:navlist.item>
                </flux:navlist.group>

                @can('view-finance')
                    <flux:navlist.group :heading="__('Abrechnung')" class="grid">
                        <flux:navlist.item icon="calculator" :href="route('settlements.index')" :current="request()->routeIs('settlements.*')" wire:navigate>{{ __('Abrechnung') }}</flux:navlist.item>
                        <flux:navlist.item icon="currency-euro" :href="route('prices.index')" :current="request()->routeIs('prices.*')" wire:navigate>{{ __('Strompreise') }}</flux:navlist.item>
                        <flux:navlist.item icon="document-chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>{{ __('Berichte') }}</flux:navlist.item>
                    </flux:navlist.group>
                @else
                    <flux:navlist.group :heading="__('Berichte')" class="grid">
                        <flux:navlist.item icon="document-chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>{{ __('Berichte') }}</flux:navlist.item>
                    </flux:navlist.group>
                @endcan

                @can('manage')
                    <flux:navlist.group :heading="__('Verwaltung')" class="grid">
                        <flux:navlist.item icon="user-group" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Benutzer') }}</flux:navlist.item>
                        <flux:navlist.item icon="cog-6-tooth" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Einstellungen') }}</flux:navlist.item>
                        <flux:navlist.item icon="clock" :href="route('admin.activity')" :current="request()->routeIs('admin.activity')" wire:navigate>{{ __('Änderungsprotokoll') }}</flux:navlist.item>
                    </flux:navlist.group>
                @endcan
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <x-user-menu />
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <x-user-menu />
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <flux:toast />

        @fluxScripts
    </body>
</html>
