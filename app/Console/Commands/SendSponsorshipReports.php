<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Notifications\SponsorshipWeeklyReport;
use App\Services\Sponsorship\SponsorshipProgress;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Manda a ogni committente il riepilogo della settimana appena chiusa.
 *
 * **Solo a chi ha una campagna in corso e un indirizzo.** Non si scrive a chi
 * ha finito — quel messaggio non dice niente di nuovo e arriva quando non c'e'
 * piu' niente da fare — e non si scrive a una bozza, che non e' ancora un
 * cliente.
 *
 * **Nemmeno alle campagne appena partite.** Una che e' cominciata l'altro
 * ieri produrrebbe un riepilogo di due giorni: sembra una misura e invece e'
 * rumore, e la prima impressione che si porta dietro e' «questa cosa conta
 * poco». Meglio saltare il primo giro e mandare qualcosa che si possa leggere.
 *
 * **Un messaggio per unità e indirizzo.** L'unità è ciò che si è comprato:
 * un'abilitazione con tutti i suoi eventi, oppure la campagna singola. Le
 * righe di un'abilitazione con lo stesso indirizzo diventano un messaggio
 * solo, con i numeri sommati e l'avanzamento dell'abilitazione (obiettivo,
 * consegnate, stato): quaranta eventi non sono quaranta email.
 */
class SendSponsorshipReports extends Command
{
    protected $signature = 'sponsorships:report {--dry-run : Elenca i destinatari senza spedire}';

    protected $description = 'Manda ai committenti il riepilogo settimanale delle loro campagne';

    /** Quanti giorni deve aver girato una campagna prima del primo riepilogo. */
    private const GIORNI_MINIMI = 3;

    public function handle(SponsorshipProgress $avanzamenti): int
    {
        $a = CarbonImmutable::now()->subDay()->endOfDay();
        $da = $a->subDays(6)->startOfDay();

        $campagne = Sponsorship::query()
            ->with(['event', 'grant'])
            ->where('status', SponsorshipStatus::Active)
            ->whereNotNull('advertiser_email')
            ->where('starts_at', '<=', CarbonImmutable::now()->subDays(self::GIORNI_MINIMI))
            ->where('ends_at', '>=', CarbonImmutable::now())
            ->get()
            ->filter(fn (Sponsorship $campagna): bool => is_string($campagna->advertiser_email) && $campagna->advertiser_email !== '');

        if ($campagne->isEmpty()) {
            $this->info('Nessuna campagna a cui mandare un riepilogo.');

            return self::SUCCESS;
        }

        $gruppi = $campagne->groupBy(fn (Sponsorship $campagna): string => $campagna->unitKey().'|'.mb_strtolower((string) $campagna->advertiser_email));
        $spediti = 0;

        foreach ($gruppi as $righe) {
            /* La riga piu' vista rappresenta l'unita': da lei vengono nome,
               indirizzo e l'evento del collegamento. */
            $campagna = $righe->sortByDesc('impressions')->first();

            if (! $campagna instanceof Sponsorship) {
                continue;
            }

            $indirizzo = (string) $campagna->advertiser_email;
            $ids = $campagna->sponsorship_grant_id !== null
                ? Sponsorship::withTrashed()->where('sponsorship_grant_id', $campagna->sponsorship_grant_id)->select('id')
                : Sponsorship::withTrashed()->whereKey($campagna->getKey())->select('id');

            $misure = SponsorshipDailyStat::query()
                ->whereIn('sponsorship_id', $ids)
                ->whereBetween('day', [$da->toDateString(), $a->toDateString()])
                ->selectRaw('COALESCE(SUM(impressions), 0) as viste, COALESCE(SUM(clicks), 0) as aperture')
                ->first();

            $viste = (int) ($misure?->getAttribute('viste') ?? 0);
            $aperture = (int) ($misure?->getAttribute('aperture') ?? 0);
            $avanzamento = $avanzamenti->forRow($campagna);

            $this->line(sprintf(
                '  %s → %s (%d viste, %d aperture%s)',
                $campagna->advertiser_name,
                $indirizzo,
                $viste,
                $aperture,
                $avanzamento->goal === null ? '' : sprintf(', %d di %d, %s', $avanzamento->delivered, $avanzamento->goal, mb_strtolower($avanzamento->badge())),
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            /*
             * `route` e non `to`: il committente non e' un utente del sito e
             * non ha un account. Ha solo un indirizzo, ed e' tutto cio' che
             * serve per mandargli un messaggio.
             */
            Notification::route('mail', $indirizzo)
                ->notify(new SponsorshipWeeklyReport($campagna, $da, $a, $viste, $aperture, $avanzamento));

            $spediti++;
        }

        $this->info($this->option('dry-run')
            ? count($gruppi).' destinatari (nessun invio: --dry-run)'
            : $spediti.' riepiloghi spediti.');

        return self::SUCCESS;
    }
}
