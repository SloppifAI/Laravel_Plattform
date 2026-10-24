<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the language: kept in the session for guests (login page) and stored on the account of a signed-in user.
 */
class SwitchLocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('lernplattform.locale.available')), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->update(['locale' => $locale]);

        // Path and query of the previous page, always on this host: leading slashes are dropped, so a foreign Referer
        // can never turn the target into a scheme-relative URL.
        $previous = parse_url(url()->previous()) ?: [];
        $query = isset($previous['query']) ? '?'.$previous['query'] : '';

        return redirect('/'.ltrim($previous['path'] ?? '', '/\\').$query);
    }
}
