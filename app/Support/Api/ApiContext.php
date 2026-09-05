<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\ApiInclude;
use App\Enums\FollowableType;
use App\Models\City;
use App\Models\SavedEvent;
use App\Models\User;

/**
 * Ciò che una risposta dell'API deve sapere oltre ai dati: il fuso in cui
 * scrivere le date, le relazioni chieste con `include` e quali occorrenze
 * l'utente autenticato ha salvato.
 *
 * **`is_saved` è assente quando il chiamante non è autenticato, non `false`**
 * (§15.8): un client che leggesse `false` lo mostrerebbe come "non salvato",
 * mentre la verità è che il server non lo sa. La distinzione vive qui:
 * `savedOccurrenceIds` nullo significa "nessuno ha chiesto", array vuoto
 * significa "nessuno salvato".
 */
final readonly class ApiContext
{
    /**
     * @param  list<ApiInclude>  $includes
     * @param  array<int, true>|null  $savedOccurrenceIds
     */
    private function __construct(
        public City $city,
        public string $timezone,
        public array $includes = [],
        public ?array $savedOccurrenceIds = null,
        /** @var array<string, array<int, true>>|null */
        public ?array $followedIds = null,
    ) {}

    /**
     * Il contesto di una richiesta che non elenca occorrenze.
     *
     * @param  list<ApiInclude>  $includes
     */
    public static function for(City $city, array $includes = []): self
    {
        return new self($city, $city->timezone, $includes);
    }

    /**
     * Lo stesso contesto, con i salvataggi delle occorrenze elencate. Una
     * sola interrogazione per l'intera pagina: chiederlo card per card
     * significherebbe cinquanta letture per una lista.
     *
     * @param  list<ApiInclude>  $includes
     * @param  list<int>  $occurrenceIds
     */
    public static function forOccurrences(City $city, array $includes, ?User $user, array $occurrenceIds): self
    {
        if ($user === null) {
            return new self($city, $city->timezone, $includes);
        }

        $saved = [];

        if ($occurrenceIds !== []) {
            /** @var list<int> $rows */
            $rows = SavedEvent::query()
                ->where('user_id', $user->getKey())
                ->whereIn('occurrence_id', $occurrenceIds)
                ->pluck('occurrence_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($rows as $id) {
                $saved[$id] = true;
            }
        }

        $followed = [];

        foreach (FollowableType::cases() as $type) {
            $followed[$type->value] = [];

            foreach ($user->followedIds($type) as $id) {
                $followed[$type->value][$id] = true;
            }
        }

        return new self($city, $city->timezone, $includes, $saved, $followed);
    }

    public function wants(ApiInclude $include): bool
    {
        return in_array($include, $this->includes, true);
    }

    /**
     * `null` quando il chiamante non è autenticato: il campo non va scritto
     * affatto, e questa è la sola forma in cui l'assenza si propaga.
     */
    public function isSaved(int $occurrenceId): ?bool
    {
        if ($this->savedOccurrenceIds === null) {
            return null;
        }

        return isset($this->savedOccurrenceIds[$occurrenceId]);
    }

    public function isFollowing(FollowableType $type, ?int $id): ?bool
    {
        if ($this->followedIds === null || $id === null) {
            return null;
        }

        return isset($this->followedIds[$type->value][$id]);
    }
}
