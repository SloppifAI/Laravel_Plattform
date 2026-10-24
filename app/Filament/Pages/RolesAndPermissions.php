<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\ClassSubstitution;
use App\Models\School;
use App\Models\StudentQuestion;
use App\Role;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Makes the permission concept visible. The matrix describes app/Policies; tests/Feature/Admin/AdministrationAccessTest
 * and RolesAndPermissionsTest keep both in sync. What a Vertretung grants comes from ClassSubstitution::grants().
 */
class RolesAndPermissions extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * How far a role may go for one capability.
     *
     * @var array<string, array{label: string, color: string, icon: Heroicon}>
     */
    private const array ACCESS = [
        'all' => ['label' => 'Ja', 'color' => 'success', 'icon' => Heroicon::OutlinedCheckCircle],
        'school' => ['label' => 'Nur eigene Schule', 'color' => 'primary', 'icon' => Heroicon::OutlinedBuildingOffice2],
        'admins' => ['label' => 'Nur Administrationskonten', 'color' => 'primary', 'icon' => Heroicon::OutlinedKey],
        'own' => ['label' => 'Nur eigene', 'color' => 'primary', 'icon' => Heroicon::OutlinedUser],
        'class' => ['label' => 'Nur eigene Klassen', 'color' => 'primary', 'icon' => Heroicon::OutlinedUserGroup],
        'read' => ['label' => 'Nur ansehen', 'color' => 'info', 'icon' => Heroicon::OutlinedDocumentText],
        'none' => ['label' => 'Nein', 'color' => 'gray', 'icon' => Heroicon::OutlinedMinusCircle],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'rollen-und-rechte';

    public static function getNavigationLabel(): string
    {
        return __('Rollen & Rechte');
    }

    public function getTitle(): string
    {
        return __('Rollen & Rechte');
    }

    public function getSubheading(): string
    {
        return __('Wer bei Tuto was darf. Die Rechte hängen allein an der Rolle eines Kontos.');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->isOperator();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->isOperator() ? NavigationGroup::Plattform : NavigationGroup::Verwaltung;
    }

    /**
     * One row per capability, with the access level of every role keyed by the Role value.
     *
     * @return array<string, array{ability: string, detail: string, operator: string, admin: string, teacher: string, student: string}>
     */
    public static function matrix(): array
    {
        return [
            'schools' => [
                'ability' => __('Schulen verwalten'),
                'detail' => __('Schulen anlegen und sperren. Eine Schuladministration bearbeitet nur die Stammdaten der eigenen Schule.'),
                'operator' => 'all', 'admin' => 'school', 'teacher' => 'none', 'student' => 'none',
            ],
            'users' => [
                'ability' => __('Benutzer verwalten'),
                'detail' => __('Konten anlegen, Rollen vergeben, Passwörter neu setzen. Die Schuladministration verwaltet alle Konten der eigenen Schule, die Plattform-Administration nur die Administrationskonten der Schulen. Das eigene Konto kann niemand löschen.'),
                'operator' => 'admins', 'admin' => 'school', 'teacher' => 'none', 'student' => 'none',
            ],
            'classes' => [
                'ability' => __('Klassen verwalten'),
                'detail' => __('Klassen anlegen, Klassenleitung festlegen, weitere Lehrkräfte der Klasse zuordnen, Schüler:innen zuordnen, Schülerlisten importieren.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'none', 'student' => 'none',
            ],
            'competencies' => [
                'ability' => __('Kompetenzraster pflegen'),
                'detail' => __('Das Kompetenzraster gilt für alle Schulen. Nur die Plattform-Administration legt Kompetenzen an und bearbeitet sie, Schuladministrationen und Lehrkräfte schlagen sie nach.'),
                'operator' => 'all', 'admin' => 'read', 'teacher' => 'read', 'student' => 'none',
            ],
            'lessons' => [
                'ability' => __('Unterrichtsstunden erfassen und Hausaufgaben freigeben'),
                'detail' => __('Lehrkräfte bearbeiten nur ihre eigenen Stunden und legen sie nur in Klassen an, die sie unterrichten: als Klassenleitung, zugeordnet oder als Vertretung im Zeitraum. Freigeben geht ebenfalls nur dort. Die Schuladministration verwaltet alle Stunden der eigenen Schule. Die Plattform-Administration sieht keine Lerndaten.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'own', 'student' => 'none',
            ],
            'today' => [
                'ability' => __('Heute: Hausaufgaben prüfen und freigeben'),
                'detail' => __('Die Seite „Heute“ gibt es nur für Lehrkräfte. Sie zeigt die eigenen Stunden von heute und den letzten Schultagen. Freigeben geht auch hier nur in Klassen, die die Lehrkraft unterrichtet.'),
                'operator' => 'none', 'admin' => 'none', 'teacher' => 'own', 'student' => 'none',
            ],
            'results' => [
                'ability' => __('Ergebnisse, Abgaben und „Erklär’s mir“-Gespräche einsehen'),
                'detail' => __('Lehrkräfte sehen alles zu ihren eigenen Stunden und den Lernstand der Klassen, die sie unterrichten. Mit dem Ende einer Vertretung endet auch dieser Zugriff. Die Schuladministration sieht alles zu den Stunden der eigenen Schule, Schüler:innen nur ihre eigenen Abgaben und Gespräche. Gespräche zu einem frei gewählten Thema ohne Stunde bleiben bei der Schüler:in. Die Plattform-Administration sieht keine Lerndaten.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'own', 'student' => 'own',
            ],
            'classbook' => [
                'ability' => __('Klassenbuch führen'),
                'detail' => __('Ein Eintrag entsteht als Entwurf aus einer Stunde. Bearbeiten und bestätigen darf ihn die Lehrkraft der Stunde, solange sie die Klasse unterrichtet, und die Schuladministration. Bestätigte Einträge sind gesperrt, nur die Schuladministration öffnet sie wieder. Lesen und als CSV exportieren dürfen die Lehrkräfte der Klasse, auch eine Vertretung im Zeitraum, und die Schuladministration. Wer gefehlt hat, sehen nur die Lehrkräfte der Klasse und die Schuladministration. Schüler:innen sehen nie die Einträge und nie die Fehltage anderer, nur die eigenen (Datum, Fach, Klasse) in ihrer Datenauskunft unter „Meine Daten herunterladen“. Die Plattform-Administration sieht das Klassenbuch nie.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'class', 'student' => 'none',
            ],
            'substitutions' => [
                'ability' => __('Vertretung einrichten'),
                'detail' => __('Die Klassenleitung richtet für ihre Klasse eine Vertretung ein, die Schuladministration für jede Klasse der Schule. Vertreten kann jede Lehrkraft der Schule, die die Klasse nicht ohnehin unterrichtet. Laufende und geplante Vertretungen derselben Lehrkraft in einer Klasse dürfen zusammen höchstens :days Tage dauern. Im Zeitraum kann die Vertretung :grants. Danach endet das von selbst. Die Übergabe-Mappe der Klasse öffnen Klassenleitung, Schuladministration und die Vertretung, solange sie läuft.', [
                    'days' => ClassSubstitution::maxDays(),
                    'grants' => ClassSubstitution::grants(),
                ]),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'own', 'student' => 'none',
            ],
            'questions' => [
                'ability' => __('Rückfragen an die Lehrkraft'),
                'detail' => __('Schüler:innen stellen aus „Erklär’s mir“ oder von der Auswertung einer Hausaufgabe eine Rückfrage zu einer Stunde, die für sie freigegeben ist (höchstens :max offene je Stunde), und lesen die Antwort. Beantworten darf die Lehrkraft der Stunde, außer eine Vertretung, deren Zeitraum vorbei ist, und die Schuladministration. Stellen lässt sich eine Frage nur, solange die Lehrkraft der Stunde sie beantworten darf. Mitschüler:innen und die Plattform-Administration sehen keine Rückfragen.', ['max' => StudentQuestion::MAX_OPEN_PER_LESSON]),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'own', 'student' => 'own',
            ],
            'homework' => [
                'ability' => __('Hausaufgaben bearbeiten und abgeben'),
                'detail' => __('Nur freigegebene Hausaufgaben, die der Schüler:in zugewiesen wurden.'),
                'operator' => 'none', 'admin' => 'none', 'teacher' => 'none', 'student' => 'own',
            ],
            'explain' => [
                'ability' => __('„Erklär’s mir“ üben'),
                'detail' => __('Ein Thema der KI erklären, die gezielt nachfragt.'),
                'operator' => 'none', 'admin' => 'none', 'teacher' => 'none', 'student' => 'all',
            ],
            'levels' => [
                'ability' => __('Stufen und Einfache Sprache bei Hausaufgaben'),
                'detail' => __('Lehrkräfte schreiben zu den Fragen ihrer Stunden optional eine Stufe „Einstieg“ und „Profi“ oder lassen sie von der KI entwerfen. Schüler:innen wählen bei jeder Frage ihre Stufe, der Erwartungshorizont bleibt für alle gleich. „Einfache Sprache“ schreibt Zusammenfassung und Fragen in kurzen Sätzen um; ob sie an ist, merkt sich nur der eigene Browser.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'own', 'student' => 'own',
            ],
            'notifications' => [
                'ability' => __('Benachrichtigungen erhalten (Glocke)'),
                'detail' => __('Schüler:innen erfahren, wenn eine Hausaufgabe oder Förderaufgabe freigegeben wird und wenn eine Rückfrage beantwortet ist. Lehrkräfte erfahren von neuen Abgaben und neuen Rückfragen zu ihren Stunden, als Anzahl je Stunde. Die Meldungen nennen keine Namen, und jede Person sieht nur ihre eigenen. Gelesene Meldungen gehen nach :read Tagen, alle spätestens nach :all Tagen.', [
                    'read' => config('lernplattform.notifications.keep_read_days'),
                    'all' => config('lernplattform.notifications.keep_days'),
                ]),
                'operator' => 'none', 'admin' => 'none', 'teacher' => 'own', 'student' => 'own',
            ],
            'mikro' => [
                'ability' => __('Tuto-Mikro im Unterricht einschalten'),
                'detail' => __('Nur die Lehrkraft der Stunde, und nur in einer Klasse, die sie unterrichtet (Klassenleitung, zugeordnet oder als Vertretung heute). Es geht nur, wenn die Schule Tuto-Mikro freigeschaltet hat, die Eltern aller anwesenden Schüler:innen eingewilligt haben (und die Schüler:innen selbst, wo die Schule das verlangt) und das Fach nicht gesperrt ist. Förderaufgaben haben nie Tuto-Mikro. Die Beamer-Anzeige mit „Mikro an“ und „Mikro aus“ öffnet ebenfalls nur die Lehrkraft der Stunde. Die Schuladministration kann es nicht einschalten.'),
                'operator' => 'none', 'admin' => 'none', 'teacher' => 'own', 'student' => 'none',
            ],
            'consents' => [
                'ability' => __('Einwilligungen der Eltern erfassen'),
                'detail' => __('Briefe an die Eltern, Papiereinwilligungen und Widerrufe pflegt die Lehrkraft für die Klassen, die sie unterrichtet, die Schuladministration für alle Klassen der Schule. Schüler:innen bestätigen oder widerrufen nur ihr eigenes Einverständnis, wo die Schule es verlangt. Die Plattform-Administration sieht keine Einwilligungen.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'class', 'student' => 'own',
            ],
            'mikro_settings' => [
                'ability' => __('Tuto-Mikro für die Schule freischalten'),
                'detail' => __('Nur die Schuladministration der eigenen Schule: Checkliste bestätigen, die Einwilligungserklärung für die Eltern veröffentlichen, bei Bedarf die Zustimmung der Schüler:innen verlangen und Fächer sperren. Sensible Fächer (:subjects) bleiben gesperrt, bis die Schule eine eigene Liste festlegt. Es läuft nur mit dem angenommenen Vertrag zur Auftragsverarbeitung.', ['subjects' => implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS)]),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'none', 'student' => 'none',
            ],
            'ai' => [
                'ability' => __('KI-Funktionen der Schule einschalten'),
                'detail' => __('Texte einer Schule gehen nur dann an einen KI-Anbieter, wenn die Plattform die KI eingeschaltet hat und die Schuladministration die Checkliste ihrer Schule bestätigt und die KI für sie freigeschaltet hat. Sonst antwortet eine Demo-Logik, ohne dass etwas die Plattform verlässt. Lehrkräfte, Schüler:innen und die Plattform-Administration können das für eine Schule nicht einschalten.'),
                'operator' => 'none', 'admin' => 'school', 'teacher' => 'none', 'student' => 'none',
            ],
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema(array_map(
                        fn (Role $role): Section => Section::make($role->getLabel())
                            ->icon(match ($role) {
                                Role::Operator => Heroicon::OutlinedGlobeAlt,
                                Role::Admin => Heroicon::OutlinedKey,
                                Role::Teacher => Heroicon::OutlinedAcademicCap,
                                Role::Student => Heroicon::OutlinedBookOpen,
                            })
                            ->iconColor($role->getColor())
                            ->compact()
                            ->schema([Text::make($role->getDescription())]),
                        Role::cases(),
                    )),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Rechte-Matrix'))
            ->records(fn (): array => self::matrix())
            ->columns([
                TextColumn::make('ability')
                    ->label(__('Was?'))
                    ->description(fn (array $record): string => $record['detail'])
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                ...array_map(
                    fn (Role $role): TextColumn => TextColumn::make($role->value)
                        ->label($role->getLabel())
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __(self::ACCESS[$state]['label']))
                        ->color(fn (string $state): string => self::ACCESS[$state]['color'])
                        ->icon(fn (string $state): Heroicon => self::ACCESS[$state]['icon']),
                    Role::cases(),
                ),
            ])
            ->paginated(false);
    }
}
