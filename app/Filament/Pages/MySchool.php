<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Schools\Schemas\SchoolForm;
use App\Models\DpaAcceptance;
use App\Models\School;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The school administration looks after the master data of its own school.
 * The school is always the one of the signed-in user and never comes from the request.
 */
class MySchool extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Verwaltung;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'meine-schule';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Meine Schule');
    }

    public function getTitle(): string
    {
        return __('Meine Schule');
    }

    public function getSubheading(): string
    {
        return __('Die Stammdaten deiner Schule. Klassen und Konten verwaltest du unter „Klassen“ und „Benutzer“.');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->isAdmin()
            && $user->school !== null
            && $user->can('update', $user->school);
    }

    public function mount(): void
    {
        $this->form->fill($this->school()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(SchoolForm::fields())
            ->columns(2)
            ->model($this->school())
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        $statusSection = Section::make(__('Status'))
            ->schema([
                Text::make(__('Aktiv'))
                    ->badge()
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle),
                Text::make(fn (): string => __('Registriert am :date. Die Plattform-Administration kann eine Schule sperren; dann kann sich niemand mehr anmelden.', ['date' => $this->school()->created_at->format('d.m.Y')]))
                    ->color('gray'),
            ]);

        // A missing or outdated agreement moves to the top, where nobody can overlook it. A draft cannot be accepted yet, so it stays below.
        $agreementFirst = DpaAcceptance::isFinal() && ! $this->school()->hasAcceptedCurrentDpa();

        return $schema
            ->components([
                ...($agreementFirst ? [$this->dpaSection()] : []),
                $statusSection,
                ...($agreementFirst ? [] : [$this->dpaSection()]),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(__('Speichern'))
                                ->submit('save'),
                        ])->key('form-actions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $school = $this->school();

        Gate::authorize('update', $school);

        $school->update($this->form->getState());

        Notification::make()->title(__('Gespeichert'))->success()->send();
    }

    /**
     * Status of the data processing agreement (Art. 28 DSGVO) with the actions to read, print and accept it.
     * While the contract is a draft nothing can be accepted, so the page only says so and offers the text to read.
     */
    private function dpaSection(): Section
    {
        $version = (string) config('lernplattform.dpa.version');

        // The key is shared with a sentence that needs the lower-case English, so the heading capitalises it.
        return Section::make(ucfirst(__('Auftragsverarbeitungsvertrag (AVV)')))
            ->description(__('Der Vertrag regelt, wie die Plattform die Daten deiner Schule in deinem Auftrag verarbeitet.').$this->germanTextNote())
            ->schema([
                Text::make(fn (): string => match (true) {
                    $this->school()->hasAcceptedCurrentDpa() => __('Angenommen, Version :version', ['version' => $this->school()->dpa_version]),
                    ! DpaAcceptance::isFinal() => __('Entwurf, noch nicht annehmbar'),
                    $this->school()->hasAcceptedDpa() => __('Geänderte Fassung zur Annahme'),
                    default => __('Noch nicht angenommen'),
                })
                    ->badge()
                    ->color(fn (): string => match (true) {
                        $this->school()->hasAcceptedCurrentDpa() => 'success',
                        ! DpaAcceptance::isFinal() => 'gray',
                        $this->school()->hasAcceptedDpa() => 'warning',
                        default => 'danger',
                    })
                    ->icon(fn (): Heroicon => $this->school()->hasAcceptedCurrentDpa() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle),
                Text::make(fn (): string => $this->dpaStatusText())
                    ->color('gray'),
                // Reading comes first: nobody should accept a contract they were not shown.
                Actions::make([
                    Action::make('viewDpa')
                        ->label(__('Vertrag mit den Daten deiner Schule ansehen und drucken'))
                        ->icon(Heroicon::OutlinedPrinter)
                        ->color('gray')
                        ->url(fn (): string => route('legal.dpa.school'))
                        ->openUrlInNewTab(),
                    Action::make('viewAcceptedDpa')
                        ->label(fn (): string => __('Angenommene Fassung (Version :version) ansehen', ['version' => $this->school()->dpa_version]))
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->color('gray')
                        ->visible(fn (): bool => $this->school()->hasAcceptedDpa() && $this->school()->dpaAcceptances()->exists())
                        ->url(fn (): string => route('legal.dpa.school.accepted'))
                        ->openUrlInNewTab(),
                    Action::make('acceptDpa')
                        ->label(fn (): string => $this->school()->hasAcceptedDpa() ? __('Geänderte Fassung annehmen') : __('Vertrag annehmen'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (): bool => DpaAcceptance::isFinal() && ! $this->school()->hasAcceptedCurrentDpa())
                        ->requiresConfirmation()
                        ->modalHeading(__('Auftragsverarbeitungsvertrag annehmen'))
                        ->modalDescription(new HtmlString(e(__('Du nimmst den Vertrag in der Version :version im Namen deiner Schule an. Gespeichert werden die Version, der Zeitpunkt, der Vertragstext, wie du ihn siehst, und dein Name mit Benutzername.', ['version' => $version]).$this->germanTextNote()).' <a href="'.e(route('legal.dpa.school')).'" target="_blank" rel="noopener" class="underline">'.e(__('Vertrag zuerst lesen')).'</a>'))
                        ->modalSubmitActionLabel(__('Im Namen der Schule annehmen'))
                        ->action(function () use ($version): void {
                            $school = $this->school();

                            Gate::authorize('update', $school);

                            if (! DpaAcceptance::isFinal() || $school->hasAcceptedCurrentDpa()) {
                                return;
                            }

                            $school->acceptDpa(auth()->user());

                            Notification::make()
                                ->title(__('Vertrag angenommen'))
                                ->body(__('Version :version ist für deine Schule gespeichert.', ['version' => $version]))
                                ->success()
                                ->send();
                        }),
                ])->key('dpa-actions'),
            ]);
    }

    /**
     * The agreement is the binding German text whatever the language of the page, so everybody else is told before they accept it.
     */
    private function germanTextNote(): string
    {
        return app()->isLocale('de') ? '' : ' '.__('Der Vertrag selbst ist auf Deutsch.');
    }

    private function dpaStatusText(): string
    {
        $school = $this->school();
        $version = (string) config('lernplattform.dpa.version');
        $isFinal = DpaAcceptance::isFinal();

        if (! $school->hasAcceptedDpa()) {
            return $isFinal
                ? __('Deine Schule hat den Vertrag noch nicht angenommen. Ohne ihn fehlt die Grundlage dafür, dass die Plattform die Daten deiner Schule im Auftrag verarbeitet. Bis zur Annahme kann deine Schule nur diese Seite nutzen. Lies den Vertrag und nimm ihn im Namen der Schule an.')
                : __('Der Vertrag ist noch ein Entwurf. Er kann erst angenommen werden, wenn der Betreiber ihn freigegeben hat. Bis dahin kannst du ihn lesen.');
        }

        $accepted = __('Angenommen am :date um :time Uhr durch :name (Benutzername :username).', [
            'date' => $school->dpa_accepted_at->format('d.m.Y'),
            'time' => $school->dpa_accepted_at->format('H:i'),
            'name' => $school->dpa_accepted_by_name,
            'username' => $school->dpa_accepted_by_username,
        ]);

        if ($school->hasAcceptedCurrentDpa()) {
            return $accepted;
        }

        if (! $isFinal) {
            return $accepted.' '.__('Die neue Version :version ist noch ein Entwurf, bis zu ihrer Freigabe gilt Version :accepted weiter.', ['version' => $version, 'accepted' => $school->dpa_version]);
        }

        // The same version number with another fingerprint: a value the text takes from the settings changed (DpaAcceptance::terms()).
        $change = $school->dpa_version === $version
            ? __('Seitdem hat sich der Vertragstext geändert, zum Beispiel eine Frist oder der KI-Anbieter.')
            : __('Seitdem gibt es die neue Version :version.', ['version' => $version]);

        return $accepted.' '.$change.' '.__('Bis zur Annahme gilt die angenommene Fassung (Version :accepted) weiter. Lies die geänderte Fassung und nimm sie an.', ['accepted' => $school->dpa_version]);
    }

    private function school(): School
    {
        return auth()->user()->school ?? abort(403);
    }
}
