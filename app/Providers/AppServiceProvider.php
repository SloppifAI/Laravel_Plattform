<?php

namespace App\Providers;

use App\Ai\ClaudeLessonAssistant;
use App\Ai\DemoLessonAssistant;
use App\Ai\LessonAssistant;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LessonAssistant::class, fn (): LessonAssistant => config('services.anthropic.enabled')
            ? $this->app->make(ClaudeLessonAssistant::class)
            : $this->app->make(DemoLessonAssistant::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
