<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Notifications\SponsorshipWeeklyReport;
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
 */
class SendSponsorshipReports extends Command
{
    protected $signature = 'sponsorships:report {--dry-run : Elenca i destinatari senza spedire}';

    protected $description = 'Manda ai committenti il riepilogo settimanale delle loro campagne';

    /** Quanti giorni deve aver girato una campagna prima del primo riepilogo. */
    private const GIORNI_MINIMI = 3;

    public function handle(): int
    {
        $a = CarbonImmutable::now()->subDay()->endOfDay();
        $da = $a->subDays(6)->startOfDay();

        $campagne = Sponsorship::query()
            ->with('event')
            ->where('status', SponsorshipStatus::Active)
            ->whereNotNull('advertiser_email')
            ->where('starts_at', '<=', CarbonImmutable::now()->subDays(self::GIORNI_MINIMI))
            ->where('ends_at', '>=', CarbonImmutable::now())
            ->get();

        if ($campagne->isEmpty()) {
            $this->info('Nessuna campagna a cui mandare un riepilogo.');

            return self::SUCCESS;
        }

        $spediti = 0;

        foreach ($campagne as $campagna) {
            $misure = SponsorshipDailyStat::query()
                ->where('sponsorship_id', $campagna->getKey())
                ->whereBetween('day', [$da->toDateString(), $a->toDateString()])
                ->selectRaw('COALESCE(SUM(impressions), 0) as viste, COALESCE(SUM(clicks), 0) as aperture')
                ->first();

            $viste = (int) ($misure?->getAttribute('viste') ?? 0);
            $aperture = (int) ($misure?->getAttribute('aperture') ?? 0);

            $indirizzo = $campagna->advertiser_email;

            if (! is_string($indirizzo) || $indirizzo === '') {
                continue;
            }

            $this->line(sprintf(
                '  %s → %s (%d viste, %d aperture)',
                $campagna->advertiser_name,
                $indirizzo,
                $viste,
                $aperture,
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
                ->notify(new SponsorshipWeeklyReport($campagna, $da, $a, $viste, $aperture));

            $spediti++;
        }

        $this->info($this->option('dry-run')
            ? count($campagne).' destinatari (nessun invio: --dry-run)'
            : $spediti.' riepiloghi spediti.');

        return self::SUCCESS;
    }
}
