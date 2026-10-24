<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Every directory under lang/areas is a JSON translation path of its own (lang/areas/<area>/en.json), so each area
     * of the UI brings its translations without anybody editing one shared file. lang/en.json still wins over them.
     * The fallback locale stays 'en': JSON keys are only read for the active locale (a missing one shows the German
     * key itself), the fallback only fills in the PHP files of packages whose German files are incomplete (Filament).
     */
    public function boot(): void
    {
        $loader = $this->app->make('translation.loader');

        foreach (glob(lang_path('areas/*'), GLOB_ONLYDIR) ?: [] as $areaPath) {
            $loader->addJsonPath($areaPath);
        }

        // Outside a request (console, queue) and until SetLocale runs, the platform default applies, not APP_LOCALE.
        $this->app->setLocale(config('lernplattform.locale.default'));
    }
}
