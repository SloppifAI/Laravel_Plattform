<?php

namespace Tests\Feature\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorksheetFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_worksheet_upload_takes_exactly_the_formats_the_ai_reads_including_heic_photos(): void
    {
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(CreateLesson::class)
            ->assertFormFieldExists('attachments', fn (FileUpload $field): bool => $field->getAcceptedFileTypes() === ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf']
                && $field->getMimeTypeMap() === ['heic' => 'image/heic', 'heif' => 'image/heif']);
    }
}
