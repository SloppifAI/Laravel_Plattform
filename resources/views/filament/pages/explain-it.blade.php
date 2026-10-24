@php
    use App\MessageAuthor;
    use App\UnderstandingLevel;

    $tutorName = $this::TUTOR_NAME;
    $tutorInitial = mb_substr($tutorName, 0, 1);
    $lastTutorMessage = $explanationSession?->messages->last(fn ($chatMessage): bool => $chatMessage->author === MessageAuthor::Tutor);
@endphp

<x-filament-panels::page>
    {{-- Always rendered, so screen readers announce each new question when its text changes. --}}
    <p class="sr-only" aria-live="polite">
        @if ($lastTutorMessage)
            {{ $tutorName }} fragt: {{ $lastTutorMessage->content }}
        @endif
    </p>

    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        <div class="min-w-0 lg:col-span-2">
            @if ($explanationSession === null)
                <x-filament::section>
                    <div class="flex items-start gap-4">
                        <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                            {{ $tutorInitial }}
                        </span>

                        <div class="min-w-0">
                            <h2 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                Erklär’s {{ $tutorName }}!
                            </h2>

                            <p class="mt-1 text-gray-600 dark:text-gray-400">
                                Wer etwas erklären kann, hat es wirklich verstanden. {{ $tutorName }} ist neugierig und kennt dein Thema noch nicht.
                            </p>
                        </div>
                    </div>

                    <ol class="mt-6 space-y-3">
                        @foreach ([
                            'Such dir ein Thema aus.',
                            "Erklär es in deinen eigenen Worten. {$tutorName} fragt nach, wenn etwas unklar ist.",
                            'Bist du fertig, beendest du die Erklärung. Dann siehst du, wie gut es geklappt hat.',
                        ] as $step)
                            <li class="flex gap-3">
                                <span aria-hidden="true" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                    {{ $loop->iteration }}
                                </span>

                                <p class="pt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <form wire:submit="start" class="mt-6 space-y-4 border-t border-gray-950/5 pt-6 dark:border-white/10">
                        {{ $this->form }}

                        <x-filament::button type="submit" icon="heroicon-m-sparkles" wire:target="start">
                            Los geht’s
                        </x-filament::button>
                    </form>
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-slot name="heading">{{ $explanationSession->topic }}</x-slot>

                    @if ($explanationSession->lesson)
                        <x-slot name="description">{{ $explanationSession->lesson->subject }}</x-slot>
                    @endif

                    <x-slot name="afterHeader">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($canAskTeacher)
                                <x-filament::button color="gray" outlined size="sm" icon="heroicon-m-question-mark-circle" wire:click="mountAction('askTeacher')">
                                    Frage an meine Lehrkraft
                                </x-filament::button>
                            @endif

                            <x-filament::button color="gray" size="sm" icon="heroicon-m-plus" wire:click="startOver">
                                Neue Erklärung
                            </x-filament::button>

                            <x-filament::button color="danger" outlined size="sm" icon="heroicon-m-trash" wire:click="mountAction('deleteSession', { session: {{ $explanationSession->id }} })">
                                Gespräch löschen
                            </x-filament::button>
                        </div>
                    </x-slot>

                    @if ($explanationSession->isFinished() && $explanationSession->understanding_score !== null)
                        @php($level = UnderstandingLevel::fromScore($explanationSession->understanding_score))

                        <div class="mb-6 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-gray-950 dark:text-white">Deine Auswertung</h3>

                                <x-filament::badge :color="$level->getColor()" :icon="$level->getIcon()">
                                    {{ $level->getLabel() }} · {{ $explanationSession->understanding_score }} von 100
                                </x-filament::badge>
                            </div>

                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $explanationSession->feedback }}</p>
                        </div>
                    @endif

                    <div
                        x-data
                        x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })"
                        role="region"
                        aria-label="Gesprächsverlauf"
                        tabindex="0"
                        class="-mx-2 max-h-[60vh] overflow-y-auto rounded-xl px-2 py-1 focus-visible:outline-2 focus-visible:outline-primary-600"
                    >
                        <ol class="space-y-4">
                            @foreach ($explanationSession->messages as $chatMessage)
                                @php($fromStudent = $chatMessage->author === MessageAuthor::Student)

                                <li wire:key="explanation-message-{{ $chatMessage->id }}" @class(['flex items-end gap-2', 'flex-row-reverse' => $fromStudent])>
                                    @unless ($fromStudent)
                                        <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                                            {{ $tutorInitial }}
                                        </span>
                                    @endunless

                                    <div data-read-aloud @class(['flex max-w-[85%] min-w-0 flex-col', 'items-end' => $fromStudent, 'items-start' => ! $fromStudent])>
                                        <p class="mb-1 px-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                            {{ $fromStudent ? 'Du' : $tutorName }}
                                        </p>

                                        <div @class([
                                            'whitespace-pre-line break-words rounded-2xl px-4 py-2 text-sm',
                                            'rounded-br-md bg-primary-600 text-white' => $fromStudent,
                                            'rounded-bl-md bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => ! $fromStudent,
                                        ])>{{ $chatMessage->content }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    @if ($explanationSession->lesson === null)
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            Fragen an deine Lehrkraft gibt es nur bei Themen aus deinen Hausaufgaben. Zu einem eigenen Thema kannst du hier nur {{ $tutorName }} etwas erklären.
                        </p>
                    @elseif (! $canAskTeacher)
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            Diese Stunde gehört nicht mehr zu deinen Hausaufgaben, oder deine Lehrkraft kann Fragen dazu nicht mehr beantworten. Deshalb kannst du dazu keine neue Frage stellen. Frag am besten deine Klassenleitung.
                        </p>
                    @endif

                    @if ($explanationSession->lesson && $explanationSession->student_questions_count > 0)
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            Du hast deiner Lehrkraft dazu {{ $explanationSession->student_questions_count === 1 ? 'eine Frage' : $explanationSession->student_questions_count.' Fragen' }} gestellt.
                            <a href="{{ \App\Filament\Pages\MyQuestions::getUrl() }}" class="font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400">Zu meinen Rückfragen</a>
                        </p>
                    @endif

                    @unless ($explanationSession->isFinished())
                        <div wire:loading.flex wire:target="send" role="status" class="mt-3 items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <x-filament::loading-indicator class="size-4" />
                            {{ $tutorName }} denkt nach …
                        </div>

                        <form wire:submit="send" x-data class="mt-4 border-t border-gray-950/5 pt-4 dark:border-white/10">
                            <label for="explain-it-message" class="text-sm font-medium text-gray-950 dark:text-white">
                                Deine Erklärung
                            </label>

                            <textarea
                                id="explain-it-message"
                                wire:model="newMessage"
                                rows="3"
                                maxlength="5000"
                                placeholder="Erklär es in deinen eigenen Worten …"
                                aria-describedby="explain-it-hint @error('newMessage') explain-it-error @enderror"
                                @error('newMessage') aria-invalid="true" @enderror
                                {{-- Livewire makes the field read-only while the form submits; skip Enter then to avoid a double send. --}}
                                x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing && ! $el.readOnly) { $event.preventDefault(); $el.form.requestSubmit() }"
                                class="mt-1 block w-full resize-y rounded-xl border-none bg-white px-4 py-3 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 focus:outline-hidden read-only:opacity-70 sm:text-sm dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500"
                            ></textarea>

                            <p id="explain-it-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Enter schickt ab. Mit Shift + Enter machst du eine neue Zeile.
                            </p>

                            @error('newMessage')
                                <p id="explain-it-error" class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                {{ $this->finishAction }}

                                <x-filament::button type="submit" icon="heroicon-m-paper-airplane" icon-position="after" wire:target="send">
                                    Senden
                                </x-filament::button>
                            </div>
                        </form>
                    @endunless
                </x-filament::section>
            @endif
        </div>

        <x-filament::section>
            <x-slot name="heading">Deine Erklärungen</x-slot>

            <ul class="-mx-2 space-y-1">
                @forelse ($earlierSessions as $earlierSession)
                    @php($isCurrent = $earlierSession->is($explanationSession))

                    <li wire:key="explanation-session-{{ $earlierSession->id }}" class="flex items-start gap-1">
                        <button
                            type="button"
                            wire:click="openSession({{ $earlierSession->id }})"
                            @if ($isCurrent) aria-current="true" @endif
                            @class([
                                'flex min-w-0 flex-1 flex-col items-start gap-1 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-600 dark:hover:bg-white/5',
                                'bg-primary-50 dark:bg-primary-400/10' => $isCurrent,
                            ])
                        >
                            <span class="font-medium break-words text-gray-950 dark:text-white">{{ $earlierSession->topic }}</span>

                            <span class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <time datetime="{{ $earlierSession->created_at->toDateString() }}">{{ $earlierSession->created_at->format('d.m.Y') }}</time>

                                @if ($earlierSession->isFinished() && $earlierSession->understanding_score !== null)
                                    @php($earlierLevel = UnderstandingLevel::fromScore($earlierSession->understanding_score))

                                    <x-filament::badge size="sm" :color="$earlierLevel->getColor()" :icon="$earlierLevel->getIcon()">
                                        {{ $earlierLevel->getLabel() }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge size="sm" color="gray" icon="heroicon-m-chat-bubble-left-ellipsis">
                                        Läuft noch
                                    </x-filament::badge>
                                @endif
                            </span>
                        </button>

                        <x-filament::icon-button
                            icon="heroicon-m-trash"
                            color="danger"
                            size="sm"
                            :label="'Gespräch löschen: '.$earlierSession->topic"
                            wire:click="mountAction('deleteSession', { session: {{ $earlierSession->id }} })"
                        />
                    </li>
                @empty
                    <li class="px-2 text-sm text-gray-600 dark:text-gray-400">
                        Noch keine Erklärungen. Leg einfach los!
                    </li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
