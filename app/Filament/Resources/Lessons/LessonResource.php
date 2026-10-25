<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
use App\Filament\Resources\Lessons\Tables\LessonsTable;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'topic';

    public static function getModelLabel(): string
    {
        return __('Unterrichtsstunde');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Unterrichtsstunden');
    }

    public static function form(Schema $schema): Schema
    {
        return LessonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LessonsTable::configure($table);
    }

    /**
     * Whether the signed-in user is a teacher who teaches no class (yet): there is nowhere to hold a lesson, so nothing
     * should invite them to add one before the school administration has assigned them to a class.
     */
    public static function teacherHasNoClass(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->isTeacher() && ! SchoolClass::query()->taughtBy($user)->exists();
    }

    public static function noClassHint(): string
    {
        return __('Du leitest keine Klasse und bist keiner Klasse als Lehrkraft zugeordnet. Die Schuladministration kann dich einer Klasse zuordnen.');
    }

    /**
     * Teachers only ever see their own lessons, admins all lessons of their school. The policy alone does not filter records.
     *
     * @return Builder<Lesson>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->when(
                $user->isAdmin(),
                fn (Builder $query) => $query->whereRelation('schoolClass', 'school_id', $user->school_id),
                fn (Builder $query) => $query->whereBelongsTo($user, 'teacher'),
            );
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
            SubmissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
            'create' => CreateLesson::route('/create'),
            'edit' => EditLesson::route('/{record}/edit'),
        ];
    }
}
