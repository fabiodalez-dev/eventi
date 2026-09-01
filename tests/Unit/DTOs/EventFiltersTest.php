<?php

declare(strict_types=1);

use App\DTOs\EventFilters;
use App\Enums\DatePreset;
use App\Enums\EventSort;
use App\Enums\PriceFilter;
use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;

/**
 * `App\DTOs\EventFilters` è lo stato di una lista pubblica (§11.3, D25 punto
 * 3): l'oggetto è immutabile, non tocca il database e sa rigenerare il proprio
 * indirizzo. Da quest'ultima proprietà dipendono tre cose che si vedono —
 * il canonical, le pillole di filtro e la condivisibilità di un link — quindi
 * qui si verifica il giro completo: query string → oggetto → query string.
 *
 * Sono test unitari veri: nessuna migrazione, nessuna richiesta HTTP.
 */
it('legge dalla query string tutto ciò che sa leggere', function (): void {
    $filters = EventFilters::fromArray([
        'date' => 'tonight',
        'category' => 'musica-dal-vivo,teatro',
        'tag' => 'jazz',
        'price' => 'free',
        'time' => 'night',
        'municipality' => 'Abano Terme',
        'venue' => 'circolo-nadir',
        'lat' => '45.4064',
        'lng' => '11.8768',
        'radius' => '5',
        'accessible' => '1',
        'outdoor' => 'true',
        'family' => 'on',
        'sort' => 'distance',
        'q' => '  concerto  ',
    ]);

    expect($filters->preset)->toBe(DatePreset::Tonight)
        ->and($filters->date)->toBeNull()
        ->and($filters->categories)->toBe(['musica-dal-vivo', 'teatro'])
        ->and($filters->tags)->toBe(['jazz'])
        ->and($filters->price)->toBe(PriceFilter::Free)
        ->and($filters->time)->toBe(TimeOfDay::Night)
        ->and($filters->municipality)->toBe('Abano Terme')
        ->and($filters->venue)->toBe('circolo-nadir')
        ->and($filters->lat)->toBe(45.4064)
        ->and($filters->lng)->toBe(11.8768)
        ->and($filters->radius)->toBe(5.0)
        ->and($filters->accessible)->toBeTrue()
        ->and($filters->outdoor)->toBeTrue()
        ->and($filters->family)->toBeTrue()
        ->and($filters->sort)->toBe(EventSort::Distance)
        ->and($filters->q)->toBe('concerto');
});

it('accetta una data puntuale nello stesso parametro del preset, e solo nel formato del calendario', function (): void {
    $exact = EventFilters::fromArray(['date' => '2026-09-25']);

    expect($exact->preset)->toBeNull()
        ->and($exact->date?->format('Y-m-d H:i:s'))->toBe('2026-09-25 00:00:00')
        ->and($exact->toQueryString()['date'])->toBe('2026-09-25');

    /* `25/09/2026`, `2026-9-5` e una parola qualsiasi non sono date: non
       diventano né un preset né un giorno, e spariscono dall'indirizzo. */
    foreach (['25/09/2026', '2026-9-5', 'domani'] as $nonsense) {
        $filters = EventFilters::fromArray(['date' => $nonsense]);

        expect($filters->preset)->toBeNull()
            ->and($filters->date)->toBeNull()
            ->and($filters->toQueryString())->not->toHaveKey('date');
    }
});

it('rigenera la query string da cui è nato', function (): void {
    $input = [
        'date' => 'tonight',
        'category' => 'musica-dal-vivo,teatro',
        'price' => 'free',
        'q' => 'blues',
    ];

    expect(EventFilters::fromArray($input)->toQueryString())->toBe([
        'date' => 'tonight',
        'category' => 'musica-dal-vivo,teatro',
        'price' => 'free',
        'q' => 'blues',
    ]);
});

it('non lascia voci vuote nell\'indirizzo: due filtri uguali danno lo stesso link', function (): void {
    $first = EventFilters::fromArray(['date' => 'today', 'category' => '', 'tag' => ' , ', 'q' => '   ']);
    $second = (new EventFilters)->withPreset(DatePreset::Today);

    expect($first->toQueryString())->toBe(['date' => 'today'])
        ->and($first->toQueryString())->toBe($second->toQueryString())
        ->and($first->accessible)->toBeFalse();
});

it('scarta gli slug ripetuti e quelli che non sono stringhe', function (): void {
    $filters = EventFilters::fromArray(['category' => ['teatro', ' teatro ', 42, '', 'danza']]);

    expect($filters->categories)->toBe(['teatro', 'danza']);
});

