<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RecordSponsorshipMetric
{
    public function record(Sponsorship $campaign, string $metric, Request $request, string $channel): void
    {
        $input = $request->validate([
            'click_id' => 'nullable|uuid',
            'placement' => 'nullable|in:banner,home_hero,card',
            'page' => 'nullable|in:home,feed,event,venues,profile,search,other',
        ]);

        /* Il tetto globale per campagna e ora sta qui, nel punto in cui passano sia
           il sito sia l'API: due controlli in due controller sarebbero due posti
           dove dimenticarne uno. Chi lo supera non lo sa: il chiamante risponde
           204 come sempre, perché un segnale diverso direbbe a chi gonfia quando
           fermarsi e quando riprovare. Si conta dopo la deduplica dei clic:
           un clic ripetuto non conta, e non deve nemmeno consumare il tetto, o
           bastano poche decine di indirizzi che rimandano lo stesso clic per
           far sparire quelli veri del resto dell'ora. */
        DB::transaction(function () use ($campaign, $metric, $input, $channel): void {
            // Serialize counter/log writes per campaign, including duplicate delivery.
            $locked = Sponsorship::query()->lockForUpdate()->findOrFail($campaign->id);
            $key = null;
            if ($metric === 'clicks') {
                $key = hash('sha256', $channel.'|'.$campaign->id.'|'.($input['click_id'] ?? Str::uuid()));
                if (SponsorshipClick::where('request_key', $key)->exists()) {
                    return;
                }
            }
            if (! $this->withinHourlyCap($campaign, $metric, $channel)) {
                return;
            }
            if ($key !== null) {
                SponsorshipClick::create([
                    'sponsorship_id' => $campaign->id, 'request_key' => $key,
                    'channel' => $channel, 'placement' => $input['placement'] ?? $campaign->placement->value,
                    'page' => $input['page'] ?? 'other', 'clicked_at' => now('UTC'),
                ]);
            }
            $locked->increment($metric);
            SponsorshipDailyStat::registra($campaign->id, $metric, CarbonImmutable::now($campaign->city->timezone));
        });
    }

    /**
     * Se la campagna ha ancora spazio nell'ora in corso per questa misura.
     *
     * Il contatore è per campagna, misura e ora UTC, e non guarda l'indirizzo:
     * il tetto per indirizzo lo fanno i controller, prima. Così un indirizzo
     * consuma al massimo la propria quota del tetto globale, e per arrivare in
     * cima servono centinaia di indirizzi diversi — che è esattamente il caso
     * che il tetto per indirizzo non vede.
     *
     * Oltre il tetto si conta di meno, mai di più: le misure sono già una stima
     * al ribasso (D52/D53), e un'ora persa per eccesso di prudenza costa meno di
     * una fattura gonfiata. Un tetto a zero o negativo spegne il controllo.
     */
    private function withinHourlyCap(Sponsorship $campaign, string $metric, string $channel): bool
    {
        $tetto = (int) config('eventi.sponsorship_metric_hourly_cap.'.$metric, 0);

        if ($tetto <= 0) {
            return true;
        }

        $ora = CarbonImmutable::now('UTC');
        $chiave = 'sponsorship-metric-global:'.$campaign->getKey().':'.$metric.':'.$ora->format('YmdH');

        // Due ore di vita: l'ora corrente più un margine, poi la chiave si butta da sola.
        Cache::add($chiave, 0, 7200);
        $conteggio = (int) Cache::increment($chiave);

        if ($conteggio <= $tetto) {
            return true;
        }

        /* Una riga per campagna, misura e ora, non una per misura scartata: chi
           gonfia ne manderebbe migliaia, e il registro diventerebbe il secondo
           bersaglio. */
        if (Cache::add($chiave.':segnalata', true, 7200)) {
            $dettagli = [
                'sponsorship_id' => $campaign->getKey(),
                'misura' => $metric,
                'ora_utc' => $ora->format('Y-m-d H:00'),
                'tetto' => $tetto,
                'canale' => $channel,
            ];

            /* Senza `performedOn()`: la campagna non è nella mappa dei tipi
               polimorfici, e aggiungercela per una riga di registro toccherebbe
               tutto ciò che la legge. L'identificativo sta nelle proprietà. */
            activity('sicurezza')->withProperties($dettagli)
                ->event('sponsor_misure_oltre_tetto')->log('sponsor_misure_oltre_tetto');
            Log::warning('Misure sponsor oltre il tetto orario della campagna: le successive di quest\'ora sono scartate.', $dettagli);
        }

        return false;
    }
}
