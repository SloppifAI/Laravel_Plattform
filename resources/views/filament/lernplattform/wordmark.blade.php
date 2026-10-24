{{-- The "tuto" wordmark: black letters on light, white letters on dark backgrounds; size it by height ($class). --}}
<img src="{{ asset('images/tuto-logo.webp') }}" alt="Tuto" width="479" height="160" class="{{ $class ?? 'h-9' }} w-auto shrink-0 dark:hidden" />
<img src="{{ asset('images/tuto-logo-dark.webp') }}" alt="Tuto" width="479" height="160" class="{{ $class ?? 'h-9' }} hidden w-auto shrink-0 dark:block" />
