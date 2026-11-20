<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\User;
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
 *
 * **Un'abilitazione scrive anche a chi gestisce.** Le sue righe di solito non
 * hanno un indirizzo del committente: il committente è il locale o
 * l'organizzazione, e il riepilogo va a chi lo gestisce, all'email del suo
 * account (D70). Locale: i membri che hanno accettato. Organizzazione: il
 * titolare e i membri. Solo account con email verificata e recapitabile, e
 * solo se il locale è approvato o l'organizzazione attiva. Se un gestore ha lo
 * stesso indirizzo scritto sulla riga, riceve un messaggio solo.
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
            ->with(['grant.venue', 'grant.organizer'])
            ->where('status', SponsorshipStatus::Active)
            ->where(fn ($query) => $query->whereNotNull('advertiser_email')->orWhereNotNull('sponsorship_grant_id'))
            ->where('starts_at', '<=', CarbonImmutable::now()->subDays(self::GIORNI_MINIMI))
            ->where('ends_at', '>=', CarbonImmutable::now())
            ->get();

        /* Una coppia per unità e destinatario: la chiave è l'indirizzo in
           minuscolo, così il gestore che è anche il committente scritto sulla
           riga non riceve due copie. */
        $coppie = [];
        $gestori = [];

        foreach ($campagne as $campagna) {
            foreach ($this->destinatari($campagna, $gestori) as $indirizzo => $destinatario) {
                $chiave = $campagna->unitKey().'|'.$indirizzo;
                $giaUtente = isset($coppie[$chiave]) && $coppie[$chiave]['a'] instanceof User;
                $coppie[$chiave] = [
                    'righe' => [...($coppie[$chiave]['righe'] ?? []), $campagna],
                    'a' => $giaUtente ? $coppie[$chiave]['a'] : $destinatario,
                ];
            }
        }

        if ($coppie === []) {
            $this->info('Nessuna campagna a cui mandare un riepilogo.');

            return self::SUCCESS;
        }

        $spediti = 0;

        foreach ($coppie as $coppia) {
            /* La riga piu' vista rappresenta l'unita': da lei vengono nome e
               l'evento del collegamento. */
            $campagna = collect($coppia['righe'])->sortByDesc('impressions')->first();
            $destinatario = $coppia['a'];

            if (! $campagna instanceof Sponsorship) {
                continue;
            }

            $indirizzo = $destinatario instanceof User ? (string) $destinatario->email : $destinatario;
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

            $riepilogo = new SponsorshipWeeklyReport($campagna, $da, $a, $viste, $aperture, $avanzamento);

            /*
             * Al gestore si scrive come utente. Al committente scritto sulla
             * riga con `route`: non e' un utente del sito e non ha un account,
             * ha solo un indirizzo, ed e' tutto cio' che serve.
             */
            if ($destinatario instanceof User) {
                $destinatario->notify($riepilogo);
            } else {
                Notification::route('mail', $indirizzo)->notify($riepilogo);
            }

            $spediti++;
        }

        $this->info($this->option('dry-run')
            ? count($coppie).' destinatari (nessun invio: --dry-run)'
            : $spediti.' riepiloghi spediti.');

        return self::SUCCESS;
    }

    /**
     * Chi riceve il riepilogo di una riga, per indirizzo in minuscolo: il
     * committente scritto sulla riga e, per un'abilitazione, chi gestisce il
     * locale o l'organizzazione.
     *
     * @param  array<int, list<User>>  $gestori  gestori già letti, per abilitazione
     * @return array<string, User|string>
     */
    private function destinatari(Sponsorship $campagna, array &$gestori): array
    {
        $destinatari = [];
        $scritto = $campagna->advertiser_email;

        if (is_string($scritto) && trim($scritto) !== '') {
            $destinatari[mb_strtolower(trim($scritto))] = trim($scritto);
        }

        $grant = $campagna->grant;

        if ($grant instanceof SponsorshipGrant && $grant->sponsorIsOperational()) {
            $gestori[$grant->id] ??= $this->gestori($grant);

            foreach ($gestori[$grant->id] as $utente) {
                $destinatari[mb_strtolower((string) $utente->email)] = $utente;
            }
        }

        return $destinatari;
    }

    /** @return list<User> */
    private function gestori(SponsorshipGrant $grant): array
    {
        $utenti = [];

        if ($grant->venue !== null) {
            $utenti = $grant->venue->members()->get()->all();
        } elseif ($grant->organizer !== null) {
            $utenti = $grant->organizer->users()->get()->all();

            if ($grant->organizer->owner !== null) {
                $utenti[] = $grant->organizer->owner;
            }
        }

        $scelti = [];

        foreach ($utenti as $utente) {
            if ($utente->canReceiveNotifications()) {
                $scelti[$utente->id] = $utente;
            }
        }

        return array_values($scelti);
    }
}
