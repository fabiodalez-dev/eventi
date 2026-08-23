<?php

declare(strict_types=1);

use App\Enums\PriceType;
use App\Models\Tag;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;

/**
 * I filtri di §8 che si compongono con le finestre temporali. Vivono qui e
 * nient'altro li riscrive: se un controller ricalcolasse "gratis" o "vicino a
 * me", in sei mesi esisterebbero due definizioni divergenti.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-05-15 09:00');
});

describe('categorie', function (): void {
    it('accetta lo slug della categoria', function (): void {
        $music = testCategory(['name' => 'Musica dal vivo']);
        $theatre = testCategory(['name' => 'Teatro e danza']);

        $concert = occurrenceAtLocal($this->city, $music, '2026-05-15 21:00');
        occurrenceAtLocal($this->city, $theatre, '2026-05-15 21:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->inCategories([$music->slug])->get()))
            ->toBe([(int) $concert->getKey()]);
    });

    it('accetta il modello e l\'identificatore numerico', function (): void {
        $music = testCategory(['name' => 'Musica dal vivo']);
        $theatre = testCategory(['name' => 'Teatro e danza']);

        $concert = occurrenceAtLocal($this->city, $music, '2026-05-15 21:00');
        $play = occurrenceAtLocal($this->city, $theatre, '2026-05-15 22:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->inCategories([$music])->get()))
            ->toBe([(int) $concert->getKey()])
            ->and(idsOf(EventOccurrenceQuery::for($this->city)->today()->inCategories([(int) $theatre->getKey()])->get()))
            ->toBe([(int) $play->getKey()]);
    });

    it('non filtra nulla se la lista è vuota', function (): void {
        $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->inCategories([])->get()))
            ->toBe([(int) $occurrence->getKey()]);
    });
});

describe('etichette', function (): void {
    it('seleziona gli eventi che portano almeno una delle etichette chieste', function (): void {
        $punk = Tag::factory()->create(['name' => 'punk']);
        $jazz = Tag::factory()->create(['name' => 'jazz']);

        $tagged = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        $tagged->event->tags()->attach($punk);

        $other = occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00');
        $other->event->tags()->attach($jazz);

        occurrenceAtLocal($this->city, $this->category, '2026-05-15 23:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->withTags([$punk->slug])->get()))
            ->toBe([(int) $tagged->getKey()])
            ->and(idsOf(EventOccurrenceQuery::for($this->city)->today()->withTags([$punk, $jazz])->get()))
            ->toBe([(int) $tagged->getKey(), (int) $other->getKey()]);
    });

    it('non duplica un evento che porta più di una delle etichette chieste', function (): void {
        $punk = Tag::factory()->create(['name' => 'punk']);
        $benefit = Tag::factory()->create(['name' => 'benefit']);

        $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        $occurrence->event->tags()->attach([$punk->getKey(), $benefit->getKey()]);

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->withTags([$punk, $benefit])->get()))
            ->toBe([(int) $occurrence->getKey()]);
    });
});

describe('prezzo', function (): void {
    it('priceFree tiene solo gli eventi dichiarati gratuiti', function (): void {
        $free = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00', event: [
            'price_type' => PriceType::Free,
            'price_min' => 0,
        ]);
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00', event: [
            'price_type' => PriceType::Donation,
        ]);
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 23:00', event: [
            'price_type' => PriceType::Ticket,
            'price_min' => 12,
        ]);

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->priceFree()->get()))
            ->toBe([(int) $free->getKey()]);
    });

    it('priceMax comprende gratuiti, offerta libera e biglietti fino all\'importo', function (): void {
        $free = occurrenceAtLocal($this->city, $this->category, '2026-05-15 18:00', event: [
            'price_type' => PriceType::Free,
            'price_min' => 0,
        ]);
        $donation = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:00', event: [
            'price_type' => PriceType::Donation,
        ]);
        $cheap = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: [
            'price_type' => PriceType::Ticket,
            'price_min' => 20,
        ]);
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00', event: [
            'price_type' => PriceType::Ticket,
            'price_min' => 25,
        ]);

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->priceMax(20)->get()))->toBe([
            (int) $free->getKey(),
            (int) $donation->getKey(),
            (int) $cheap->getKey(),
        ]);
    });
});

it('atVenue restringe a un solo locale', function (): void {
    $mine = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $other = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $here = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00', venue: $mine);
    occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00', venue: $other);

    expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->atVenue($mine)->get()))
        ->toBe([(int) $here->getKey()]);
});

describe('ricerca testuale', function (): void {
    it('cerca nel titolo dell\'evento e nel nome del locale', function (): void {
        $venue = Venue::factory()->approved()->create([
            'city_id' => $this->city->getKey(),
            'name' => 'Circolo Arcadia',
        ]);

        $byTitle = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: [
            'title' => 'Concerto di arpa celtica',
        ]);
        $byVenue = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00', event: [
            'title' => 'Serata liscio',
        ], venue: $venue);
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00', event: [
            'title' => 'Assemblea di quartiere',
        ]);

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->search('arpa')->get()))
            ->toBe([(int) $byTitle->getKey()])
            ->and(idsOf(EventOccurrenceQuery::for($this->city)->today()->search('Arcadia')->get()))
            ->toBe([(int) $byVenue->getKey()]);
    });

    it('tratta i caratteri jolly come testo, non come modello di ricerca', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['title' => 'Serata liscio']);

        expect(EventOccurrenceQuery::for($this->city)->today()->search('%')->get())->toBeEmpty();
    });

    it('ignora un termine di sola spaziatura', function (): void {
        $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->search('   ')->get()))
            ->toBe([(int) $occurrence->getKey()]);
    });
});

it('compone finestra, categoria e prezzo senza perdere nessuno dei tre vincoli', function (): void {
    $music = testCategory(['name' => 'Musica dal vivo']);
    $theatre = testCategory(['name' => 'Teatro e danza']);

    $wanted = occurrenceAtLocal($this->city, $music, '2026-05-15 21:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => 0,
    ]);
    // Giusta categoria e prezzo, ma domani.
    occurrenceAtLocal($this->city, $music, '2026-05-16 21:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => 0,
    ]);
    // Giusta finestra e prezzo, ma altra categoria.
    occurrenceAtLocal($this->city, $theatre, '2026-05-15 21:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => 0,
    ]);
    // Giusta finestra e categoria, ma a pagamento.
    occurrenceAtLocal($this->city, $music, '2026-05-15 22:00', event: [
        'price_type' => PriceType::Ticket,
        'price_min' => 15,
    ]);

    expect(idsOf(
        EventOccurrenceQuery::for($this->city)
            ->tonight()
            ->inCategories([$music->slug])
            ->priceFree()
            ->get()
    ))->toBe([(int) $wanted->getKey()]);
});
