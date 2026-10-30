<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\SecurityLog;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> Gli eventi di sicurezza da scrivere dopo il salvataggio. */
    private array $cambiCredenziali = [];

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
     * prima, e si scrivono nel registro dopo il salvataggio.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $this->cambiCredenziali = [];

        if (array_key_exists('email', $data) && mb_strtolower(trim((string) $data['email'])) !== mb_strtolower((string) $record->getAttribute('email'))) {
            $this->cambiCredenziali[] = 'email_cambiata_da_staff';
        }
        if (filled($data['password'] ?? null)) {
            $this->cambiCredenziali[] = 'password_impostata_da_staff';
        }

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
        if ($this->cambiCredenziali === [] || ! $record instanceof User) {
            return;
        }

        $record->tokens()->delete();
        $staff = auth()->user();
        foreach ($this->cambiCredenziali as $evento) {
            SecurityLog::scrivi($evento, $record, causa: $staff instanceof User ? $staff : null);
        }
        $this->cambiCredenziali = [];
    }
}
