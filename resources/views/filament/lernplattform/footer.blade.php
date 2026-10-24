@php
    use Illuminate\Support\Facades\Route;

    $legalLinks = array_filter([
        'Impressum' => Route::has('legal.imprint') ? route('legal.imprint') : null,
        'Datenschutzerklärung' => Route::has('legal.privacy') ? route('legal.privacy') : null,
        'Auftragsverarbeitung (AVV)' => Route::has('legal.dpa') ? route('legal.dpa') : null,
    ]);
@endphp

@if ($legalLinks !== [])
    <footer class="lp-footer w-full px-4 py-6">
        <ul class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-400">
            @foreach ($legalLinks as $label => $url)
                <li>
                    <a href="{{ $url }}" class="lp-focus rounded-sm underline-offset-4 hover:text-gray-950 hover:underline dark:hover:text-white">
                        {{ $label }}
                    </a>
                </li>
            @endforeach
        </ul>
    </footer>
@endif
