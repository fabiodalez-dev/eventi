<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\DTOs\EventFilters;
use App\DTOs\PageMeta;
use App\Models\Category;
use App\Models\City;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\DateFormatter;
use Illuminate\Support\Str;

/**
 * Titolo, `<h1>` e descrizione di una lista filtrata (§11.3: «ogni
 * combinazione genera title, h1 e meta description sensati»).
 *
 * La frase si compone di quattro pezzi — soggetto, qualificatore, tempo,
 * luogo — perché in italiano l'ordine è quello e cambia con i filtri accesi:
 * "Concerti gratis stasera a Padova", "Eventi all'aperto questo weekend a Este".
 * I pezzi mancanti spariscono, non lasciano spazi.
 */
final class EventListingMeta
{
    public function __construct(private readonly DateFormatter $formatter) {}

    public function build(City $city, EventFilters $filters, int $total): PageMeta
    {
        $heading = $this->heading($city, $filters);

        return new PageMeta(
            title: $heading,
            heading: $heading,
            description: $this->description($city, $filters, $heading, $total),
            indexable: $this->isIndexable($filters),
        );
    }

    private function heading(City $city, EventFilters $filters): string
    {
        return Str::squish(__('events.meta.pattern', [
            'subject' => $this->subject($filters),
            'qualifiers' => $this->qualifiers($filters),
            'when' => $this->when($filters),
            'place' => $this->place($city, $filters),
        ]));
    }

    /**
     * Il soggetto è la categoria se ce n'è una sola, il tag se c'è solo quello,
     * altrimenti la parola generica.
     */
    private function subject(EventFilters $filters): string
    {
        if (count($filters->categories) === 1) {
            $category = Category::query()->where('slug', $filters->categories[0])->first();

            if ($category !== null) {
                return $category->name;
            }
        }

        if (count($filters->tags) === 1) {
            $tag = Tag::query()->where('slug', $filters->tags[0])->first();

            if ($tag !== null) {
                return __('events.meta.tagged', ['tag' => $tag->name]);
            }
        }

        return __('events.title');
    }

    private function qualifiers(EventFilters $filters): string
    {
        $parts = [];

        if ($filters->price !== null) {
            $parts[] = $filters->price->phrase();
        }

        if ($filters->outdoor) {
            $parts[] = __('events.meta.outdoor');
        }

        if ($filters->accessible) {
            $parts[] = __('events.meta.accessible');
        }

        if ($filters->family) {
            $parts[] = __('events.meta.family');
        }

        return implode(' ', $parts);
    }

    private function when(EventFilters $filters): string
    {
        if ($filters->preset !== null) {
            return $filters->preset->phrase();
        }

        if ($filters->date !== null) {
            return __('events.meta.on_date', ['date' => $this->formatter->weekdayDate($filters->date)]);
        }

        if ($filters->from !== null && $filters->to !== null) {
            return __('events.meta.between_dates', [
                'from' => $this->formatter->shortDate($filters->from),
                'to' => $this->formatter->shortDate($filters->to),
            ]);
        }

        if ($filters->time !== null) {
            return Str::lower($filters->time->label());
        }

        return '';
    }

    private function place(City $city, EventFilters $filters): string
    {
        if ($filters->venue !== null) {
            $venue = Venue::query()->where('slug', $filters->venue)->first();

            if ($venue !== null) {
                return __('events.meta.at_venue', ['venue' => $venue->name]);
            }
        }

        /*
         * Il quartiere è più preciso del comune, quindi vince: «eventi al
         * Portello» dice qualcosa che «eventi a Padova» non dice.
         */
        if ($filters->zone !== null) {
            return __('events.meta.in_place', ['place' => $filters->zone]);
        }

        if ($filters->municipality !== null) {
            return __('events.meta.in_place', ['place' => $filters->municipality]);
        }

        if ($filters->hasPosition()) {
            return __('events.meta.near_you');
        }

        return __('events.meta.in_place', ['place' => $city->name]);
    }

    private function description(City $city, EventFilters $filters, string $heading, int $total): string
    {
        if ($total === 0) {
            return __('events.meta.description_empty', ['city' => $city->name]);
        }

        if ($filters->q !== '') {
            return trans_choice('events.meta.description_search', $total, [
                'count' => $total,
                'query' => $filters->q,
                'city' => $city->name,
            ]);
        }

        return trans_choice('events.meta.description', $total, [
            'count' => $total,
            'heading' => Str::lcfirst($heading),
            'city' => $city->name,
        ]);
    }

    /**
     * Le combinazioni di filtri sono infinite e le pagine che meritano di stare
     * in un indice sono poche: una categoria, una data, un comune. Oltre due
     * filtri, o con una ricerca libera o una posizione, la pagina resta
     * navigabile ma non indicizzabile.
     */
    private function isIndexable(EventFilters $filters): bool
    {
        if ($filters->q !== '' || $filters->hasPosition()) {
            return false;
        }

        return $filters->activeCount() <= config()->integer('eventi.indexable_filters');
    }
}
