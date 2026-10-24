@php
    use App\Models\SchoolClass;
    use Illuminate\Support\Collection;

    /** @var Collection<int, SchoolClass> $schoolClasses */
    /** @var string|null $registrationUrl */
@endphp

<div class="space-y-4">
    @if (filled($registrationUrl))
        <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">Registrierung auf dieser Seite:</p>
            <a href="{{ $registrationUrl }}" class="text-base font-medium break-all text-primary-600 underline underline-offset-4 dark:text-primary-400">
                {{ $registrationUrl }}
            </a>
        </div>
    @endif

    @forelse ($schoolClasses as $schoolClass)
        <div class="flex items-center justify-between gap-4 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/15">
            <span class="text-base font-medium text-gray-950 dark:text-white">{{ $schoolClass->name }}</span>
            <span class="font-mono text-2xl font-semibold tracking-widest text-gray-950 select-all dark:text-white">{{ $schoolClass->join_code }}</span>
        </div>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Du leitest noch keine Klasse. Die Schulverwaltung kann dir einen Klassencode geben.
        </p>
    @endforelse
</div>
