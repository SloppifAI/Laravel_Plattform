<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Resources\Schools\Schemas\SchoolForm;
use App\Models\DpaAcceptance;
use App\Models\School;
use App\Models\User;
use App\Role;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * A new school registers itself, accepts the data processing agreement and gets its first administration account.
 * While the agreement is still a draft (DpaAcceptance::isFinal()) there is nothing to accept: the page says so and the
 * school accepts it under "Meine Schule" once it is released.
 *
 * Role and school of that account are fixed here and never read from input. The page builds on the student
 * registration for the username rules, the password fields and the rate limit, but needs no class code.
 */
class RegisterSchool extends Register
{
    /**
     * Fewer than for students: a school registers once, a class registers all at the same time.
     */
    protected const int MAX_ATTEMPTS_PER_MINUTE = 10;

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Deine Schule')
                    ->statePath('school')
                    ->schema(SchoolForm::fields()),
                Section::make('Erster Zugang der Schuladministration')
                    ->description('Damit meldest du dich an und legst danach Klassen und Lehrkräfte an.')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getUsernameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                Checkbox::make('isAuthorizedToRepresentSchool')
                    ->label('Ich bin berechtigt, die Schule zu vertreten.')
                    ->accepted()
                    ->dehydrated(false)
                    ->validationMessages(['accepted' => 'Bitte bestätige, dass du die Schule vertreten darfst.']),
                Checkbox::make('hasAcceptedPrivacyPolicy')
                    ->label(new HtmlString('Ich habe die <a href="'.e(route('legal.privacy')).'" target="_blank" rel="noopener" class="underline">Datenschutzerklärung</a> gelesen und akzeptiere sie.'))
                    ->accepted()
                    ->dehydrated(false)
                    ->validationMessages(['accepted' => 'Bitte akzeptiere die Datenschutzerklärung.']),
                DpaAcceptance::isFinal()
                    ? Checkbox::make('hasAcceptedDpa')
                        ->label(new HtmlString('Ich akzeptiere den <a href="'.e(route('legal.dpa')).'" target="_blank" rel="noopener" class="underline">Auftragsverarbeitungsvertrag (AVV)</a> im Namen der Schule.'))
                        ->accepted()
                        ->dehydrated(false)
                        ->validationMessages(['accepted' => 'Bitte akzeptiere den Auftragsverarbeitungsvertrag, sonst können wir die Daten deiner Schule nicht verarbeiten.'])
                    : Text::make(new HtmlString('Der <a href="'.e(route('legal.dpa')).'" target="_blank" rel="noopener" class="underline">Auftragsverarbeitungsvertrag (AVV)</a> ist noch ein Entwurf und kann deshalb noch nicht angenommen werden. Du kannst ihn schon lesen. Sobald er freigegeben ist, nimmst du ihn unter „Meine Schule“ an.'))
                        ->color('gray'),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->label('Dein Name')->autofocus(false);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('Deine E-Mail-Adresse')
            ->helperText('Damit kannst du dein Passwort selbst zurücksetzen.')
            ->required()
            ->validationMessages(['unique' => 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.']);
    }

    /**
     * The student registration limits repeated sign-ups per email, which would lock a school out
     * after two typos. The limit per IP address from rateLimit() is enough, because no email is sent.
     */
    protected function isRegisterRateLimited(string $email): bool
    {
        return false;
    }

    protected function beforeValidate(): void
    {
        $this->data['username'] = Str::lower(trim((string) ($this->data['username'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): User {
            $school = School::create($data['school']);

            $admin = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Admin,
                'school_id' => $school->getKey(),
            ]);

            // The box is required, so registering is accepting the data processing agreement (Art. 28 Abs. 9 DSGVO).
            if (DpaAcceptance::isFinal()) {
                $school->acceptDpa($admin);
            }

            return $admin;
        });
    }

    protected function afterRegister(): void
    {
        Notification::make()
            ->title('Willkommen auf der Lernplattform!')
            ->body('Lege jetzt Klassen an oder importiere eine Schülerliste.')
            ->success()
            ->send();
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()->label('Schule registrieren');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Schule registrieren';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Neue Schule registrieren';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('Deine Schule ist schon dabei? '.$this->loginAction->toHtml());
    }
}
