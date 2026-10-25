<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Lesson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The summary an app sets for a lesson the user may edit (LessonPolicy::update). A Förderaufgabe gets its summary from
 * its own draft, so an app cannot set it. TrimStrings trims the text, so a blank one fails "required".
 */
class UpdateLessonSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->lesson());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->lesson()->isPracticeTask()) {
                    $validator->errors()->add('summary', __('Die Zusammenfassung einer Förderaufgabe kann eine App nicht ändern.'));
                }
            },
        ];
    }

    private function lesson(): Lesson
    {
        return $this->route('lesson');
    }
}
