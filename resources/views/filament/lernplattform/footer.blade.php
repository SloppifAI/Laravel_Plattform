@php
    use Illuminate\Support\Facades\Route;

    $legalLinks = array_filter([
        __('Impressum') => Route::has('legal.imprint') ? route('legal.imprint') : null,
        __('Datenschutzerklärung') => Route::has('legal.privacy') ? route('legal.privacy') : null,
        __('Auftragsverarbeitung (AVV)') => Route::has('legal.dpa') ? route('legal.dpa') : null,
    ]);

    // Signed-in people switch the language in the user menu and on their profile; the login and registration pages offer it here.
    $languages = filament()->auth()->check() ? [] : config('lernplattform.locale.available');
@endphp

@if ($legalLinks !== [] || $languages !== [])
    <footer class="lp-footer w-full px-4 py-6">
        <ul class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-400">
            @foreach ($legalLinks as $label => $url)
                <li>
                    <a href="{{ $url }}" class="lp-focus rounded-sm underline-offset-4 hover:text-gray-950 hover:underline dark:hover:text-white">
                        {{ $label }}
                    </a>
                </li>
            @endforeach

            @if ($languages !== [])
                <li>
                    <nav aria-label="{{ __('Sprache') }}" class="flex items-center gap-x-2">
                        @foreach ($languages as $code => $name)
                            @if (! $loop->first)
                                <span aria-hidden="true">|</span>
                            @endif

                            @if ($code === app()->getLocale())
                                <span lang="{{ $code }}" aria-current="true" class="font-semibold text-gray-950 dark:text-white">{{ $name }}</span>
                            @else
                                <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}" class="lp-focus rounded-sm underline-offset-4 hover:text-gray-950 hover:underline dark:hover:text-white">{{ $name }}</a>
                            @endif
                        @endforeach
                    </nav>
                </li>
            @endif
        </ul>
    </footer>
@endif
