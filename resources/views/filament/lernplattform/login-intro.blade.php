{{-- Written onto the exercise-book page right of the margin line; beside the login card on large screens, above it on phones. --}}
<section class="lp-login-intro w-full max-w-xl ps-16 pe-6 pt-10 sm:pt-16 lg:ps-0 lg:pt-0">
    <div class="flex items-center gap-3">
        @include('filament.lernplattform.brand-mark', ['class' => 'size-10 lg:size-12'])

        <span class="text-xl font-semibold tracking-tight text-gray-950 lg:text-2xl dark:text-white">
            {{ filament()->getBrandName() }}
        </span>
    </div>

    <p class="mt-6 text-2xl font-semibold tracking-tight text-balance text-primary-800 sm:text-3xl lg:mt-10 lg:text-5xl lg:leading-tight dark:text-primary-200">
        Vom Unterricht zur Hausaufgabe zum Verstehen.
    </p>

    <p class="mt-4 hidden max-w-md text-base text-pretty text-gray-700 sm:block lg:text-lg dark:text-gray-300">
        Lehrkräfte halten ihre Stunde fest, die KI macht daraus passende Hausaufgaben, und Schüler:innen sehen, was sie schon richtig verstanden haben.
    </p>
</section>
