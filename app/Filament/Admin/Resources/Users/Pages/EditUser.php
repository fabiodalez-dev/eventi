<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\Users\UpdateUserByStaff;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> Gli eventi di sicurezza da scrivere dopo il salvataggio. */
    private array $cambiCredenziali = [];

    /** L'email prima del salvataggio, per avvisarla se cambia. */
    private ?string $emailPrecedente = null;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Email e password cambiate dallo staff sono il modo di prendersi un
     * account altrui: si annotano qui, quando ancora si vede il valore di
     * prima, e si scrivono nel registro dopo il salvataggio. La regola sta in
     * `UpdateUserByStaff`, che usa anche l'app della redazione.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $this->cambiCredenziali = $record instanceof User ? UpdateUserByStaff::credentialChanges($record, $data) : [];
        $this->emailPrecedente = $record instanceof User ? (string) $record->email : null;

        return $data;
    }

    /**
     * Come dopo una reimpostazione della password (`PasswordReset` in
     * `AppServiceProvider`): le sessioni dell'app aperte con le credenziali
     * di prima non restano valide.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof User) {
            $staff = auth()->user();
            /* La propria email cambiata si riconferma, qualunque cosa dica il
               campo «Email verificata» (la regola è in `UpdateUserByStaff`). */
            if (UpdateUserByStaff::ownEmailChange($record, $this->cambiCredenziali, $staff instanceof User ? $staff : null)) {
                $record->forceFill(['email_verified_at' => null])->save();
            }
            UpdateUserByStaff::afterCredentialChanges($record, $this->cambiCredenziali, $staff instanceof User ? $staff : null, $this->emailPrecedente);
        }
        $this->cambiCredenziali = [];
        $this->emailPrecedente = null;
    }
}
