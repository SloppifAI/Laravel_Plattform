<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GermanTranslationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
    }

    public function test_translates_required_message_with_the_given_attribute(): void
    {
        $message = __('validation.required', ['attribute' => 'Name']);

        $this->assertSame('Das Feld Name ist erforderlich.', $message);
    }

    public function test_validation_error_names_the_email_field_in_german(): void
    {
        $errors = Validator::make([], ['email' => 'required'])->errors();

        $this->assertSame('Das Feld E-Mail-Adresse ist erforderlich.', $errors->first('email'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function translationFiles(): array
    {
        return [
            'auth' => ['auth'],
            'pagination' => ['pagination'],
            'passwords' => ['passwords'],
            'validation' => ['validation'],
        ];
    }

    #[DataProvider('translationFiles')]
    public function test_german_file_contains_every_key_of_the_english_original(string $fileName): void
    {
        $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$fileName}.php");
        $german = require lang_path("de/{$fileName}.php");

        $missingKeys = array_filter(
            array_keys(Arr::dot($english)),
            fn (string $key): bool => ! Arr::has($german, $key),
        );

        $this->assertSame([], array_values($missingKeys));
    }
}