it('conta i filtri accesi senza contare l\'ordinamento', function (): void {
    $filters = EventFilters::fromArray([
        'date' => 'today',
        'category' => 'teatro',
        'price' => 'free',
        'sort' => 'distance',
    ]);

    expect($filters->activeCount())->toBe(3)
        ->and($filters->isEmpty())->toBeFalse()
        ->and(EventFilters::fromArray(['sort' => 'time'])->activeCount())->toBe(0)
        ->and((new EventFilters)->isEmpty())->toBeTrue();
});

it('resta immutabile: ogni variante è un oggetto nuovo', function (): void {
    $original = EventFilters::fromArray(['date' => 'today']);
    $variant = $original->withPrice(PriceFilter::Free);

    expect($variant)->not->toBe($original)
        ->and($original->price)->toBeNull()
        ->and($variant->price)->toBe(PriceFilter::Free)
        ->and($variant->preset)->toBe(DatePreset::Today);
});

it('una finestra temporale nuova cancella la precedente invece di sommarsi', function (): void {
    $range = new EventFilters(
        from: CarbonImmutable::parse('2026-09-01'),
        to: CarbonImmutable::parse('2026-09-30'),
    );

    expect($range->hasDateWindow())->toBeTrue()
        ->and($range->toQueryString())->toBe(['from' => '2026-09-01', 'to' => '2026-09-30']);

    $preset = $range->withPreset(DatePreset::Weekend);

    expect($preset->from)->toBeNull()
        ->and($preset->to)->toBeNull()
        ->and($preset->toQueryString())->toBe(['date' => 'weekend']);

    $exact = $preset->withDate(CarbonImmutable::parse('2026-10-02'));

    expect($exact->preset)->toBeNull()
        ->and($exact->toQueryString())->toBe(['date' => '2026-10-02'])
        ->and($exact->withPreset(null)->hasDateWindow())->toBeFalse();
});

it('accende e spegne una categoria con lo stesso link', function (): void {
    $filters = (new EventFilters)->toggleCategory('teatro');

    expect($filters->hasCategory('teatro'))->toBeTrue()
        ->and($filters->toggleCategory('danza')->categories)->toBe(['teatro', 'danza'])
        ->and($filters->toggleCategory('teatro')->categories)->toBe([])
        ->and($filters->toggleCategory('teatro')->hasCategory('teatro'))->toBeFalse();
});

it('accende e spegne un tag con lo stesso link', function (): void {
    $filters = (new EventFilters)->toggleTag('jazz')->toggleTag('swing');

    expect($filters->hasTag('jazz'))->toBeTrue()
        ->and($filters->toggleTag('jazz')->tags)->toBe(['swing'])
        ->and($filters->toQueryString()['tag'])->toBe('jazz,swing');
});

it('azzera tutto tranne la posizione: chi l\'ha concessa non deve riconcederla', function (): void {
    $filters = EventFilters::fromArray([
        'date' => 'today',
        'category' => 'teatro',
        'q' => 'jazz',
        'lat' => '45.4',
        'lng' => '11.8',
        'radius' => '2',
    ]);

    $cleared = $filters->cleared();

    expect($cleared->hasPosition())->toBeTrue()
        ->and($cleared->radius)->toBe(2.0)
        ->and($cleared->preset)->toBeNull()
        ->and($cleared->categories)->toBe([])
        ->and($cleared->q)->toBe('')
        ->and($cleared->toQueryString())->toBe(['lat' => '45.4', 'lng' => '11.8', 'radius' => '2']);
});

it('non dichiara una posizione quando ne conosce metà', function (): void {
    expect(EventFilters::fromArray(['lat' => '45.4'])->hasPosition())->toBeFalse()
        ->and(EventFilters::fromArray(['lng' => '11.8'])->hasPosition())->toBeFalse()
        ->and(EventFilters::fromArray(['lat' => 'nord', 'lng' => 'ovest'])->lat)->toBeNull()
        ->and(EventFilters::fromArray(['lat' => '45.4', 'lng' => '11.8'])->hasPosition())->toBeTrue();
});

it('ignora un valore che non appartiene a nessun enum invece di inventarselo', function (): void {
    $filters = EventFilters::fromArray([
        'date' => 'dopodomani',
        'price' => 'quasi-gratis',
        'time' => 'alba',
        'sort' => 'a-caso',
    ]);

    expect($filters->preset)->toBeNull()
        ->and($filters->price)->toBeNull()
        ->and($filters->time)->toBeNull()
        ->and($filters->sort)->toBeNull()
        ->and($filters->toQueryString())->toBe([]);
});

