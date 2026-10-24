@php
    use App\ConsentStatus;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($classes->isEmpty())
        <x-filament::callout
            color="gray"
            :icon="Heroicon::OutlinedUserGroup"
            :heading="__('Keine Klasse zugeordnet')"
            :description="__('Dir ist noch keine Klasse zugeordnet. Sobald die Schuladministration dich einer Klasse zuweist, erscheint sie hier.')"
        />
    @else
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-48">
                <label for="consent-class" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Klasse') }}</label>

                <x-filament::input.wrapper>
                    <x-filament::input.select id="consent-class" wire:model.live="classId">
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($schoolClass)
                <div class="flex flex-wrap items-center gap-3">
                    {{ $this->generateLettersAction }}
                </div>
            @endif
        </div>

        @if ($form === null)
            <x-filament::callout
                color="warning"
                :icon="Heroicon::OutlinedExclamationTriangle"
                :heading="__('Es gibt noch keine Einwilligungserklärung')"
                :description="$isAdmin ? __('Veröffentliche zuerst den Text unter „Tuto-Mikro & Einwilligungen“, dann kannst du Elternbriefe erzeugen.') : __('Die Schuladministration muss zuerst den Text unter „Tuto-Mikro & Einwilligungen“ veröffentlichen. Erst dann kannst du Elternbriefe erzeugen.')"
            />
        @endif

        @if (! $school?->recordingsEnabled())
            <x-filament::callout
                color="info"
                :icon="Heroicon::OutlinedInformationCircle"
                :heading="__('Tuto-Mikro ist für eure Schule noch nicht freigeschaltet')"
                :description="__('Einwilligungen kannst du trotzdem schon einholen. Einschalten lässt sich das Mikro erst, wenn die Schuladministration es freigeschaltet hat.')"
            />
        @endif

        @if ($school?->require_student_consent)
            <x-filament::callout
                color="info"
                :icon="Heroicon::OutlinedAcademicCap"
                :heading="__('Zusätzliche Bestätigung der Schüler:innen')"
                :description="__('Eure Schule verlangt, dass die Schüler:innen nach der Einwilligung der Eltern selbst unter „Tuto-Mikro im Unterricht“ zustimmen.')"
            />
        @endif

        @if ($schoolClass)
            <section aria-labelledby="consent-summary" class="lp-card p-4 sm:p-6">
                <h2 id="consent-summary" class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ trans_choice(':granted von :total Einwilligung vollständig|:granted von :total Einwilligungen vollständig', $total, ['granted' => $grantedCount, 'total' => $total]) }}
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Das Tuto-Mikro geht nur an, wenn für alle anwesenden Schüler:innen die Einwilligung vollständig ist.') }}
                </p>

                @if ($statusCounts !== [])
                    <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('Übrige Schüler:innen nach Status') }}">
                        @foreach ($statusCounts as $value => $count)
                            @php($status = ConsentStatus::from($value))

                            <li>
                                <x-filament::badge :color="$status->getColor()" :icon="$status->getIcon()">
                                    {{ $count }} {{ $status->getLabel() }}
                                </x-filament::badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{ $this->table }}

            {{-- The letters are made by a plain POST, so the response is the printable page itself. --}}
            <form id="consent-letters-form" method="POST" action="{{ route('consents.letters', $schoolClass) }}" hidden>
                @csrf
                <input type="hidden" name="scope" value="new">
                <input type="hidden" name="student" value="">
            </form>
        @endif
    @endif
</x-filament-panels::page>
