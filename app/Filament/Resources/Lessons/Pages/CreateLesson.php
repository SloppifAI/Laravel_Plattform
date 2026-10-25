<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\SchoolClass;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateLesson extends CreateRecord
{
    protected static string $resource = LessonResource::class;

    public function getSubheading(): ?string
    {
        return LessonResource::teacherHasNoClass() ? LessonResource::noClassHint() : null;
    }

    /**
     * The lesson belongs to whoever enters it, admins included. The class select only offers the allowed classes; the
     * policy check is the backstop for a forged form state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Gate::authorize('createFor', [Lesson::class, SchoolClass::findOrFail($data['school_class_id'])]);

        return [...$data, 'teacher_id' => auth()->id()];
    }
}
