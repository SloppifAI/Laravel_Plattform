<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ClassConsents;
use App\Models\RecordingConsent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Makes the printable parent letters of a class. Every letter carries a fresh secret link (and its QR code); the plain
 * token exists only in this one response, the database keeps its hash. Making a letter invalidates the student's
 * previous link, so the page is only produced by a POST and never by following a link or reloading from a cache.
 *
 * Which students get a letter: `scope=new` (default) only those who never had one, so links in the parents' hands stay
 * valid; `scope=all` the whole class; `student` a single student, e.g. when a letter was lost.
 */
class ConsentLetterController extends Controller
{
    public function __invoke(Request $request, SchoolClass $schoolClass): Response|RedirectResponse
    {
        Gate::authorize('manageClass', [RecordingConsent::class, $schoolClass]);

        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['new', 'all'])],
            'student' => ['nullable', 'integer'],
        ]);

        $school = $schoolClass->school;
        $form = $school->currentConsentForm();

        if ($form === null) {
            return $this->back($schoolClass, 'Es gibt noch keine veröffentlichte Einwilligungserklärung.', 'Die Schuladministration veröffentlicht sie unter „Aufnahmen & Einwilligungen“.');
        }

        $students = $schoolClass->students()->withRole(Role::Student)->orderBy('name')->get();
        $consents = RecordingConsent::query()->whereIn('student_id', $students->modelKeys())->get()->keyBy('student_id');

        if (filled($data['student'] ?? null)) {
            $students = $students->where('id', (int) $data['student'])->values();

            abort_if($students->isEmpty(), 404);
        } elseif (($data['scope'] ?? 'new') === 'new') {
            $students = $students->filter(fn (User $student): bool => $consents->get($student->getKey())?->token_hash === null)->values();
        }

        if ($students->isEmpty()) {
            return $this->back($schoolClass, 'Alle Schüler:innen dieser Klasse haben schon einen Brief.', 'Wähle „Für die ganze Klasse neu erzeugen“, wenn du neue Briefe brauchst.');
        }

        $letters = DB::transaction(fn (): array => $students->map(function (User $student) use ($consents): array {
            $consent = $consents->get($student->getKey()) ?? RecordingConsent::forStudent($student);
            $url = route('consents.show', $consent->regenerateToken());

            return ['name' => RecordingConsent::firstName($student, withInitial: true), 'url' => $url, 'qr' => $this->qrCode($url)];
        })->all());

        return response()->view('filament.consents.letters', [
            'school' => $school,
            'schoolClass' => $schoolClass,
            'form' => $form,
            'letters' => $letters,
            'backUrl' => ClassConsents::getUrl(['klasse' => $schoolClass->getKey()]),
        ])->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * An inline SVG as data URI: the link never goes to an external QR service.
     */
    private function qrCode(string $url): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'drawLightModules' => false,
            'addQuietzone' => true,
        ])))->render($url);
    }

    private function back(SchoolClass $schoolClass, string $title, string $body): RedirectResponse
    {
        Notification::make()->warning()->title($title)->body($body)->persistent()->send();

        return redirect(ClassConsents::getUrl(['klasse' => $schoolClass->getKey()]));
    }
}
