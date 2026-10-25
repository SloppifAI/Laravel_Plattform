<?php

use App\Filament\Pages\Auth\AuthorizeApp;
use App\Http\Controllers\AppAuthorizationController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\ConsentLetterController;
use App\Http\Controllers\LessonRecordingController;
use App\Http\Controllers\SchoolDpaController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Http\Middleware\FrameGuard;
use Illuminate\Support\Facades\Route;

/*
 * The legal pages are public and need no session, so they skip the whole web middleware group:
 * a visitor who only reads the Impressum gets no cookie and leaves no row in the sessions table.
 */
Route::view('/impressum', 'filament.legal.imprint')
    ->withoutMiddleware('web')
    ->name('legal.imprint');

Route::view('/datenschutzerklaerung', 'filament.legal.privacy')
    ->withoutMiddleware('web')
    ->name('legal.privacy');

Route::view('/auftragsverarbeitung', 'filament.legal.avv')
    ->withoutMiddleware('web')
    ->name('legal.dpa');

/*
 * Language switch (login page and user menu): needs the session, so it keeps the web group. A signed-in user's choice is
 * stored on the account, a guest's in the session; SwitchLocaleController only accepts the configured languages.
 */
Route::get('/sprache/{locale}', SwitchLocaleController::class)->name('locale.switch');

/*
 * The same agreement with the master data of the administration's own school filled in. It is not a public page and
 * takes no school parameter: the school is always the one of the signed-in school administration.
 */
Route::middleware([AuthenticateSession::class, Authenticate::class])->group(function (): void {
    Route::get('/meine-schule/auftragsverarbeitung', SchoolDpaController::class)->name('legal.dpa.school');

    // The text the school accepted, kept as it was shown (DpaAcceptance), for as long as no newer version is accepted.
    Route::get('/meine-schule/auftragsverarbeitung/angenommen', [SchoolDpaController::class, 'accepted'])->name('legal.dpa.school.accepted');
});

/*
 * Tuto-Mikro: start, sound segments, end and summary for the session's teacher only (LessonRecordingPolicy). The
 * panel's own login and session check guards them, because the Tuto-Mikro page is a panel page and uses the same session.
 * CSRF protection comes with the web group applied to this file; the browser sends the token as X-CSRF-TOKEN. SetLocale
 * (the panel's own middleware) makes the messages of the JSON answers follow the teacher's language.
 */
Route::middleware([AuthenticateSession::class, Authenticate::class, SetLocale::class])->controller(LessonRecordingController::class)->group(function (): void {
    // Own counters per route (the third argument): a segment every ~25 s is normal, a backlog after an outage drains within a minute.
    Route::post('/tuto-mikro/{lesson}/start', 'store')->middleware('throttle:10,1,recording-start')->name('recordings.store');
    // The Beamer display asks this every 3 s (MikroBoard): only "is a session of this lesson open", for the lesson's teacher.
    Route::get('/tuto-mikro/{lesson}/status', 'status')->middleware('throttle:60,1,recording-status')->name('recordings.status');
    Route::post('/tuto-mikro/sitzung/{recording}/abschnitt', 'segment')->middleware('throttle:60,1,recording-segment')->name('recordings.segment');
    Route::post('/tuto-mikro/sitzung/{recording}/pruefung', 'check')->middleware('throttle:30,1,recording-check')->name('recordings.check');
    Route::post('/tuto-mikro/sitzung/{recording}/ende', 'finish')->name('recordings.finish');
    Route::post('/tuto-mikro/sitzung/{recording}/zusammenfassung', 'analyse')->middleware('throttle:20,1,recording-analyse')->name('recordings.analyse');
});

/*
 * Consents to lesson recordings. The parents' page is public: the long random token in the URL is the only credential,
 * so a token of the wrong shape never matches a route (404) and unknown tokens get the same 404 from the controller.
 * Like the legal pages it skips the web group: no session means no sessions row with the full URL (and with it the plain
 * token), the IP address and the user agent, and no CSRF token is needed because forging a request already takes the link.
 * That holds for the application only: the web server's access log (serversideup image: NGINX_ACCESS_LOG, default
 * /dev/stdout, so the container log) still holds the request line with the link, the IP address and the user agent.
 * The "consent" limiter counts per link and IP, with a high ceiling per IP (AppServiceProvider).
 */
Route::middleware('throttle:consent')->withoutMiddleware('web')->prefix('/einwilligung/{token}')->where(['token' => '[A-Za-z0-9]{40,64}'])->controller(ConsentController::class)->group(function (): void {
    Route::get('/', 'show')->name('consents.show');
    Route::post('/', 'store')->name('consents.store');
    Route::post('/widerruf', 'revoke')->name('consents.revoke');
});

/*
 * The printable parent letters of a class. A POST because it makes new links and thereby invalidates the old ones.
 * Teachers of the class and the school administration only (RecordingConsentPolicy).
 */
Route::post('/einwilligungen/{schoolClass}/briefe', ConsentLetterController::class)
    ->middleware([AuthenticateSession::class, Authenticate::class])
    ->name('consents.letters');

/*
 * Connecting a native app (OAuth 2.0 authorization code with PKCE, see AppAuthorizationController). Laravel's `auth`
 * sends guests to the normal login and, through the intended URL, back to this exact request afterwards. The consent
 * page and the completion page are panel pages outside the panel's routes (AuthorizeApp), so they set the panel up
 * themselves; they may not be framed by another site, which could otherwise lure a click on "Erlauben".
 */
Route::middleware(['auth', AuthenticateSession::class, SetLocale::class, SetUpPanel::class.':lernplattform', DispatchServingFilamentEvent::class, FrameGuard::class])->group(function (): void {
    Route::get('/app/verbinden', AuthorizeApp::class)->name('app.authorize');
    Route::post('/app/verbinden', [AppAuthorizationController::class, 'store'])->name('app.authorize.store');
});
