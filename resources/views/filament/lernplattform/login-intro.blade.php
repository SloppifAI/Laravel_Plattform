{{-- Written onto the exercise-book page right of the margin line; beside the login card on large screens, above it on phones. --}}
<section class="lp-login-intro w-full max-w-xl ps-16 pe-6 pt-10 sm:pt-16 lg:ps-0 lg:pt-0">
    <div class="flex items-center">
        @include('filament.lernplattform.wordmark', ['class' => 'h-10 lg:h-12'])
    </div>

    <p class="mt-6 text-2xl font-semibold tracking-tight text-balance text-primary-800 sm:text-3xl lg:mt-10 lg:text-5xl lg:leading-tight dark:text-primary-200">
        {{ __('Lernen zum Verstehen') }}
    </p>

    <p class="mt-4 max-w-md text-base text-pretty text-gray-700 lg:text-lg dark:text-gray-300">
        {{ __('Vom Unterricht zur Hausaufgabe zum Verstehen.') }}
    </p>
</section>
