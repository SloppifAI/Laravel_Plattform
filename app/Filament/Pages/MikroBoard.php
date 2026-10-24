<?php

namespace App\Filament\Pages;

use App\Models\Lesson;
use App\Models\LessonRecording;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * The Beamer display of Tuto-Mikro: a full-window page the lesson's teacher opens in a second window on the projector,
 * so the class sees whether the microphone is on ("Mikro an") or off ("Mikro aus"), like the light ring on a camera.
 * It shows the lesson's topic and nothing else: no names, no notes, no text of the session. The state comes from the
 * Tuto-Mikro tab of the same browser (BroadcastChannel) and, as a second source, from the status endpoint of
 * LessonRecordingController, which also covers a projector on another device (see the view).
 *
 * Only the lesson's teacher gets in (LessonRecordingPolicy), like for the Tuto-Mikro page itself.
 */
class MikroBoard extends Page
{
    protected static string $layout = 'filament.layouts.mikro-board';

    protected string $view = 'filament.pages.mikro-board';

    protected static ?string $title = 'Tuto-Mikro am Beamer';

    protected static ?string $slug = 'tuto-mikro/{lesson}/beamer';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'tuto-mikro-beamer';
    }

    /**
     * The BroadcastChannel the Tuto-Mikro page publishes its state on and this page listens to.
     */
    public static function channelName(Lesson $lesson): string
    {
        return 'tuto-mikro-'.$lesson->getKey();
    }

    public function mount(Lesson $lesson): void
    {
        Gate::authorize('create', [LessonRecording::class, $lesson]);
        abort_if($lesson->isPracticeTask(), 404);

        $this->lesson = $lesson;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // The lesson survives in the Livewire snapshot, so every render re-checks the rights.
        Gate::authorize('create', [LessonRecording::class, $this->lesson]);

        return [
            'topic' => $this->lesson->topic,
            'config' => [
                'channel' => self::channelName($this->lesson),
                'statusUrl' => route('recordings.status', $this->lesson),
            ],
        ];
    }
}
