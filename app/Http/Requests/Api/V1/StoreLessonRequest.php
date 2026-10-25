<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A lesson a connected app creates: the same fields as the lesson form, the notes optional (they fill in on the lesson
 * page). Whether the class is one the user may place lessons in is checked after validation (LessonController::store).
 */
class StoreLessonRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'school_class_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'topic' => ['required', 'string', 'max:255'],
            'held_on' => ['nullable', 'date_format:Y-m-d'],
            // The column is a TEXT (64 KB); four bytes per character still fit.
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
