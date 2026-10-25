<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lesson as a connected app sees it: what it needs to find the lesson and its summary, nothing about students.
 *
 * @mixin Lesson
 */
class LessonResource extends JsonResource
{
    /**
     * @return array{id: int, subject: string, topic: string, held_on: ?string, class: array{id: int, name: string}, summary: ?string, summary_from_app: bool, released: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'topic' => $this->topic,
            'held_on' => $this->held_on?->format('Y-m-d'),
            'class' => ['id' => $this->schoolClass->id, 'name' => $this->schoolClass->name],
            'summary' => $this->summary,
            // A lesson the app has just created has the column's default only in the database.
            'summary_from_app' => (bool) $this->summary_from_app,
            'released' => $this->isPublished(),
        ];
    }
}
