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
        {{-- Below md the card stacks both values under a visible label; from md the labels are screen-reader-only and name and code sit in one row. --}}
        <dl class="flex flex-col gap-2 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-950/10 md:flex-row md:items-center md:justify-between md:gap-4 dark:bg-white/5 dark:ring-white/15">
            <div class="min-w-0">
                <dt class="text-sm text-gray-600 md:sr-only dark:text-gray-400">Klasse</dt>
                <dd class="text-base font-medium break-words text-gray-950 dark:text-white">{{ $schoolClass->name }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-sm text-gray-600 md:sr-only dark:text-gray-400">Klassencode</dt>
                <dd class="font-mono text-2xl font-semibold tracking-widest break-all text-gray-950 select-all dark:text-white">{{ $schoolClass->join_code }}</dd>
            </div>
        </dl>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Dir ist noch keine Klasse zugeordnet. Die Schulverwaltung kann dir eine Klasse zuweisen, dann erscheint hier ihr Klassencode.
        </p>
    @endforelse
</div>
