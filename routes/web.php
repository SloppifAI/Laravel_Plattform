<?php

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
