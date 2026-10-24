@php
    use App\MessageAuthor;

    $chatMessages = $getRecord()->messages;
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($chatMessages->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Es gibt noch keine Nachrichten in dieser Sitzung.') }}</p>
    @else
        <ol class="space-y-4">
            @foreach ($chatMessages as $chatMessage)
                @php($fromStudent = $chatMessage->author === MessageAuthor::Student)

                <li @class(['flex items-end gap-2', 'flex-row-reverse' => $fromStudent])>
                    @unless ($fromStudent)
                        <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                            <x-filament::icon icon="heroicon-m-sparkles" class="size-4" />
                        </span>
                    @endunless

                    <div @class(['flex max-w-[85%] min-w-0 flex-col', 'items-end' => $fromStudent, 'items-start' => ! $fromStudent])>
                        <p class="mb-1 px-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ $fromStudent ? __('Schüler:in') : \App\Filament\Pages\ExplainIt::TUTOR_NAME }}
                            @if ($chatMessage->created_at)
                                · <time datetime="{{ $chatMessage->created_at->toIso8601String() }}">{{ $chatMessage->created_at->format('d.m.Y H:i') }}</time>
                            @endif
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
    @endif
</x-dynamic-component>
