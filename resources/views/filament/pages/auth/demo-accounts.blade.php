@php
    use App\Role;
    use Filament\Support\Icons\Heroicon;

    /** @var array<string, Role> $accounts */
@endphp

<section x-data class="border-t border-dashed border-gray-300 pt-6 dark:border-white/15" aria-labelledby="lp-demo-accounts-heading">
    <h2 id="lp-demo-accounts-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
        Demo-Zugänge
    </h2>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        Wähle eine Rolle, dann stehen ihre Anmeldedaten im Formular. Passwort für alle:
        <span class="font-medium text-gray-950 dark:text-white">{{ $password }}</span>
    </p>

    <ul class="mt-4 grid gap-2">
        @foreach ($accounts as $username => $role)
            <li>
                <button
                    type="button"
                    wire:click="fillDemoAccount(@js($username))"
                    x-bind:aria-pressed="$wire.get('data.username') === @js($username) ? 'true' : 'false'"
                    class="group flex w-full items-center gap-3 rounded-xl bg-white px-3 py-2.5 text-start ring-1 ring-gray-950/10 transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 aria-pressed:bg-primary-50 aria-pressed:ring-2 aria-pressed:ring-primary-600 dark:bg-white/5 dark:ring-white/15 dark:hover:bg-white/10 dark:focus-visible:outline-primary-400 dark:aria-pressed:bg-primary-400/10 dark:aria-pressed:ring-primary-400"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 group-aria-pressed:bg-primary-600 group-aria-pressed:text-white dark:bg-primary-400/10 dark:text-primary-300 dark:group-aria-pressed:bg-primary-500">
                        <x-filament::icon
                            :icon="match ($role) {
                                Role::Operator => Heroicon::OutlinedBuildingLibrary,
                                Role::Admin => Heroicon::OutlinedCog6Tooth,
                                Role::Teacher => Heroicon::OutlinedPencilSquare,
                                Role::Student => Heroicon::OutlinedAcademicCap,
                            }"
                            class="size-5"
                        />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $role->getLabel() }}</span>
                        <span class="block truncate text-sm text-gray-600 dark:text-gray-400">Benutzername: {{ $username }}</span>
                    </span>

                    <x-filament::icon
                        :icon="Heroicon::CheckCircle"
                        class="hidden size-5 text-primary-600 group-aria-pressed:block dark:text-primary-400"
                    />
                </button>
            </li>
        @endforeach
    </ul>
</section>
