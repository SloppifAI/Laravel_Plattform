<?php

namespace Tests\Feature\I18n;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class MissingTranslationsTest extends TestCase
{
    /**
     * Matches __('…'), trans('…'), trans_choice('…') and @lang('…') with a literal first argument (single or double
     * quoted, escaped quotes allowed). Method calls (->trans), static calls (Foo::trans) and variables are skipped.
     * The literal has to be followed by a comma or the closing bracket, so a concatenation is not taken for a key.
     */
    private const TRANSLATION_CALL = <<<'REGEX'
~(?:(?<![\w$>:\\])(?:__|trans_choice|trans)|@lang)\s*\(\s*(?:\x27((?:[^\x27\\]|\\.)*)\x27|"((?:[^"\\]|\\.)*)")\s*(?=[,)])~s
REGEX;

    /**
     * Views that stay German on purpose: legal texts, the school's privacy documents and the parents' consent letters.
     *
     * @var list<string>
     */
    private const GERMAN_ONLY_PATHS = [
        'resources/views/filament/legal/',
        'resources/views/filament/pages/privacy-documents/',
        'resources/views/filament/consents/',
    ];

    public function test_every_translation_key_used_in_the_app_has_english(): void
    {
        $missing = $this->keysWithoutEnglish();

        $this->assertSame([], array_keys($missing), "Keys without English in lang/areas/*/en.json:\n".$this->describe($missing));
    }

    public function test_no_two_area_files_translate_the_same_german_key_differently(): void
    {
        $conflicts = $this->keysWithConflictingEnglish();

        $this->assertSame([], array_keys($conflicts), "German keys with different English in different files:\n".$this->describe($conflicts));
    }

    public function test_no_area_file_defines_the_same_german_key_twice(): void
    {
        $duplicates = $this->keysDefinedTwiceInOneFile();

        $this->assertSame([], array_keys($duplicates), "Keys defined twice in one file (the last one silently wins):\n".$this->describe($duplicates));
    }

    public function test_the_key_extraction_handles_quotes_choices_and_dynamic_calls(): void
    {
        $source = <<<'PHP'
            __('Es ist \'ganz\' einfach'), __("Sie sagte \"Hallo\""), trans('Zurück'), trans_choice(':count Tag|:count Tage', $n),
            @lang('Aus Blade'), {{ __('Mit Platzhalter :name', ['name' => $name]) }},
            __($dynamic), __('Teil eins'.$suffix), __("Mit $variable"), $this->trans('Methode'), Lang::trans('Statisch'),
            __(
                'Über mehrere Zeilen'
            )
            PHP;

        $this->assertSame([
            "Es ist 'ganz' einfach",
            'Sie sagte "Hallo"',
            'Zurück',
            ':count Tag|:count Tage',
            'Aus Blade',
            'Mit Platzhalter :name',
            'Über mehrere Zeilen',
        ], $this->literalKeys($source));

        $this->assertTrue($this->isPackageOrGroupKey('filament-panels::global-search.label'));
        $this->assertTrue($this->isPackageOrGroupKey('validation.required'));
        $this->assertFalse($this->isPackageOrGroupKey('D.M.YYYY'));
        $this->assertFalse($this->isPackageOrGroupKey('Das ist ein Satz. Noch einer.'));
        // Looks like a group key, but no PHP group file has it: it is a JSON key and needs English.
        $this->assertFalse($this->isPackageOrGroupKey('vorname.nachname'));
        $this->assertFalse($this->isPackageOrGroupKey('z.b.'));
    }

    public function test_a_blank_english_text_counts_as_missing(): void
    {
        $english = ['Fertig' => 'Done', 'Platzhalter' => '', 'Leerzeichen' => '  '];

        $this->assertTrue($this->hasEnglish($english, 'Fertig'));
        $this->assertFalse($this->hasEnglish($english, 'Platzhalter'));
        $this->assertFalse($this->hasEnglish($english, 'Leerzeichen'));
        $this->assertFalse($this->hasEnglish($english, 'Fehlt ganz'));
    }

    public function test_the_checks_report_conflicting_and_duplicate_keys_in_a_broken_lang_directory(): void
    {
        $langPath = sys_get_temp_dir().'/tuto-lang-'.uniqid();
        File::ensureDirectoryExists($langPath.'/areas/a');
        File::ensureDirectoryExists($langPath.'/areas/b');
        File::put($langPath.'/areas/a/en.json', "{\n    \"Hallo\": \"Hello\",\n    \"Tag\": \"Day\",\n    \"Tag\": \"Date\",\n    \"Gleich\": \"Same\"\n}");
        File::put($langPath.'/areas/b/en.json', '{"Hallo": "Hi", "Gleich": "Same"}');

        try {
            $this->app->useLangPath($langPath);

            $this->assertSame(['Hallo'], array_keys($this->keysWithConflictingEnglish()));
            $this->assertSame(['Tag'], array_keys($this->keysDefinedTwiceInOneFile()));
        } finally {
            File::deleteDirectory($langPath);
        }
    }

    /**
     * @return array<string, list<string>> German key => files that use it, for every key without English
     */
    private function keysWithoutEnglish(): array
    {
        $english = array_replace([], ...array_values($this->englishFiles()));
        $missing = [];

        foreach ($this->sourceFiles() as $path => $source) {
            foreach ($this->literalKeys($source) as $key) {
                if (! $this->hasEnglish($english, $key) && ! $this->isPackageOrGroupKey($key)) {
                    $missing[$key][$path] = $path;
                }
            }
        }

        ksort($missing);

        return array_map('array_values', $missing);
    }

    /**
     * @return array<string, list<string>> German key => one line per English text with the files that have it
     */
    private function keysWithConflictingEnglish(): array
    {
        $texts = [];

        foreach ($this->englishFiles() as $file => $translations) {
            foreach ($translations as $key => $english) {
                $texts[$key][$english][] = $file;
            }
        }

        $conflicts = [];

        foreach ($texts as $key => $files) {
            if (count($files) > 1) {
                foreach ($files as $english => $paths) {
                    $conflicts[$key][] = json_encode((string) $english, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).' in '.implode(', ', $paths);
                }
            }
        }

        return $conflicts;
    }

    /**
     * json_decode() silently keeps the last of two equal keys, so the keys are counted in the raw text (the files
     * are flat maps of German key to English text, one entry per line).
     *
     * @return array<string, list<string>> German key => files that define it more than once
     */
    private function keysDefinedTwiceInOneFile(): array
    {
        $duplicates = [];

        foreach ($this->englishFilePaths() as $path) {
            preg_match_all('/^\s*("(?:[^"\\\\]|\\\\.)*")\s*:/m', file_get_contents($path), $matches);

            $keys = array_map(fn (string $literal): string => json_decode($literal), $matches[1]);

            foreach (array_keys(array_filter(array_count_values($keys), fn (int $count): bool => $count > 1)) as $key) {
                $duplicates[$key][] = $this->relativePath($path);
            }
        }

        return $duplicates;
    }

    /**
     * @param  array<string, list<string>>  $problems
     */
    private function describe(array $problems): string
    {
        return collect($problems)
            ->map(fn (array $details, string $key): string => json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).' ('.implode('; ', $details).')')
            ->implode("\n");
    }

    /**
     * The literal keys of all translation calls in a piece of source code.
     *
     * @return list<string>
     */
    private function literalKeys(string $source): array
    {
        preg_match_all(self::TRANSLATION_CALL, $source, $matches, PREG_SET_ORDER);

        $keys = [];

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                // A double quoted string with a variable in it is not a fixed key.
                if (preg_match('/(?<!\\\\)\$/', $match[2]) !== 1) {
                    $keys[] = stripcslashes($match[2]);
                }

                continue;
            }

            $keys[] = preg_replace('/\\\\([\x27\\\\])/', '$1', $match[1]);
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $english
     */
    private function hasEnglish(array $english, string $key): bool
    {
        return trim((string) ($english[$key] ?? '')) !== '';
    }

    /**
     * Keys of Laravel and Filament packages (filament-panels::…) and PHP group keys (validation.required) do not
     * live in the JSON files. A dotted key only counts as a group key when a PHP group file really defines it, so
     * a German key like "vorname.nachname" still needs its English.
     */
    private function isPackageOrGroupKey(string $key): bool
    {
        if (str_contains($key, '::')) {
            return true;
        }

        [$group, $item] = array_pad(explode('.', $key, 2), 2, '');

        return $item !== '' && Arr::has(app('translator')->getLoader()->load('en', $group, '*'), $item);
    }

    /**
     * @return array<string, string> relative path => source code of every PHP and Blade file that is translated
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (Finder::create()->files()->in([app_path(), resource_path('views')])->name('*.php') as $file) {
            $path = $this->relativePath($file->getPathname());

            if (collect(self::GERMAN_ONLY_PATHS)->contains(fn (string $prefix): bool => str_starts_with($path, $prefix))) {
                continue;
            }

            $files[$path] = $file->getContents();
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function englishFilePaths(): array
    {
        return array_merge(glob(lang_path('areas/*/en.json')) ?: [], glob(lang_path('en.json')) ?: []);
    }

    /**
     * @return array<string, array<string, string>> relative path => German key => English text
     */
    private function englishFiles(): array
    {
        $files = [];

        foreach ($this->englishFilePaths() as $path) {
            $files[$this->relativePath($path)] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }

        return $files;
    }

    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', str_starts_with($path, base_path()) ? substr($path, strlen(base_path()) + 1) : $path);
    }
}
