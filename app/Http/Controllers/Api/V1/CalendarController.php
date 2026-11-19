<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Services\Calendar\MonthCalendar;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/calendar` (§13.1): quante date cadono in ciascun giorno del mese.
 *
 * È lo stesso `MonthCalendar` del sito — una sola query aggregata per mese,
 * in cache mezz'ora (§11.8, §12.3) — quindi l'app e la pagina mostrano gli
 * stessi numeri e li pagano una volta sola.
 *
 * I giorni senza date **non compaiono**: una griglia di caselle vuote la
 * disegna il client, che sa quanto è larga la sua (§8.6).
 *
 * ## `personal=1`
 *
 * Con un token e `personal=1` il mese segue gli interessi di chi chiede, come
 * il calendario della pagina iniziale del sito: locali, organizzatori,
 * categorie e tag seguiti, categorie scelte, meno categorie e locali nascosti
 * (`MonthCalendar::personalDigest()`). Chi non ha espresso interessi riceve il
 * mese della città meno ciò che ha nascosto (`filteredDigest()`), e
 * `meta.interests` lo dice al client, che può spiegarlo. Senza token
 * `personal=1` non cambia nulla: `meta.personal` resta falso.
 *
 * La risposta personale non entra in nessuna cache condivisa:
 * `CacheJsonResponse` la segna `private, no-store` perché c'è un token, e
 * `MonthCalendar` non mette in cache il mese personale.
 */
final class CalendarController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly MonthCalendar $calendar) {}

    public function __invoke(CalendarRequest $request): JsonResponse
    {
        $city = $this->city();
        $month = $request->month($city->timezone);
        $request->validate(['personal' => ['nullable', 'boolean']]);

        $user = $request->boolean('personal') ? $this->currentUser($request) : null;
        $personal = $user === null ? null : $this->calendar->personalDigest($city, $month, $user);

        $digest = match (true) {
            $user === null => $this->calendar->digest($city, $month),
            $personal === null => $this->calendar->filteredDigest($city, $month, $user),
            default => $personal,
        };

        $days = [];

        foreach ($digest as $date => $day) {
            $days[] = [
                'business_date' => $date,
                'count' => $day['count'],
                'titles' => $day['titles'],
            ];
        }

        return ApiResponse::collection($days, [
            'month' => $month->format('Y-m'),
            'timezone' => $city->timezone,
            'total' => array_sum(array_column($days, 'count')),
            'personal' => $user !== null,
            'interests' => $personal !== null,
        ]);
    }
}
