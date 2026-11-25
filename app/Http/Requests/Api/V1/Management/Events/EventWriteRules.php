<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Enums\VenueStatus;
use App\Models\Event;
use App\Services\Events\EventFormRules;
use Illuminate\Validation\Rule;

/**
 * Le regole dei quattro moduli dell'app (creazione e modifica, locale e
 * organizzazione), composte dalle stesse di `EventFormRules` che usano i
 * pannelli. In modifica ogni campo è facoltativo (`sometimes`): quello che
 * l'app non manda resta com'è.
 */
final class EventWriteRules
{
    /**
     * Creazione del locale. `publish` vero è il pulsante «Pubblica» del
     * modulo rapido (servono data e categoria); falso è «Salva come bozza»
     * (basta il titolo).
     *
     * @return array<string, list<mixed>>
     */
    public static function venueStore(bool $publish): array
    {
        return [
            'publish' => ['sometimes', 'boolean'],
            'draft_id' => ['nullable', 'integer'],
            'facebook_import' => ['nullable', 'string', 'size:40'],
            ...EventFormRules::basics('', categoryRequired: $publish),
            ...EventFormRules::venueLinks(),
            ...EventFormRules::location(),
            ...EventFormRules::beforeGoing(),
            ...EventFormRules::firstDate('', startRequired: $publish),
            ...EventFormRules::repeat(),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function venueUpdate(Event $event): array
    {
        return self::optional([
            ...EventFormRules::basics(),
            ...EventFormRules::venueLinks(),
            ...EventFormRules::location(),
            ...EventFormRules::beforeGoing('', onlyBeforeGoing: false),
            ...EventFormRules::editorial($event),
            ...EventFormRules::venueEditOnly(),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public static function organizerStore(): array
    {
        return [
            'facebook_import' => ['nullable', 'string', 'size:40'],
            ...self::organizerFields(null),
            ...EventFormRules::location(),
            ...EventFormRules::firstDate(),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function organizerUpdate(Event $event): array
    {
        return self::optional(self::organizerFields($event));
    }

    /**
     * Il modulo unico dell'organizzazione: descrizione obbligatoria, città e
     * locale principale, link dei biglietti fino a 2048 caratteri, nessun
     * link di prenotazione né link esterni.
     *
     * @return array<string, list<mixed>>
     */
    private static function organizerFields(?Event $event): array
    {
        return [
            ...EventFormRules::basics('', categoryRequired: true, descriptionRequired: true, ticketUrlMax: 2048),
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)],
            ...EventFormRules::beforeGoing('', onlyBeforeGoing: false),
            ...EventFormRules::editorial($event),
        ];
    }

    /**
     * Rende facoltativi i campi di primo livello: una modifica parziale non
     * deve chiedere di rimandare tutto il modulo.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, list<mixed>>
     */
    private static function optional(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (! str_contains($key, '.')) {
                $rules[$key] = ['sometimes', ...$rule];
            }
        }

        return $rules;
    }
}
