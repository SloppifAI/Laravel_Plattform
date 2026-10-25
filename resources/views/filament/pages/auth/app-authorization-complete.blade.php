{{--
    Completion page after "Erlauben" or "Abbrechen" (AppAuthorizationController::store), in the frame of the consent
    page without a topbar. The button is the reliable way back to the app; the meta refresh tries the link on load.
    The return URL holds the one-time code, so it only appears in the link, never as text.
--}}
@php
    use Filament\Support\Facades\FilamentView;
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsRenderHook;

    /**
     * @var string $appName
     * @var bool $isAllowed
     * @var string $returnUrl
     */

    $heading = $isAllowed ? __(':app ist verbunden', ['app' => $appName]) : __('Verbindung abgelehnt');

    // The page skeleton takes the browser title from a page object; this plain view has none, so it would say only "Tuto".
    $titled = new class($heading) {
        public function __construct(private string $title) {}

        public function getTitle(): string
        {
            return $this->title;
        }

        public function getRenderHookScopes(): array
        {
            return [];
        }

        public function getExtraBodyAttributes(): array
        {
            return [];
        }
    };
@endphp

@push('styles')
    <meta http-equiv="refresh" content="0; url={{ $returnUrl }}">
@endpush

<x-filament-panels::layout.base :livewire="$titled">
    <div class="fi-simple-layout">
        <div class="fi-simple-main-ctn">
            <main id="fi-main-content" tabindex="-1" class="fi-simple-main fi-width-lg">
                <div class="fi-simple-page">
                    <div class="fi-simple-page-content">
                        <x-filament-panels::header.simple
                            :heading="$heading"
                        />

                        <div class="grid gap-6">
                            <p class="text-sm text-gray-700 dark:text-gray-300" role="status">
                                {{ $isAllowed
                                    ? __('Du kannst dieses Fenster jetzt schließen und zu :app zurückkehren.', ['app' => $appName])
                                    : __(':app bekommt keinen Zugang. Du kannst dieses Fenster jetzt schließen.', ['app' => $appName]) }}
                            </p>

                            <x-filament::button
                                tag="a"
                                :href="$returnUrl"
                                :color="$isAllowed ? 'primary' : 'gray'"
                                :icon="Heroicon::OutlinedArrowTopRightOnSquare"
                            >
                                {{ $isAllowed ? __(':app öffnen', ['app' => $appName]) : __('Zurück zu :app', ['app' => $appName]) }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        {{ FilamentView::renderHook(PanelsRenderHook::FOOTER) }}
    </div>
</x-filament-panels::layout.base>
