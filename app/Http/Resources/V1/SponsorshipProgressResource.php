<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Sponsorship;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use Carbon\CarbonInterface;

/**
 * L'avanzamento di una campagna per le risposte di gestione dell'app:
 * `goal`, `delivered`, `pacing_state`.
 *
 * Il numero è quello dell'**unità di consegna**, non della riga: per le righe
 * di un'abilitazione obiettivo e consegnate sono quelli dell'abilitazione
 * (la somma delle sue righe, cestino compreso), perché è l'abilitazione che il
 * gestore ha comprato. Per una campagna singola sono quelli della riga.
 *
 * Per il prodotto a periodo `goal` e `pacing_state` sono `null`: si vende una
 * quota di spazio, non un numero, e lo stato del ritmo non ha senso.
 *
 * Il calcolo è uno solo per app, pannelli e rapporti
 * (`App\Services\Sponsorship\SponsorshipProgress`, D70): app e pannello non
 * possono dire due stati diversi della stessa campagna.
 *
 * Solo dati della campagna, mai della persona: chi chiama deve già aver
 * deciso che il gestore può vedere quella campagna.
 */
final class SponsorshipProgressResource
{
    /** @return array{goal: int|null, delivered: int, pacing_state: string|null} */
    public static function toArray(Sponsorship $campaign, ?CarbonInterface $now = null): array
    {
        return self::fields(app(SponsorshipProgress::class)->forRow($campaign, $now));
    }

    /**
     * L'avanzamento di più righe, calcolato una volta per unità: le righe di
     * una stessa abilitazione condividono il numero e non devono rifare le
     * stesse query.
     *
     * @param  iterable<Sponsorship>  $campaigns
     * @return array<int, array{goal: int|null, delivered: int, pacing_state: string|null}> per id della riga
     */
    public static function many(iterable $campaigns, ?CarbonInterface $now = null): array
    {
        $progress = app(SponsorshipProgress::class);
        $byUnit = [];
        $result = [];

        foreach ($campaigns as $campaign) {
            $unit = $campaign->unitKey();
            $byUnit[$unit] ??= self::fields($progress->forRow($campaign, $now));
            $result[(int) $campaign->getKey()] = $byUnit[$unit];
        }

        return $result;
    }

    /** @return array{goal: int|null, delivered: int, pacing_state: string|null} */
    private static function fields(UnitProgress $progress): array
    {
        return [
            'goal' => $progress->goal,
            'delivered' => max(0, $progress->delivered),
            'pacing_state' => $progress->hasGoal() ? $progress->state?->value : null,
        ];
    }
}
