<?php

namespace App\Filament\Pages;

use App\Support\StudentImport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * The interface to school software: student lists exported as CSV become classes and student accounts.
 * The page walks through three steps: upload, preview (nothing is saved yet) and result with the one-time passwords.
 *
 * @phpstan-import-type Outcome from StudentImport
 */
class ImportStudents extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Schülerliste importieren';

    protected static ?string $slug = 'schuelerliste-importieren';

    protected string $view = 'filament.pages.import-students';

    protected ?string $subheading = 'Übernimm Klassen und Schüler:innen aus der Schulverwaltung, zum Beispiel aus SchILD-NRW, WebUntis oder IServ.';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * What the preview or the import produced. Null while no file has been checked.
     *
     * @var Outcome|null
     */
    #[Locked]
    public ?array $outcome = null;

    /**
     * Whether $outcome is the result of a real import, as opposed to the preview.
     */
    #[Locked]
    public bool $imported = false;

    /**
     * Only a school administration imports, and only into its own school. Operators see no student records.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isAdmin() ?? false) && $user->school_id !== null;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('CSV-Datei')
                    ->helperText('Eine CSV- oder Textdatei bis 1 MB. Das Trennzeichen (Semikolon, Komma oder Tabulator) und die Zeichenkodierung erkennt die Plattform selbst.')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->maxSize(1024)
                    ->required()
                    // Only needed once to read the rows; it stays in Livewire's temporary folder until the import is done.
                    ->storeFiles(false),
            ])
            ->statePath('data');
    }

    public function createPreview(): void
    {
        abort_unless(static::canAccess(), 403);

        $outcome = app(StudentImport::class)->preview($this->uploadedFile()->get(), auth()->user()->school);

        if ($outcome['problem'] !== null) {
            Notification::make()
                ->danger()
                ->title('Die Datei lässt sich nicht importieren.')
                ->body($outcome['problem'])
                ->persistent()
                ->send();

            return;
        }

        $this->outcome = $outcome;
        $this->imported = false;
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label('Import ausführen')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Import ausführen?')
            ->modalDescription(fn (): string => sprintf(
                '%d Konten werden angelegt und %d aktualisiert, %d Zeilen werden übersprungen. Die Startpasswörter siehst du danach nur ein einziges Mal.',
                ...array_values($this->counts()),
            ))
            ->modalSubmitActionLabel('Ja, importieren')
            ->modalCancelActionLabel('Noch nicht')
            ->visible(fn (): bool => $this->outcome !== null && ! $this->imported && ($this->counts()['created'] + $this->counts()['updated']) > 0)
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                $file = $this->uploadedFile();

                try {
                    $outcome = app(StudentImport::class)->import($file->get(), auth()->user()->school);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title('Der Import hat nicht geklappt.')
                        ->body('Es wurde nichts gespeichert. Versuch es gleich noch einmal.')
                        ->send();

                    return;
                }

                $file->delete();
                $this->form->fill();

                $this->outcome = $outcome;
                $this->imported = true;
            });
    }

    /**
     * Gives the new accounts back as CSV. The passwords exist only in this page's state, never in the database.
     */
    public function downloadCredentials(): StreamedResponse
    {
        abort_unless(static::canAccess() && $this->imported, 403);

        $csv = app(StudentImport::class)->credentialsCsv($this->outcome['rows']);

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            'zugangsdaten-'.now()->format('Y-m-d-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function startOver(): void
    {
        $this->discardUpload();
        $this->form->fill();

        $this->outcome = null;
        $this->imported = false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->outcome['rows'] ?? [])
            ->columns([
                TextColumn::make('line')
                    ->label('Zeile')
                    ->visibleFrom('md'),
                TextColumn::make('name')
                    ->label('Name')
                    ->weight(FontWeight::Medium),
                TextColumn::make('class')
                    ->label('Klasse'),
                TextColumn::make('username')
                    ->label('Benutzername')
                    ->placeholder('–'),
                TextColumn::make('status')
                    ->label('Ergebnis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'created' => $this->imported ? 'Angelegt' : 'Wird angelegt',
                        'updated' => $this->imported ? 'Aktualisiert' : 'Wird aktualisiert',
                        default => 'Übersprungen',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        default => 'warning',
                    })
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'created' => Heroicon::OutlinedUserPlus,
                        'updated' => Heroicon::OutlinedArrowPath,
                        default => Heroicon::OutlinedExclamationTriangle,
                    }),
                TextColumn::make('message')
                    ->label('Hinweis')
                    ->wrap(),
                TextColumn::make('password')
                    ->label('Startpasswort')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('–')
                    ->visible(fn (): bool => $this->imported),
            ])
            ->paginated(false);
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    protected function counts(): array
    {
        $statuses = array_column($this->outcome['rows'] ?? [], 'status');

        return [
            'created' => count(array_keys($statuses, 'created', true)),
            'updated' => count(array_keys($statuses, 'updated', true)),
            'skipped' => count(array_keys($statuses, 'skipped', true)),
        ];
    }

    /**
     * @return array{counts: array{created: int, updated: int, skipped: int}, newClasses: list<string>}
     */
    protected function getViewData(): array
    {
        return [
            'counts' => $this->counts(),
            'newClasses' => $this->outcome['newClasses'] ?? [],
        ];
    }

    protected function uploadedFile(): TemporaryUploadedFile
    {
        $file = Arr::first(Arr::wrap($this->form->getState()['file']));

        // A client may submit a plain path string instead of an upload, which must never be read.
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        return $file;
    }

    protected function discardUpload(): void
    {
        foreach (Arr::wrap($this->data['file'] ?? []) as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }
    }
}
