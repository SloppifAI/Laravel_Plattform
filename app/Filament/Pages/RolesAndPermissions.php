<?php

namespace App\Filament\Pages;

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
 * Makes the permission concept visible. The matrix describes app/Policies; tests/Feature/Admin/RolesAndPermissionsTest keeps both in sync.
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
        'own' => ['label' => 'Nur eigene', 'color' => 'primary', 'icon' => Heroicon::OutlinedUser],
        'read' => ['label' => 'Nur ansehen', 'color' => 'info', 'icon' => Heroicon::OutlinedEye],
        'none' => ['label' => 'Nein', 'color' => 'gray', 'icon' => Heroicon::OutlinedMinusCircle],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Rollen & Rechte';

    protected static ?string $slug = 'rollen-und-rechte';

    protected ?string $subheading = 'Wer auf der Lernplattform was darf. Die Rechte hängen allein an der Rolle eines Kontos.';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * One row per capability, with the access level of every role keyed by the Role value.
     *
     * @return array<string, array{ability: string, detail: string, admin: string, teacher: string, student: string}>
     */
    public static function matrix(): array
    {
        return [
            'users' => [
                'ability' => 'Benutzer verwalten',
                'detail' => 'Konten anlegen, Rollen vergeben, Passwörter neu setzen. Das eigene Konto kann niemand löschen.',
                'admin' => 'all', 'teacher' => 'none', 'student' => 'none',
            ],
            'classes' => [
                'ability' => 'Klassen verwalten',
                'detail' => 'Klassen anlegen, Klassenleitung festlegen, Schüler:innen zuordnen.',
                'admin' => 'all', 'teacher' => 'none', 'student' => 'none',
            ],
            'competencies' => [
                'ability' => 'Kompetenzraster pflegen',
                'detail' => 'Kompetenzen des Referenzrahmens anlegen und bearbeiten. Lehrkräfte schlagen sie nach.',
                'admin' => 'all', 'teacher' => 'read', 'student' => 'none',
            ],
            'lessons' => [
                'ability' => 'Unterrichtsstunden erfassen und Hausaufgaben verteilen',
                'detail' => 'Lehrkräfte bearbeiten nur ihre eigenen Stunden, die Administration alle.',
                'admin' => 'all', 'teacher' => 'own', 'student' => 'none',
            ],
            'results' => [
                'ability' => 'Ergebnisse, Abgaben und „Erklär’s mir“-Gespräche einsehen',
                'detail' => 'Lehrkräfte sehen alles zu ihren eigenen Stunden, Schüler:innen nur ihre eigenen Abgaben und Gespräche.',
                'admin' => 'all', 'teacher' => 'own', 'student' => 'own',
            ],
            'homework' => [
                'ability' => 'Hausaufgaben bearbeiten und abgeben',
                'detail' => 'Nur veröffentlichte Hausaufgaben, die der Schüler:in zugewiesen wurden.',
                'admin' => 'none', 'teacher' => 'none', 'student' => 'own',
            ],
            'explain' => [
                'ability' => '„Erklär’s mir“ üben',
                'detail' => 'Ein Thema der KI erklären, die gezielt nachfragt.',
                'admin' => 'none', 'teacher' => 'none', 'student' => 'all',
            ],
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema(array_map(
                        fn (Role $role): Section => Section::make($role->getLabel())
                            ->icon(match ($role) {
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
            ->heading('Rechte-Matrix')
            ->records(fn (): array => self::matrix())
            ->columns([
                TextColumn::make('ability')
                    ->label('Was?')
                    ->description(fn (array $record): string => $record['detail'])
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                ...array_map(
                    fn (Role $role): TextColumn => TextColumn::make($role->value)
                        ->label($role->getLabel())
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::ACCESS[$state]['label'])
                        ->color(fn (string $state): string => self::ACCESS[$state]['color'])
                        ->icon(fn (string $state): Heroicon => self::ACCESS[$state]['icon']),
                    Role::cases(),
                ),
            ])
            ->paginated(false);
    }
}
