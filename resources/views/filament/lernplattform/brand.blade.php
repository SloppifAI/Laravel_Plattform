<div class="flex items-center gap-2.5">
    @include('filament.lernplattform.brand-mark', ['class' => 'size-9'])

    <span class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
        {{ filament()->getBrandName() }}
    </span>
</div>
