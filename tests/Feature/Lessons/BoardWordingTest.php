<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\ClassConsents;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ImportStudents;
use App\Filament\Pages\LessonStarter;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\MyRecordingConsent;
use App\Filament\Pages\RecordingSettings;
use App\Filament\Pages\RolesAndPermissions;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\User;
use Closure;
use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\View\FormsIconAlias;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Consents\CreatesConsentFixtures;
use Tests\TestCase;

/**
 * The glossary of the board: "freigeben" and "Stunde" instead of "verteilen", and for the Tuto-Mikro never
 * "aufnehmen", "Aufnahme", "Mitschnitt", "abhören", "überwachen" or "Transkript" (in English never "record",
 * "transcript", "monitor" or "listen in"); no microphone, ear or eye icons. The suite runs in English, so the pages are
 * rendered once per language and checked against the list of that language.
 */
class BoardWordingTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private const array FORBIDDEN_WORDS = [
        'en' => '/record|transcript|monitor|listen in|eavesdrop/iu',
        'de' => '/aufnahm|aufnehm|aufgenommen|mitschnitt|abhör|überwach|transkript|verteil/iu',
    ];

    /**
     * Source files of the pages above that must stay free of microphone, ear and eye icons.
     */
    private const array ICON_FREE_FILES = [
        'app/Filament/Pages/ClassConsents.php',
        'app/Filament/Pages/MyRecordingConsent.php',
        'app/Filament/Pages/RecordingSettings.php',
        'app/Filament/Pages/RolesAndPermissions.php',
        'app/Filament/Resources/Lessons/Pages/EditLesson.php',
        'resources/views/filament/pages/class-consents.blade.php',
        'resources/views/filament/pages/import-students.blade.php',
        'resources/views/filament/pages/lesson-starter.blade.php',
        'resources/views/filament/pages/my-learning-progress.blade.php',
        'resources/views/filament/pages/my-recording-consent.blade.php',
        'resources/views/filament/pages/recording-settings.blade.php',
    ];

    public function test_the_teacher_pages_use_the_board_wording(): void
    {
        $school = $this->schoolWithRecordings(requireStudentConsent: true);
        $form = $this->publishedForm($school);
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school, 2);
        $this->consentAll($students->take(1), $form);
        $draft = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->has(Question::factory())->create();
        $released = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create();
        $this->actingAs($teacher);

        $pages = [
            'Dashboard' => fn () => Livewire::test(Dashboard::class),
            'ListLessons' => fn () => Livewire::test(ListLessons::class),
            'EditLesson (draft)' => fn () => Livewire::test(EditLesson::class, ['record' => $draft->getRouteKey()]),
            'EditLesson (released)' => fn () => Livewire::test(EditLesson::class, ['record' => $released->getRouteKey()]),
            'LessonStarter' => fn () => Livewire::test(LessonStarter::class, ['lesson' => $released]),
            'ClassConsents' => fn () => Livewire::withQueryParams(['klasse' => $schoolClass->getKey()])->test(ClassConsents::class),
        ];

        $this->assertTheBoardWordingInBothLanguages($pages);
    }

    public function test_the_student_pages_use_the_board_wording(): void
    {
        $school = $this->schoolWithRecordings(requireStudentConsent: true);
        $this->publishedForm($school);
        [$schoolClass, $students] = $this->classWithStudents($school, 1);
        $student = $students->first();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['summary' => 'Die Zusammenfassung der Lehrkraft.']);
        Question::factory()->for($lesson)->create();
        $this->actingAs($student);

        $pages = [
            'Dashboard' => fn () => Livewire::test(Dashboard::class),
            'MyHomework' => fn () => Livewire::test(MyHomework::class),
            'WorkOnHomework' => fn () => Livewire::test(WorkOnHomework::class, ['lesson' => $lesson]),
            'MyLearningProgress' => fn () => Livewire::test(MyLearningProgress::class),
            'MyRecordingConsent' => fn () => Livewire::test(MyRecordingConsent::class),
        ];

        $this->assertTheBoardWordingInBothLanguages($pages);
    }

    public function test_the_administration_pages_use_the_board_wording(): void
    {
        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $this->assertTheBoardWordingInBothLanguages([
            'Dashboard' => fn () => Livewire::test(Dashboard::class),
            'RecordingSettings' => fn () => Livewire::test(RecordingSettings::class),
            'RolesAndPermissions' => fn () => Livewire::test(RolesAndPermissions::class),
            'ImportStudents' => fn () => Livewire::test(ImportStudents::class),
            'ListLessons' => fn () => Livewire::test(ListLessons::class),
        ]);
    }

    public function test_the_parents_page_and_the_letters_use_the_board_wording(): void
    {
        $this->withoutVite();
        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);
        [$schoolClass, , $teacher] = $this->classWithStudents($school, 1);

        $letters = $this->actingAs($teacher)->post(route('consents.letters', $schoolClass), ['scope' => 'all'])->assertOk();
        preg_match('#/einwilligung/([A-Za-z0-9]{48})#', $letters->getContent(), $link);
        $page = $this->get(route('consents.show', $link[1]))->assertOk();

        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS['de'], $this->visibleText($page->getContent()), 'public page');
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS['de'], $this->visibleText($letters->getContent()), 'letters');
        $page->assertSee('Tuto-Mikro');
    }

    public function test_no_microphone_ear_or_eye_icons_are_left_on_these_pages(): void
    {
        foreach (self::ICON_FREE_FILES as $file) {
            $this->assertDoesNotMatchRegularExpression('/Heroicon::\w*(Eye|Microphone|Ear)\b|heroicon-[a-z]-(eye|microphone|ear)/i', file_get_contents(base_path($file)), $file);
        }
    }

    public function test_the_dictation_button_of_the_students_has_no_microphone_icon(): void
    {
        $source = file_get_contents(base_path('resources/views/filament/lernplattform/speech.blade.php'));

        // The icon lives in a script, which visibleText() strips: check the raw source for the heroicons microphone path.
        $this->assertStringNotContainsString('M7 4a3 3 0 0 1 6 0v6a3 3 0 1 1-6 0V4Z', $source);
        $this->assertStringNotContainsString('M5.5 9.643a.75.75 0 0 0-1.5 0V10', $source);
    }

    public function test_filaments_own_eye_icons_are_replaced_by_neutral_ones(): void
    {
        foreach ([
            ActionsIconAlias::VIEW_ACTION,
            ActionsIconAlias::VIEW_ACTION_GROUPED,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD,
        ] as $alias) {
            $icon = FilamentIcon::resolve($alias);

            $this->assertInstanceOf(Heroicon::class, $icon, $alias);
            $this->assertStringNotContainsString('Eye', $icon->name, $alias);
        }

        // The password field of the login page no longer shows the eye of the heroicons set.
        $this->get('/login')->assertOk()->assertDontSee('M2.036 12.322a1.012', false);
    }

    /**
     * Renders every page with the English and with the German default and checks the visible text against the forbidden
     * words of that language.
     *
     * @param  array<string, Closure(): Testable>  $pages
     */
    private function assertTheBoardWordingInBothLanguages(array $pages): void
    {
        foreach (self::FORBIDDEN_WORDS as $locale => $forbidden) {
            config(['lernplattform.locale.default' => $locale]);
            app()->setLocale($locale);

            foreach ($pages as $name => $page) {
                $this->assertDoesNotMatchRegularExpression($forbidden, $this->visibleText($page()->html()), "{$name} ({$locale})");
            }
        }
    }

    /**
     * What a reader sees: no scripts or styles, no tags.
     */
    private function visibleText(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)));
    }
}
