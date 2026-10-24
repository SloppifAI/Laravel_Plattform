<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Auth\RegisterSchool;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Http\Middleware\EnsureSchoolIsActive;
use App\Http\Middleware\SetLocale;
use Filament\Actions\Action;
use Filament\Actions\View\ActionsIconAlias;
use Filament\FontProviders\LocalFontProvider;
use Filament\Forms\View\FormsIconAlias;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class LernplattformPanelProvider extends PanelProvider
{
    /**
     * Königsblau, the classic school fountain-pen ink. Deeper and calmer than Tailwind's blue;
     * 600 carries white text at 7.3:1, 500 at 4.9:1 and 400 reads at 6.5:1 on the dark sidebar.
     *
     * @var array<int, string>
     */
    public const array KOENIGSBLAU = [
        50 => 'oklch(0.97 0.015 267)',
        100 => 'oklch(0.93 0.035 267)',
        200 => 'oklch(0.87 0.065 267)',
        300 => 'oklch(0.78 0.11 267)',
        400 => 'oklch(0.68 0.16 267)',
        500 => 'oklch(0.56 0.2 267)',
        600 => 'oklch(0.47 0.2 267)',
        700 => 'oklch(0.41 0.175 267)',
        800 => 'oklch(0.355 0.145 267)',
        900 => 'oklch(0.3 0.11 267)',
        950 => 'oklch(0.225 0.075 267)',
    ];

    /**
     * The board wants no eye icons anywhere: Filament's own "view" action and the show/hide toggle of a password
     * field, which would otherwise carry one, get neutral icons.
     */
    public function boot(): void
    {
        FilamentIcon::register([
            ActionsIconAlias::VIEW_ACTION => Heroicon::OutlinedDocumentText,
            ActionsIconAlias::VIEW_ACTION_GROUPED => Heroicon::OutlinedDocumentText,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD => Heroicon::OutlinedLockOpen,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD => Heroicon::OutlinedLockClosed,
        ]);
    }

    /**
     * The language the user menu offers: the first available one that is not the current one.
     */
    private function otherLocale(): string
    {
        return collect(array_keys(config('lernplattform.locale.available')))
            ->first(fn (string $locale): bool => $locale !== app()->getLocale());
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('lernplattform')
            ->path('')
            ->login(Login::class)
            ->registration(Register::class)
            // Guest page for new schools; named filament.lernplattform.auth.register-school like the other auth routes.
            ->livewireComponents([RegisterSchool::class])
            ->routes(fn () => Route::get('/schule-registrieren', RegisterSchool::class)->name('auth.register-school'))
            ->passwordReset(RequestPasswordReset::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('Tuto')
            ->brandLogo(fn (): View => view('filament.lernplattform.brand'))
            ->brandLogoHeight('2.25rem')
            // Resolved per request so the URL picks up the HTTPS scheme behind the proxy.
            ->favicon(fn (): string => asset('favicon.svg'))
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Lexend was designed to improve reading proficiency, which suits learning content.
            // Self-hosted (public/fonts/lexend) so pupils' browsers make no request to a third-party font CDN.
            ->font(
                'Lexend',
                url: fn (): string => asset('fonts/lexend/lexend.css'),
                provider: LocalFontProvider::class,
                preload: fn (): array => [asset('fonts/lexend/lexend-latin-wght-normal.woff2')],
            )
            ->colors([
                'primary' => self::KOENIGSBLAU,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'info' => Color::Sky,
                'danger' => Color::Rose,
            ])
            ->viteTheme('resources/css/filament/lernplattform/theme.css')
            ->sidebarCollapsibleOnDesktop()
            // The bell: Notify puts entries into the notifications table; one minute of polling is plenty for homework.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn (): View => view('filament.lernplattform.login-intro'),
                scopes: Login::class,
            )
            // Impressum and Datenschutzerklärung on the login page and below every panel page.
            ->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('filament.lernplattform.footer'))
            // Every resource needs a policy, so a missing permission rule fails loudly instead of granting access.
            ->strictAuthorization()
            // The sidebar groups are the cases of App\Filament\NavigationGroup (order and translated label come from there).
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // After the session and the sign-in are known; persistent, so Livewire requests speak the language of the page.
            ->middleware([SetLocale::class], isPersistent: true)
            ->userMenuItems([
                Action::make('switchLocale')
                    ->label(fn (): string => __('Sprache: :language', ['language' => config('lernplattform.locale.available')[$this->otherLocale()]]))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn (): string => route('locale.switch', $this->otherLocale())),
            ])
            // Persistent, so a Livewire request from a page opened before the school was blocked is covered too.
            ->authMiddleware([
                EnsureSchoolIsActive::class,
            ], isPersistent: true);
    }
}
