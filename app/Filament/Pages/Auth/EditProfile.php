<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use SensitiveParameter;

/**
 * Own profile for every role. Username and role are shown but only the administration changes them,
 * and so is the name of students, so nobody can pose as a classmate or a teacher.
 */
class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'Mein Profil';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                TextInput::make('username')
                    ->label('Benutzername')
                    ->helperText('Damit meldest du dich an. Ändern kann ihn nur die Schulverwaltung.')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('role')
                    ->label('Rolle')
                    ->formatStateUsing(fn (): string => $this->getUser()->role->getLabel())
                    ->disabled()
                    ->dehydrated(false),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label('Name')
            ->helperText(fn (): ?string => $this->getUser()->isStudent() ? 'Ändern kann ihn nur die Schulverwaltung.' : null)
            ->disabled(fn (): bool => $this->getUser()->isStudent())
            ->dehydrated(fn (): bool => ! $this->getUser()->isStudent());
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('E-Mail-Adresse')
            ->helperText('Freiwillig. Mit einer E-Mail-Adresse kannst du dein Passwort selbst zurücksetzen.')
            ->required(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Neues Passwort')
            ->helperText('Mindestens 8 Zeichen. Leer lassen, wenn du dein Passwort behalten möchtest.')
            ->validationMessages([
                'min' => 'Dein Passwort braucht mindestens 8 Zeichen.',
                'same' => 'Die beiden Passwörter müssen gleich sein.',
            ]);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()->label('Neues Passwort wiederholen');
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->label('Aktuelles Passwort')
            ->belowContent('Zur Sicherheit brauchen wir dein aktuelles Passwort, wenn du Passwort oder E-Mail-Adresse änderst.');
    }

    /**
     * An emptied e-mail field must be stored as NULL, because the unique index would reject a second empty string.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $data['email'] = filled($data['email'] ?? null) ? $data['email'] : null;

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Gespeichert';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Speichern');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Abbrechen');
    }
}
