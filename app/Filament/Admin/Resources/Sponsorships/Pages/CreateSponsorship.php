<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipWizard;
use App\Filament\Concerns\HasFeminineCreateTitle;
use App\Models\SponsorshipGrant;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Database\Eloquent\Model;

/**
 * Nuova sponsorizzazione: un percorso in quattro passi (`SponsorshipWizard`).
 *
 * Il modulo a schede di prima chiedeva l'evento da una tendina di soli titoli
 * e sparpagliava i campi obbligatori su tre schede: salvando dalla prima il
 * browser saltava a un'altra e diceva «Please fill out this field». Qui ogni
 * passo controlla i suoi campi prima di andare avanti.
 *
 * La modifica di una campagna esistente resta il modulo a schede della
 * risorsa: lì i campi sono già tutti compilati e serve vederli insieme.
 */
class CreateSponsorship extends CreateRecord
{
    use HasFeminineCreateTitle;
    use HasWizard;

    protected static string $resource = SponsorshipResource::class;

    protected static bool $canCreateAnother = false;

    /** @return array<mixed> */
    public function getSteps(): array
    {
        return SponsorshipWizard::steps();
    }

    /**
     * «Un evento» crea una campagna singola, «tutti gli eventi»
     * un'abilitazione automatica con le sue righe.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $id = auth()->id();

        return SponsorshipWizard::save($data, is_numeric($id) ? (int) $id : null);
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        return $record instanceof SponsorshipGrant
            ? SponsorshipGrantResource::getUrl('edit', ['record' => $record])
            : SponsorshipResource::getUrl('edit', ['record' => $record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        if ($record instanceof SponsorshipGrant) {
            $count = $record->sponsorships()->count();

            return trans_choice('sponsorships.wizard.created_grant', $count, ['count' => $count]);
        }

        return __('sponsorships.wizard.created');
    }
}
