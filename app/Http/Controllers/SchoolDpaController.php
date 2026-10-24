<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The data processing agreement with the master data of the signed-in administration's own school filled in.
 * The school is never read from the request, so one school can never open another school's version.
 */
class SchoolDpaController extends Controller
{
    /**
     * The current text.
     */
    public function __invoke(Request $request): View
    {
        return view('filament.legal.avv', ['school' => $this->school($request)]);
    }

    /**
     * The text of the school's latest acceptance, exactly as the school saw it. It stays in force until the school
     * accepts a newer version, so it has to stay readable, however the current text has changed since.
     */
    public function accepted(Request $request): View
    {
        $school = $this->school($request);

        return view('filament.legal.avv', [
            'school' => $school,
            'acceptance' => $school->dpaAcceptances()->first() ?? abort(404),
        ]);
    }

    private function school(Request $request): School
    {
        $school = $request->user()->school ?? abort(403);

        Gate::authorize('update', $school);

        return $school;
    }
}
