<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\SavedSearch;
use App\Services\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;

/**
 * Rifà le ricerche salvate e accoda un avviso per le date nuove.
 *
 * «Nuovo» è ciò che è stato pubblicato — o aggiunto come data — da quando
 * questa ricerca è stata controllata l'ultima volta. La prima volta non manda
 * niente: segna soltanto da dove cominciare, altrimenti chi salva una ricerca
 * riceverebbe subito tutto il catalogo che già vedeva.
 *
 * Trova al massimo poche date per avviso: l'elenco completo sta dietro il
 * collegamento, e un avviso con venti titoli è un avviso che nessuno legge.
 */
final class SavedSearchMatcher
{
    public const MAX_ITEMS = 8;

    public function __construct(
        private readonly EventFinder $finder,
        private readonly NotificationScheduler $scheduler,
    ) {}

    /** @return int quante ricerche hanno prodotto un avviso */
    public function run(): int
    {
        $announced = 0;
        $now = CarbonImmutable::now();

        SavedSearch::query()->with(['city', 'user'])->orderBy('id')->chunkById(100, function ($searches) use (&$announced, $now): void {
            foreach ($searches as $search) {
                if ($search->user === null || $search->city === null) {
                    continue;
                }
                if ($this->check($search, $now)) {
                    $announced++;
                }
            }
        });

        return $announced;
    }

    public function check(SavedSearch $search, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $since = $search->last_checked_at;
        $search->forceFill(['last_checked_at' => $now])->save();

        if ($since === null) {
            return false;
        }

        $ids = $this->finder->query($search->city, $search->filters())
            ->excludingDemo()
            ->upcoming()
            ->publishedSince(CarbonImmutable::instance($since))
            ->take(self::MAX_ITEMS)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return false;
        }

        return $this->scheduler->announceSavedSearch($search, $ids);
    }
}
