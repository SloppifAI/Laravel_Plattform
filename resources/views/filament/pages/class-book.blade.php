@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($classes->isEmpty())
        <x-filament::callout
            color="gray"
            :icon="Heroicon::OutlinedUserGroup"
            heading="Keine Klasse zugeordnet"
            :description="$isAdmin ? 'In deiner Schule gibt es noch keine Klasse. Lege zuerst eine Klasse an.' : 'Du leitest keine Klasse und bist keiner Klasse als Lehrkraft zugeordnet. Die Schuladministration kann dich einer Klasse zuordnen.'"
        />
    @else
        <div class="min-w-48 max-w-xs">
            <label for="class-book-class" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Klasse</label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="class-book-class" wire:model.live="classId">
                    @foreach ($classes as $class)
                        <option value="{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <x-filament::callout
            color="info"
            :icon="Heroicon::OutlinedLockClosed"
            heading="Fehlzeiten sind personenbezogen"
            description="Wer gefehlt hat, sehen nur die Lehrkräfte dieser Klasse (auch eine Vertretung, solange sie läuft) und die Schuladministration, nie die Schüler:innen."
        />

        @if ($schoolClass)
            {{ $this->table }}
        @endif
    @endif
</x-filament-panels::page>
