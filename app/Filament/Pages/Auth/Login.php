<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Login with a username instead of Filament's default email field.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->label('Benutzername')
                    ->required()
                    ->autocomplete('username')
                    ->autofocus(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{username: string, password: string}
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Willkommen zurück!';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Melde dich an und mach dort weiter, wo du aufgehört hast.';
    }
}
