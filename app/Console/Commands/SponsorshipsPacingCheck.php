<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Enums\SponsorshipStatus;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Support\PanelDate;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Notifications\SponsorshipPacingAlert;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Ogni mattina dice alla redazione quali sponsorizzazioni a visualizzazioni
 * sono «in ritardo» o «a rischio» (`PacingState::needsAttention()`).
 *
 * **Solo quelle in corso e a visualizzazioni.** Un prodotto a periodo non ha
 * un ritmo da tenere; una campagna chiusa sotto l'obiettivo la dice il
 * rapporto, e ripeterla ogni mattina per sempre non aiuta nessuno.
 *
 * **Una volta al giorno e non di più.** Prima di scrivere a una persona
 * guardo se ha già ricevuto l'avviso di oggi (giorno della redazione, ora di
 * Roma): se il cron gira due volte, o qualcuno lo lancia a mano, non arriva
 * un secondo avviso. Il controllo sta nel database e non in cache, perché
 * la cache su file si svuota con un deploy.
 *
 * Avvisa chi può gestire le sponsorizzazioni (`sponsorships.manage`), cioè
 * amministratori e superamministratori: non il moderatore né il referente.
 */
class SponsorshipsPacingCheck extends Command
{
    protected $signature = 'sponsorships:pacing-check {--dry-run : Elenca le sponsorizzazioni da guardare senza avvisare nessuno}';

    protected $description = 'Avvisa la redazione delle sponsorizzazioni a visualizzazioni in ritardo o a rischio';

    public function handle(SponsorshipProgress $avanzamenti): int
    {
        $adesso = CarbonImmutable::now('UTC');
        $daGuardare = $this->daGuardare($avanzamenti, $adesso);

        if ($daGuardare === []) {
            $this->info(__('sponsorship-panel.pacing_check.none'));

            return self::SUCCESS;
        }

        foreach ($daGuardare as $unita) {
            $this->line('  '.__('sponsorship-panel.pacing_check.unit', $unita));
        }

        if ($this->option('dry-run')) {
            $this->info(__('sponsorship-panel.pacing_check.dry_run'));

            return self::SUCCESS;
        }

        $inizioGiornata = $adesso->setTimezone(PanelDate::defaultTimezone())->startOfDay()->utc();
        $avviso = new SponsorshipPacingAlert($daGuardare, SponsorshipResource::getUrl('index', panel: 'admin'));
        $inviati = 0;

        foreach ($this->redazione() as $persona) {
            $giaAvvisata = $persona->notifications()
                ->where('type', SponsorshipPacingAlert::class)
                ->where('created_at', '>=', $inizioGiornata)
                ->exists();

            if ($giaAvvisata) {
                $this->line('  '.__('sponsorship-panel.pacing_check.already', ['name' => $persona->name]));

                continue;
            }

            $persona->notify($avviso);
            $inviati++;
        }

        $this->info(trans_choice('sponsorship-panel.pacing_check.sent', $inviati, ['count' => $inviati]));

        return self::SUCCESS;
    }

    /**
     * Le unità a visualizzazioni in corso che chiedono attenzione, con il
     * nome che la redazione riconosce: il committente per un'abilitazione,
     * l'evento per una campagna singola.
     *
     * @return list<array{name: string, state: string}>
     */
    private function daGuardare(SponsorshipProgress $avanzamenti, CarbonImmutable $adesso): array
    {
        $abilitazioni = SponsorshipGrant::query()->active($adesso)->whereNotNull('impressions_goal')->with(['venue', 'organizer'])->get();
        $campagne = Sponsorship::query()->with('event')
            ->whereNull('sponsorship_grant_id')
            ->whereNotNull('impressions_goal')
            ->where('status', SponsorshipStatus::Active)
            ->where('starts_at', '<=', $adesso)
            ->where('ends_at', '>', $adesso)
            ->get();

        $elenco = [];

        foreach ($avanzamenti->grants($abilitazioni, $adesso) as $id => $avanzamento) {
            $abilitazione = $abilitazioni->find($id);

            if ($abilitazione instanceof SponsorshipGrant && self::chiedeAttenzione($avanzamento)) {
                $elenco[] = self::voce($abilitazione->sponsorName().' · '.$abilitazione->placement->label(), $avanzamento);
            }
        }

        foreach ($avanzamenti->campaigns($campagne, $adesso) as $id => $avanzamento) {
            $campagna = $campagne->find($id);

            if ($campagna instanceof Sponsorship && self::chiedeAttenzione($avanzamento)) {
                $elenco[] = self::voce(($campagna->event->title ?? $campagna->advertiser_name).' · '.$campagna->placement->label(), $avanzamento);
            }
        }

        return $elenco;
    }

    private static function chiedeAttenzione(UnitProgress $avanzamento): bool
    {
        return $avanzamento->state !== null && $avanzamento->state->needsAttention();
    }

    /** @return array{name: string, state: string} */
    private static function voce(string $nome, UnitProgress $avanzamento): array
    {
        return ['name' => $nome, 'state' => mb_strtolower($avanzamento->badge())];
    }

    /** @return Collection<int, User> */
    private function redazione(): Collection
    {
        try {
            return User::permission(Permission::ManageSponsorships->value)->get();
        } catch (PermissionDoesNotExist) {
            /* Ruoli e permessi non ancora creati (un'installazione nuova):
               non c'è nessuno a cui scrivere. */
            return new Collection;
        }
    }
}
