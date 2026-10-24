<?php

namespace Tests\Feature\I18n;

use App\Providers\TranslationServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationAreasTest extends TestCase
{
    private string $langPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->langPath = sys_get_temp_dir().'/tuto-lang-'.uniqid();
        File::ensureDirectoryExists($this->langPath.'/areas/test');
        File::put($this->langPath.'/areas/test/en.json', json_encode(['Hallo' => 'Hello']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->langPath);

        parent::tearDown();
    }

    /**
     * Boots the provider again, as if the area directory had been there from the start.
     */
    private function bootProviderWithTemporaryAreas(): void
    {
        $this->app->useLangPath($this->langPath);
        (new TranslationServiceProvider($this->app))->boot();
        $this->app->make('translator')->setLoaded([]);
    }

    public function test_an_area_directory_translates_german_source_strings_under_english_only(): void
    {
        $this->bootProviderWithTemporaryAreas();

        app()->setLocale('en');
        $this->assertSame('Hello', __('Hallo'));

        app()->setLocale('de');
        $this->assertSame('Hallo', __('Hallo'));
    }

    public function test_a_missing_translation_shows_the_german_key_with_the_english_fallback_locale(): void
    {
        config(['app.fallback_locale' => 'en']);
        $this->bootProviderWithTemporaryAreas();

        // JSON keys are read for the active locale only, so German never falls back to the English area text.
        app()->setLocale('de');
        $this->assertSame('Hallo', __('Hallo'));

        app()->setLocale('en');
        $this->assertSame('Das gibt es nicht', __('Das gibt es nicht'));
    }

    public function test_filament_strings_missing_in_its_german_files_fall_back_to_english_instead_of_showing_the_raw_key(): void
    {
        // The provider must leave the fallback locale alone (a local .env may still say APP_FALLBACK_LOCALE=de).
        app()->setFallbackLocale('en');
        (new TranslationServiceProvider($this->app))->boot();
        app()->setLocale('de');

        $this->assertSame('en', app()->getFallbackLocale());

        foreach (['filament-panels::global-search.loading_message', 'filament-tables::table.reordering.moved'] as $key) {
            $this->assertNotSame($key, __($key));
        }
    }

    public function test_the_shipped_core_area_translates_the_language_menu(): void
    {
        app()->setLocale('en');

        $this->assertSame('Language', __('Sprache'));
        $this->assertSame('Language: Deutsch', __('Sprache: :language', ['language' => 'Deutsch']));

        app()->setLocale('de');

        $this->assertSame('Sprache: English', __('Sprache: :language', ['language' => 'English']));
    }

    public function test_the_default_language_comes_from_the_platform_config_not_from_app_locale(): void
    {
        config(['lernplattform.locale.default' => 'de', 'app.locale' => 'en']);

        (new TranslationServiceProvider($this->app))->boot();

        $this->assertSame('de', app()->getLocale());
    }
}
