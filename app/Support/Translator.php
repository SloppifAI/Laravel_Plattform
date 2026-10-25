<?php

namespace App\Support;

use Illuminate\Translation\Translator as BaseTranslator;

/**
 * Laravel picks the fallback locale for trans_choice() when the active locale has no line for the key. German has no
 * JSON files, because the keys are the German texts, so every German plural came out in English. German and English
 * share their plural rules, and get() still fills in missing PHP lines from the fallback, so the active locale is kept.
 */
class Translator extends BaseTranslator
{
    /**
     * @param  string  $key
     * @param  string|null  $locale
     */
    protected function localeForChoice($key, $locale): string
    {
        return $locale ?: $this->locale;
    }
}