it('rigenerare l\'oggetto dal proprio indirizzo non cambia più nulla', function (): void {
    /* Il punto fisso è la proprietà da cui dipende il canonical: se leggere e
       riscrivere spostasse anche una virgola, due visite alla stessa pagina
       dichiarerebbero due indirizzi canonici diversi. */
    $originale = EventFilters::fromArray([
        'date' => '2026-10-02',
        'category' => 'teatro,danza',
        'tag' => 'jazz',
        'price' => 'free',
        'time' => 'night',
        'municipality' => 'Abano Terme',
        'venue' => 'circolo-nadir',
        'lat' => '45.4064',
        'lng' => '11.8768',
        'radius' => '2.5',
        'accessible' => '1',
        'outdoor' => '1',
        'family' => '1',
        'sort' => 'distance',
        'q' => 'quartetto',
    ]);

    $rigenerato = EventFilters::fromArray($originale->toQueryString());

    expect($rigenerato->toQueryString())->toBe($originale->toQueryString())
        ->and($rigenerato->date?->format('Y-m-d'))->toBe('2026-10-02')
        ->and($rigenerato->categories)->toBe(['teatro', 'danza'])
        ->and($rigenerato->radius)->toBe(2.5)
        ->and($rigenerato->accessible)->toBeTrue();
});

it('cambia comune, locale e domanda, e sa toglierli', function (): void {
    $filters = (new EventFilters)
        ->withMunicipality('Abano Terme')
        ->withVenue('circolo-nadir')
        ->withSearch('fisarmonica');

    expect($filters->toQueryString())->toBe([
        'municipality' => 'Abano Terme',
        'venue' => 'circolo-nadir',
        'q' => 'fisarmonica',
    ]);

    $vuoto = $filters->withMunicipality(null)->withVenue(null)->withSearch('');

    expect($vuoto->isEmpty())->toBeTrue()
        ->and($vuoto->q)->toBe('')
        ->and($vuoto->municipality)->toBeNull();
});

it('dimentica la posizione quando la si toglie', function (): void {
    $conPosizione = (new EventFilters)->withPosition(45.4064, 11.8768, 3.0);

    expect($conPosizione->hasPosition())->toBeTrue()
        ->and($conPosizione->toQueryString())->toHaveKeys(['lat', 'lng', 'radius']);

    $senza = $conPosizione->withPosition(null, null, null);

    expect($senza->hasPosition())->toBeFalse()
        ->and($senza->toQueryString())->toBe([]);
});

it('accende e spegne i tre interruttori di accessibilità, aria aperta e famiglia', function (): void {
    $acceso = (new EventFilters)->withAccessible(true)->withOutdoor(true)->withFamily(true);

    expect($acceso->toQueryString())->toBe([
        'accessible' => '1',
        'outdoor' => '1',
        'family' => '1',
    ])->and($acceso->activeCount())->toBe(3);

    $spento = $acceso->withAccessible(false)->withOutdoor(false)->withFamily(false);

    expect($spento->toQueryString())->toBe([])
        ->and($spento->isEmpty())->toBeTrue();
});

it('legge come spento ciò che nell\'indirizzo dice di no', function (): void {
    foreach (['0', 'false', 'no', 'off', ''] as $negazione) {
        $filters = EventFilters::fromArray([
            'accessible' => $negazione,
            'outdoor' => $negazione,
            'family' => $negazione,
        ]);

        expect($filters->accessible)->toBeFalse("«{$negazione}» non è un sì")
            ->and($filters->outdoor)->toBeFalse()
            ->and($filters->family)->toBeFalse()
            ->and($filters->toQueryString())->toBe([]);
    }
});

it('non ripete uno slug scelto due volte, e conserva l\'ordine in cui è stato scelto', function (): void {
    $filters = (new EventFilters)
        ->withCategories(['teatro', 'danza', 'teatro'])
        ->withTags(['jazz', 'jazz', 'swing']);

    expect($filters->categories)->toBe(['teatro', 'danza'])
        ->and($filters->tags)->toBe(['jazz', 'swing'])
        ->and($filters->toQueryString()['category'])->toBe('teatro,danza');
});

it('azzerare senza posizione lascia un indirizzo nudo', function (): void {
    $cleared = EventFilters::fromArray(['date' => 'today', 'q' => 'jazz'])->cleared();

    expect($cleared->isEmpty())->toBeTrue()
        ->and($cleared->hasPosition())->toBeFalse()
        ->and($cleared->hasDateWindow())->toBeFalse()
        ->and($cleared->toQueryString())->toBe([]);
});

it('toglie l\'ordinamento senza toccare i filtri', function (): void {
    $filters = EventFilters::fromArray(['date' => 'today', 'sort' => 'distance']);

    expect($filters->activeCount())->toBe(1);

    $senzaOrdine = $filters->withSort(null);

    expect($senzaOrdine->sort)->toBeNull()
        ->and($senzaOrdine->activeCount())->toBe(1)
        ->and($senzaOrdine->toQueryString())->toBe(['date' => 'today']);
});
