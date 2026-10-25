<?php

namespace App\Filament\Pages\Auth;

use App\Support\PersonalDataExport;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Laravel\Sanctum\PersonalAccessToken;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Own profile for every role. Username and role are shown but only the administration changes them,
 * and so is the name of students, so nobody can pose as a classmate or a teacher.
 */
class EditProfile extends BaseEditProfile
{
    public function getTitle(): string|Htmlable
    {
        return __('Mein Profil');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                TextInput::make('username')
                    ->label(__('Benutzername'))
                    ->helperText(__('Damit meldest du dich an. Ändern kann ihn nur die Schulverwaltung.'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('role')
                    ->label(__('Rolle'))
                    ->formatStateUsing(fn (): string => $this->getUser()->role->getLabel())
                    ->disabled()
                    ->dehydrated(false),
                $this->getEmailFormComponent(),
                Select::make('locale')
                    ->label(__('Sprache'))
                    ->options(config('lernplattform.locale.available'))
                    ->formatStateUsing(fn (): string => app()->getLocale())
                    ->selectablePlaceholder(false)
                    ->required(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getConnectedAppsContentComponent(),
            ]);
    }

    /**
     * "Verbundene Apps": the tokens this account gave apps by confirming on /app/verbinden (AppAuthorizationController),
     * each with a way to revoke it. Only teachers and school administrations can connect an app, so nobody else sees
     * the section.
     */
    protected function getConnectedAppsContentComponent(): Component
    {
        return Section::make(__('Verbundene Apps'))
            ->description(__('Apps, denen du in Tuto den Zugang erlaubt hast. Eine App sieht die Stunden und Klassen, die du in Tuto bearbeiten darfst, und deinen Stundenplan, legt neue Stunden an und setzt die Zusammenfassung einer Stunde. Von Schüler:innen sieht sie nur die Namen, damit sie sie auf deinem Gerät pseudonymisieren kann.'))
            ->visible(fn (): bool => $this->getUser()->isTeacher() || $this->getUser()->isAdmin())
            ->schema(fn (): array => $this->getUser()->tokens()->latest()->latest('id')->get()
                ->map(fn (PersonalAccessToken $token): Component => TextEntry::make('connectedApp'.$token->getKey())
                    ->label($token->name)
                    ->state(collect([
                        __('Verbunden am :date', ['date' => $token->created_at->isoFormat(__('D.M.YYYY'))]),
                        $token->last_used_at ? __('zuletzt genutzt am :date', ['date' => $token->last_used_at->isoFormat(__('D.M.YYYY'))]) : __('noch nicht genutzt'),
                        $token->expires_at ? __('gültig bis :date', ['date' => $token->expires_at->isoFormat(__('D.M.YYYY'))]) : null,
                    ])->filter()->implode(' · '))
                    ->afterContent($this->revokeTokenAction($token)))
                ->whenEmpty(fn () => collect([Text::make(__('Noch keine App verbunden.'))]))
                ->all());
    }

    /**
     * One action per token, so the confirmation can name the app; it deletes only this account's token of that id.
     */
    protected function revokeTokenAction(PersonalAccessToken $token): Action
    {
        return Action::make('revokeToken'.$token->getKey())
            ->label(__('Zugang entziehen'))
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Zugang entziehen?'))
            ->modalDescription(__('Die App „:name“ kann danach nichts mehr in Tuto ansehen oder ändern, bis du sie neu verbindest.', ['name' => $token->name]))
            ->modalSubmitActionLabel(__('Zugang entziehen'))
            ->action(function () use ($token): void {
                $this->getUser()->tokens()->whereKey($token->getKey())->delete();

                Notification::make()->success()->title(__('Zugang entzogen'))->send();
            });
    }

    /**
     * A new language is only visible after a reload: the page that just saved it was rendered in the old one.
     */
    protected function getRedirectUrl(): ?string
    {
        return $this->getUser()->wasChanged('locale') ? static::getUrl() : null;
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label(__('Name'))
            ->helperText(fn (): ?string => $this->getUser()->isStudent() ? __('Ändern kann ihn nur die Schulverwaltung.') : null)
            ->disabled(fn (): bool => $this->getUser()->isStudent())
            ->dehydrated(fn (): bool => ! $this->getUser()->isStudent());
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label(__('E-Mail-Adresse'))
            ->helperText(__('Freiwillig. Mit einer E-Mail-Adresse kannst du dein Passwort selbst zurücksetzen.'))
            ->required(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label(__('Neues Passwort'))
            ->helperText(__('Mindestens 8 Zeichen. Leer lassen, wenn du dein Passwort behalten möchtest.'))
            ->validationMessages([
                'min' => __('Dein Passwort braucht mindestens 8 Zeichen.'),
                'same' => __('Die beiden Passwörter müssen gleich sein.'),
            ]);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()->label(__('Neues Passwort wiederholen'));
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->label(__('Aktuelles Passwort'))
            ->belowContent(__('Zur Sicherheit brauchen wir dein aktuelles Passwort, wenn du Passwort oder E-Mail-Adresse änderst.'));
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

    /**
     * Auskunft nach Art. 15 DSGVO: everybody can download what the platform stores about them, and only about them.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadData')
                ->label(__('Meine Daten herunterladen'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->tooltip(__('Alle Daten, die zu deinem Konto gespeichert sind, als Datei (JSON)'))
                ->action(function (): StreamedResponse {
                    $user = $this->getUser();
                    $json = json_encode(PersonalDataExport::for($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $filename = 'lernplattform-daten-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $user->username).'-'.today()->toDateString().'.json';

                    return response()->streamDownload(function () use ($json): void {
                        echo $json;
                    }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Gespeichert');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('Speichern'));
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label(__('Abbrechen'));
    }
}
