{{--
    Consent page for connecting an app (AuthorizeApp). The form posts the checked request back to
    AppAuthorizationController::store, which checks it again; only the button decides between code and refusal.
--}}
@php
    use Filament\Support\Icons\Heroicon;

    /** @var array<string, string>|null $authorization */
@endphp

<x-filament-panels::page.simple>
    @if ($authorization === null)
        <div class="grid gap-6">
            <p class="text-sm text-gray-700 dark:text-gray-300" role="alert">{{ $problem }}</p>

            <x-filament::button tag="a" :href="url('/')" color="gray">
                {{ __('Zurück zur Startseite') }}
            </x-filament::button>
        </div>
    @else
        <div class="grid gap-6">
            <section aria-labelledby="lp-app-may-heading">
                <h2 id="lp-app-may-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('Die App darf') }}
                </h2>

                <ul class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ([
                        $isAdmin ? __('die Stunden und Klassen deiner Schule sehen') : __('deine Stunden und Klassen sehen'),
                        __('die Fächer deiner Schule sehen'),
                        __('deinen Stundenplan sehen'),
                        $isAdmin ? __('die Namen der Schüler:innen deiner Schule für die lokale Pseudonymisierung sehen') : __('die Namen deiner Schüler:innen für die lokale Pseudonymisierung sehen'),
                        __('neue Stunden anlegen'),
                        __('die Zusammenfassung einer Stunde setzen'),
                    ] as $permission)
                        <li class="flex gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="size-5 shrink-0 text-primary-600 dark:text-primary-400" aria-hidden="true" />
                            <span>{{ $permission }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-labelledby="lp-app-may-not-heading">
                <h2 id="lp-app-may-not-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('Die App darf nicht') }}
                </h2>

                <ul class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ([
                        $isAdmin ? __('weitere Daten der Schüler:innen deiner Schule sehen') : __('weitere Daten deiner Schüler:innen sehen'),
                        __('Ergebnisse und Abgaben sehen'),
                        __('dein Passwort erfahren'),
                    ] as $restriction)
                        <li class="flex gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedXCircle" class="size-5 shrink-0 text-gray-500 dark:text-gray-400" aria-hidden="true" />
                            <span>{{ $restriction }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Du kannst den Zugang jederzeit in deinem Profil unter „Verbundene Apps“ entziehen.') }}
            </p>

            <form method="POST" action="{{ route('app.authorize.store') }}" class="flex flex-wrap gap-3">
                @csrf

                @foreach ($authorization as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach

                <x-filament::button type="submit" name="decision" value="allow">
                    {{ __('Erlauben') }}
                </x-filament::button>

                <x-filament::button type="submit" name="decision" value="deny" color="gray">
                    {{ __('Abbrechen') }}
                </x-filament::button>
            </form>
        </div>
    @endif
</x-filament-panels::page.simple>
