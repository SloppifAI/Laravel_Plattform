<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Filament\Resources\Lessons\Schemas\LessonForm;
use App\Filament\Resources\Lessons\Tables\LessonsTable;
use App\Models\Lesson;
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

    protected static string|UnitEnum|null $navigationGroup = 'Unterricht';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Unterrichtsstunde';

    protected static ?string $pluralModelLabel = 'Unterrichtsstunden';

    protected static ?string $recordTitleAttribute = 'topic';

    public static function form(Schema $schema): Schema
    {
        return LessonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LessonsTable::configure($table);
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
