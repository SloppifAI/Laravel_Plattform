<?php

namespace App\Filament\Pages\Auth;

use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Self-registration for students: the class code from the teacher decides which class they join.
 *
 * Teachers and admins never register themselves, so the role is fixed here and never read from input.
 * The class code is checked before anything else, so only holders of a valid code learn which usernames are taken.
 */
class Register extends BaseRegister
{
    /**
     * Filament allows 2 attempts per minute and IP, but a whole class registers from one school IP at the same time.
     */
    protected const int MAX_ATTEMPTS_PER_MINUTE = 60;

    private const string UNKNOWN_CLASS_CODE_MESSAGE = 'Diesen Klassencode gibt es nicht. Frag deine Lehrkraft.';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getUsernameFormComponent(),
                $this->getClassCodeFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getEmailFormComponent(),
                Text::make('Lehrkräfte erhalten ihren Zugang von der Schulverwaltung.')->color('gray'),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->label('Name');
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Benutzername')
            ->helperText('So meldest du dich an: 3 bis 30 Zeichen, nur Kleinbuchstaben, Zahlen, Punkt, Minus und Unterstrich.')
            ->required()
            ->minLength(3)
            ->maxLength(30)
            ->regex('/^[a-z0-9._-]+$/')
            ->unique(User::class, 'username')
            ->autocomplete('username')
            ->validationMessages([
                'regex' => 'Erlaubt sind nur Kleinbuchstaben, Zahlen, Punkt, Minus und Unterstrich.',
                'unique' => 'Diesen Benutzernamen gibt es schon. Nimm einen anderen.',
            ]);
    }

    protected function getClassCodeFormComponent(): Component
    {
        return TextInput::make('classCode')
            ->label('Klassencode')
            ->helperText('Den Code zeigt dir deine Lehrkraft, zum Beispiel an der Tafel.')
            ->required()
            ->maxLength(20)
            ->autocomplete('off')
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->findSchoolClass($value) === null) {
                        $fail(self::UNKNOWN_CLASS_CODE_MESSAGE);
                    }
                },
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('E-Mail-Adresse (freiwillig)')
            ->helperText('Mit einer E-Mail-Adresse kannst du dein Passwort selbst zurücksetzen.')
            ->required(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Passwort')
            ->helperText('Mindestens 8 Zeichen.')
            ->autocomplete('new-password')
            ->validationMessages([
                'min' => 'Dein Passwort braucht mindestens 8 Zeichen.',
                'same' => 'Die beiden Passwörter müssen gleich sein.',
            ]);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label('Passwort wiederholen')
            ->autocomplete('new-password');
    }

    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        parent::rateLimit(static::MAX_ATTEMPTS_PER_MINUTE, $decaySeconds, $method ?? 'register', $component);
    }

    /**
     * Usernames are case-insensitive, so they are lowercased before the unique and format rules run.
     * Without a valid class code the other rules never run, because their "already taken" errors would confirm accounts to outsiders.
     */
    protected function beforeValidate(): void
    {
        $this->data['username'] = Str::lower(trim((string) ($this->data['username'] ?? '')));

        if ($this->findSchoolClass($this->data['classCode'] ?? null) === null) {
            throw ValidationException::withMessages([
                'data.classCode' => blank($this->data['classCode'] ?? null) ? 'Bitte gib den Klassencode ein.' : self::UNKNOWN_CLASS_CODE_MESSAGE,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $schoolClass = $this->findSchoolClass($data['classCode'])
            ?? throw ValidationException::withMessages(['data.classCode' => self::UNKNOWN_CLASS_CODE_MESSAGE]);

        return User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'password' => $data['password'],
            'role' => Role::Student,
            'school_id' => $schoolClass->school_id,
            'school_class_id' => $schoolClass->getKey(),
        ]);
    }

    /**
     * Ignores case, spaces and dashes, so "abc-def" and "ABC DEF" both find ABCDEF.
     * The class of a blocked school counts as unknown, so the answer says nothing about why.
     */
    private function findSchoolClass(mixed $code): ?SchoolClass
    {
        $code = Str::upper(preg_replace('/[\s-]+/', '', is_string($code) ? $code : ''));

        return $code === ''
            ? null
            : SchoolClass::query()
                ->where('join_code', $code)
                ->whereHas('school', fn (Builder $school): Builder => $school->whereNull('blocked_at'))
                ->first();
    }

    public function loginAction(): Action
    {
        return parent::loginAction()->label('Anmelden');
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()->label('Konto erstellen');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Registrieren';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Mit Klassencode registrieren';
    }

    public function registerSchoolAction(): Action
    {
        return Action::make('registerSchool')
            ->link()
            ->label('Schule registrieren')
            ->url(route('filament.lernplattform.auth.register-school'));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('Du hast schon ein Konto? '.$this->loginAction->toHtml().'<br>Neue Schule? '.$this->registerSchoolAction->toHtml());
    }
}
