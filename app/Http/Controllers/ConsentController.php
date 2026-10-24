<?php

namespace App\Http\Controllers;

use App\ConsentStatus;
use App\Models\RecordingConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;

/**
 * The public consent page the parents reach through the link of the letter: no login, the secret token in the URL is
 * the only credential. It shows the school, the child's first name and class and the current consent form, takes the
 * declaration and lets the parents revoke it on the same link. Nothing else about the student is exposed, and an
 * unknown, malformed or replaced token gets the same neutral 404.
 *
 * Granting and revoking work whatever the state of the school: the right to withdraw consent must never be blocked.
 *
 * The routes run without the web group (see routes/web.php): there is no session, so nothing about a visit is stored
 * next to the plain token, and no flash data either. Errors and the entered values are rendered in the response of the
 * failed POST, the confirmation after a success is the `ergebnis` query flag of the redirect.
 */
class ConsentController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return $this->page($this->consent($token), $token, $request->query('ergebnis'));
    }

    public function store(Request $request, string $token): Response|RedirectResponse
    {
        $consent = $this->consent($token);
        $form = $consent->school->currentConsentForm() ?? abort(404);

        $validator = Validator::make($request->all(), [
            'consent_form' => ['required', Rule::in([$form->getKey()])],
            'purposes' => ['required', 'array'],
            'purposes.*' => ['string', Rule::in($form->purposes)],
            'guardian_name' => ['required', 'string', 'min:2', 'max:120'],
            'is_guardian' => ['accepted'],
        ], [
            'consent_form.required' => 'Der Text der Einwilligung fehlt. Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.',
            'consent_form.in' => 'Der Text der Einwilligung wurde in der Zwischenzeit geändert. Bitte lesen Sie ihn noch einmal und bestätigen Sie dann erneut.',
            'purposes.required' => 'Bitte wählen Sie mindestens den Ton aus.',
            'purposes.array' => 'Bitte wählen Sie mindestens den Ton aus.',
            'purposes.*.in' => 'Diese Auswahl ist nicht möglich.',
            'purposes.*.string' => 'Diese Auswahl ist nicht möglich.',
            'guardian_name.required' => 'Bitte tragen Sie Ihren Namen ein.',
            'guardian_name.string' => 'Bitte tragen Sie Ihren Namen ein.',
            'guardian_name.min' => 'Bitte tragen Sie Ihren vollständigen Namen ein.',
            'guardian_name.max' => 'Der Name darf höchstens 120 Zeichen lang sein.',
            'is_guardian.accepted' => 'Bitte bestätigen Sie, dass Sie erziehungsberechtigt sind.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (! $validator->errors()->has('purposes') && ! in_array('audio', $request->input('purposes', []), true)) {
                $validator->errors()->add('purposes', 'Der Ton ist die Grundlage der anderen Zwecke. Ohne ihn ist keine Einwilligung möglich.');
            }
        });

        if ($validator->fails()) {
            $purposes = $request->input('purposes');
            $guardianName = $request->input('guardian_name');

            return $this->page($consent, $token, null, $validator->errors(), [
                'purposes' => is_array($purposes) ? array_values(array_filter($purposes, 'is_string')) : [],
                'guardian_name' => is_string($guardianName) ? $guardianName : '',
            ], 422);
        }

        $data = $validator->validated();

        $consent->grant($form, $data['purposes'], trim($data['guardian_name']));

        return redirect()->route('consents.show', ['token' => $token, 'ergebnis' => 'erteilt']);
    }

    public function revoke(string $token): RedirectResponse
    {
        $this->consent($token)->revoke();

        return redirect()->route('consents.show', ['token' => $token, 'ergebnis' => 'widerrufen']);
    }

    /**
     * The page for the current state of the consent.
     *
     * @param  array{purposes: list<string>, guardian_name: string}|null  $input  what the parents entered in a declaration that failed validation
     */
    private function page(RecordingConsent $consent, string $token, mixed $flag, ?MessageBag $errors = null, ?array $input = null, int $status = 200): Response
    {
        $school = $consent->school;
        $form = $school->currentConsentForm();
        $state = ConsentStatus::for($consent, $form, false);
        $student = $consent->student;

        // The flag only words the confirmation; it never claims a state the consent is not in.
        $result = match (true) {
            $flag === 'erteilt' && in_array($state, [ConsentStatus::Granted, ConsentStatus::Partial], true) => 'granted',
            $flag === 'widerrufen' && $state === ConsentStatus::Revoked => 'revoked',
            default => null,
        };

        return response(view('filament.consents.public', [
            'token' => $token,
            'school' => $school,
            'schoolClass' => $student->schoolClass,
            'firstName' => RecordingConsent::firstName($student),
            'consent' => $consent,
            'form' => $form,
            'status' => $state,
            'result' => $result,
            'errors' => (new ViewErrorBag)->put('default', $errors ?? new MessageBag),
            'input' => $input,
        ]), $status)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function consent(string $token): RecordingConsent
    {
        return RecordingConsent::findByToken($token) ?? abort(404);
    }
}
