<?php

namespace App\Providers;

use App\Ai\LessonAssistant;
use App\Ai\RecordingAnalyst;
use App\Ai\SchoolAwareLessonAssistant;
use App\Ai\SchoolAwareRecordingAnalyst;
use App\Ai\SchoolAwareTranscriber;
use App\Ai\Transcriber;
use Filament\Forms\Components\Select;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * How many words a table cell shows before it is cut off (the tooltip has the rest).
     */
    public const int TABLE_CELL_WORDS = 12;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Each call goes to the real AI (SDK) only for a school that switched it on, otherwise to the offline demo logic.
        $this->app->bind(LessonAssistant::class, SchoolAwareLessonAssistant::class);

        // Tuto-Mikro: sound segments and notes go to the real AI (SDK) only for a school that switched it on, otherwise the demo answers.
        $this->app->bind(Transcriber::class, SchoolAwareTranscriber::class);
        $this->app->bind(RecordingAnalyst::class, SchoolAwareRecordingAnalyst::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No table scrolls sideways on phones: below md every row becomes a card with labelled values (see "Tables stacked below md" in theme.css).
        Table::configureUsing(fn (Table $table): Table => $table->stackedOnMobile());

        // One select style everywhere: Filament's searchable JavaScript select instead of the browser's native one.
        Select::configureUsing(fn (Select $select): Select => $select->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $filter): SelectFilter => $filter->native(false));
        SelectColumn::configureUsing(fn (SelectColumn $column): SelectColumn => $column->native(false));

        // Long texts in table cells end after a few words with an ellipsis; the tooltip shows the whole text. HTML stays whole.
        TextColumn::configureUsing(fn (TextColumn $column): TextColumn => $column
            ->words(self::TABLE_CELL_WORDS)
            ->tooltip(fn (TextColumn $column, mixed $state): ?string => is_string($state) && ! $column->isHtml() && Str::words($state, self::TABLE_CELL_WORDS) !== $state ? $state : null));

        // The parents' consent page: a small limit per link and IP, and a generous ceiling per IP only against abuse, because a whole
        // parents' evening can sit behind one school Wi-Fi address and revoking has to stay as easy as consenting.
        RateLimiter::for('consent', fn (Request $request): array => [
            Limit::perMinute(30)->by('link:'.sha1((string) $request->route('token')).'|'.$request->ip()),
            Limit::perMinute(240)->by('ip:'.$request->ip()),
        ]);

        // Read aloud and dictation run in the pupil's browser only; teachers, administration and operators never get the script.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|string => auth()->user()?->isStudent() ? view('filament.lernplattform.speech') : '',
        );
    }
}
