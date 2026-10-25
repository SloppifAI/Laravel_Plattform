<?php

use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\SchoolClassController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Middleware\EnsureAppMayConnect;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
| The API for connected apps. An app gets its token through the browser (/app/verbinden, AppAuthorizationController)
| and POST v1/token; there is no password login. Every other route needs the token, the ability for the route
| (TokenController::ABILITIES) and an account that may still connect an app (EnsureAppMayConnect).
*/

Route::prefix('v1')->name('api.')->group(function (): void {
    Route::post('token', [TokenController::class, 'store'])->middleware('throttle:app-token')->name('token.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('token', [TokenController::class, 'destroy'])->name('token.destroy');

        Route::middleware(EnsureAppMayConnect::class)->group(function (): void {
            Route::middleware(CheckAbilities::class.':lessons:read')->group(function (): void {
                Route::get('classes', [SchoolClassController::class, 'index'])->name('classes.index');
                Route::get('lessons', [LessonController::class, 'index'])->name('lessons.index');
                Route::get('lessons/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
            });

            Route::post('lessons', [LessonController::class, 'store'])->middleware(CheckAbilities::class.':lessons:create')->name('lessons.store');
            Route::put('lessons/{lesson}/summary', [LessonController::class, 'updateSummary'])->middleware(CheckAbilities::class.':lessons:summary')->name('lessons.summary.update');
        });
    });
});
