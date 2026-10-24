<?php

namespace App\Filament\Pages;

use App\ConsentStatus;
use App\Filament\NavigationGroup;
use App\Models\RecordingConsent;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * "Tuto-Mikro im Unterricht" for students: where a school asks for it, the student confirms or withdraws their own
 * agreement once the parents have consented. The page only exists for students of schools that switched Tuto-Mikro on
 * and require this confirmation, and only ever touches the signed-in student's own record.
 */
class MyRecordingConsent extends Page
{
    protected string $view = 'filament.pages.my-recording-consent';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lernen;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Tuto-Mikro im Unterricht';

    protected static ?string $slug = 'tuto-mikro-im-unterricht';

    protected ?string $subheading = 'Manchmal schaltet deine Lehrkraft im Unterricht das Tuto-Mikro ein. Hier siehst du, ob das für dich gilt, und sagst selbst, ob du einverstanden bist.';

    public static function canAccess(): bool
    {
        $school = auth()->user()?->school;

        return (auth()->user()?->isStudent() ?? false) && $school !== null && $school->recordingsEnabled() && $school->require_student_consent;
    }

    public function confirm(): void
    {
        abort_unless(static::canAccess(), 403);

        $consent = $this->consent();

        if ($consent === null || ! in_array($this->parentStatus($consent), [ConsentStatus::Granted, ConsentStatus::Partial], true)) {
            Notification::make()->warning()->title('Deine Eltern müssen zuerst einwilligen')->body('Danach kannst du hier zustimmen.')->send();

            return;
        }

        Gate::authorize('confirmOwn', $consent);

        $consent->confirmByStudent();

        Notification::make()->success()->title('Danke, du bist einverstanden')->send();
    }

    public function withdraw(): void
    {
        abort_unless(static::canAccess(), 403);

        $consent = $this->consent();

        if ($consent !== null) {
            Gate::authorize('confirmOwn', $consent);

            $consent->withdrawByStudent();
        }

        Notification::make()->success()->title('Du bist nicht mehr einverstanden')->body('Solange du nicht zustimmst, bleibt das Tuto-Mikro in deiner Klasse aus.')->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $consent = $this->consent();
        $parentStatus = $consent === null ? ConsentStatus::Missing : $this->parentStatus($consent);

        return [
            'parentStatus' => $parentStatus,
            'parentsConsented' => in_array($parentStatus, [ConsentStatus::Granted, ConsentStatus::Partial], true),
            'confirmedAt' => $consent?->student_confirmed_at,
        ];
    }

    /**
     * The status of the parents' consent alone, without the student's own confirmation.
     */
    private function parentStatus(RecordingConsent $consent): ConsentStatus
    {
        return ConsentStatus::for($consent, $this->student()->school?->currentConsentForm(), false);
    }

    private function consent(): ?RecordingConsent
    {
        return RecordingConsent::query()->where('student_id', $this->student()->getKey())->first();
    }

    private function student(): User
    {
        return auth()->user() ?? abort(403);
    }
}
