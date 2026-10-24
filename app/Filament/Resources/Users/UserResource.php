<?php

namespace App\Filament\Resources\Users;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Role;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * School administrations manage the accounts of their own school. The platform operator manages only
 * administration accounts across schools and never sees teachers or students.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'benutzer';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->isOperator() ? NavigationGroup::Plattform : NavigationGroup::Verwaltung;
    }

    public static function getModelLabel(): string
    {
        return auth()->user()?->isOperator() ? __('Administrationskonto') : trans_choice('Benutzer|Benutzer', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return auth()->user()?->isOperator() ? __('Administrationskonten') : trans_choice('Benutzer|Benutzer', 2);
    }

    /**
     * The query is the school boundary: records of other schools do not exist for the signed-in user (404),
     * the policy then decides what may be done with the visible ones.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        if ($user?->isOperator()) {
            return $query->whereIn('role', [Role::Admin, Role::Operator]);
        }

        return $query->where('school_id', $user?->school_id)->whereNotNull('school_id');
    }

    /**
     * The roles the signed-in user may give to an account. Operators set up school administrations (and further
     * operators), school administrations run their own school and never create operators.
     *
     * @return list<Role>
     */
    public static function assignableRoles(): array
    {
        return auth()->user()?->isOperator()
            ? [Role::Admin, Role::Operator]
            : [Role::Admin, Role::Teacher, Role::Student];
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
