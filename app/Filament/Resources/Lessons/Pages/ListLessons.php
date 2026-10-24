<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('classCodes')
                ->label('Klassencodes')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->modalHeading('Klassencodes')
                ->modalDescription('Mit dem Code registrieren sich Schüler:innen deiner Klasse. Schreib ihn für die Registrierung an die Tafel.')
                ->modalContent(fn (): View => view('filament.resources.lessons.class-codes', [
                    'schoolClasses' => $this->getSchoolClassesWithCodes(),
                    'registrationUrl' => filament()->getRegistrationUrl(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Schließen'),
            CreateAction::make()
                ->label('Stunde erfassen'),
        ];
    }

    /**
     * Admins see the classes of their school, teachers only the classes they lead (Klassenleitung), always within their school.
     *
     * @return Collection<int, SchoolClass>
     */
    private function getSchoolClassesWithCodes(): Collection
    {
        $user = auth()->user();

        return ($user->isAdmin() ? SchoolClass::query() : $user->ledClasses())
            ->where('school_id', $user->school_id)
            ->orderBy('name')
            ->get();
    }
}
