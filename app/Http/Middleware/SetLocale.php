<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the language of the request: the signed-in user's own choice, else the one picked on the login page (session),
 * else the platform default (config lernplattform.locale.default). The panel registers it as persistent, so Livewire
 * requests of an open page speak the same language.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;

        $locale = $request->user()?->preferredLocale();

        if ($locale === null && is_string($sessionLocale) && array_key_exists($sessionLocale, config('lernplattform.locale.available'))) {
            $locale = $sessionLocale;
        }

        app()->setLocale($locale ?? config('lernplattform.locale.default'));

        return $next($request);
    }
}
