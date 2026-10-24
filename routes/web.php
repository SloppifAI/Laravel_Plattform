<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\ConsentLetterController;
use App\Http\Controllers\LessonRecordingController;
use App\Http\Controllers\SchoolDpaController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
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
 * The same agreement with the master data of the administration's own school filled in. It is not a public page and
 * takes no school parameter: the school is always the one of the signed-in school administration.
 */
Route::middleware([AuthenticateSession::class, Authenticate::class])->group(function (): void {
    Route::get('/meine-schule/auftragsverarbeitung', SchoolDpaController::class)->name('legal.dpa.school');

    // The text the school accepted, kept as it was shown (DpaAcceptance), for as long as no newer version is accepted.
    Route::get('/meine-schule/auftragsverarbeitung/angenommen', [SchoolDpaController::class, 'accepted'])->name('legal.dpa.school.accepted');
});

/*
 * Lesson recordings: upload and playback for the recording's teacher only (LessonRecordingPolicy). The panel's own
 * login and session check guards them, because the recording page is a panel page and uses the same session.
 * CSRF protection comes with the web group applied to this file; the browser sends the token as X-CSRF-TOKEN.
 */
Route::middleware([AuthenticateSession::class, Authenticate::class])->controller(LessonRecordingController::class)->group(function (): void {
    // Own counters per route (the third argument): a chunk every ~20 s is normal, a backlog after an outage drains within seconds.
    Route::post('/aufnahme/{lesson}/start', 'store')->middleware('throttle:10,1,recording-start')->name('recordings.store');
    Route::post('/aufnahmen/{recording}/teil', 'append')->middleware('throttle:120,1,recording-chunk')->name('recordings.append');
    Route::post('/aufnahmen/{recording}/ende', 'finish')->name('recordings.finish');
    Route::put('/aufnahmen/{recording}/transkript', 'transcript')->name('recordings.transcript');
    Route::get('/aufnahmen/{recording}/audio', 'stream')->name('recordings.stream');
    Route::get('/aufnahmen/{recording}/download', 'download')->name('recordings.download');
});

/*
 * Consents to lesson recordings. The parents' page is public: the long random token in the URL is the only credential,
 * so a token of the wrong shape never matches a route (404) and unknown tokens get the same 404 from the controller.
 * Like the legal pages it skips the web group: no session means no sessions row with the full URL (and with it the plain
 * token), the IP address and the user agent, and no CSRF token is needed because forging a request already takes the link.
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
