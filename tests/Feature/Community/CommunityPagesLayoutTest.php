<?php

declare(strict_types=1);

use App\Models\CommunityPost;
use App\Models\User;

require_once __DIR__.'/../Flows/Community/SupportoFlussiCommunity.php';

/**
 * Le pagine della community hanno lo stesso disegno delle altre pagine di
 * servizio: la colonna unica del layout, un solo titolo, le schede della
 * community in testata, nessuna griglia a più colonne e nessuna larghezza
 * scritta nel corpo. Le partecipazioni e i consigli mostrano la locandina.
 */
beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->autrice = fcomPersona('andrea_prova', 'public', 'Andrea Prova');
    $this->data = fcomData($this->city, $this->category, '2026-09-15 21:00', ['title' => 'Concerto in piazza']);
    $this->post = fcomConsiglio($this->autrice, $this->data, 'Da non perdere');
    DB::table('community_attendances')->insert(['user_id' => $this->autrice->id, 'occurrence_id' => $this->data->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->lettore = fcomPersona('chi_legge', 'public', 'Chi Legge');
    $this->lettore->follow($this->autrice);
});

/** Il corpo della pagina di servizio, senza testata e piede del sito. */
function corpoServizioCommunity(string $html): string
{
    $inizio = strpos($html, 'data-service-page');
    expect($inizio)->not->toBeFalse();
    $fine = strpos($html, '</main>', $inizio);

    return substr($html, $inizio, $fine === false ? null : $fine - $inizio);
}

dataset('pagine della community', [
    'bacheca' => fn () => route('community.feed'),
    'bacheca da scoprire' => fn () => route('community.feed', ['scope' => 'discover']),
    'persone' => fn () => route('community.people'),
    'profilo pubblico' => fn () => route('community.profile', 'andrea_prova'),
    'profilo, archivio' => fn () => route('community.profile', ['handle' => 'andrea_prova', 'past' => 1]),
    'seguiti e follower' => fn () => route('community.followers'),
    'bloccati' => fn () => route('community.followers', ['tab' => 'blocks']),
    'profilo social' => fn () => route('community.settings'),
    'avvisi' => fn () => route('community.inbox'),
    'verifica whatsapp' => fn () => route('community.whatsapp'),
    'consiglio' => fn () => route('community.post', CommunityPost::query()->firstOrFail()),
]);

it('usa il layout di servizio con un solo titolo e senza griglie', function (Closure|string $indirizzo): void {
    $html = $this->actingAs($this->lettore)->get(is_string($indirizzo) ? $indirizzo : $indirizzo())->assertOk()->getContent();
    $corpo = corpoServizioCommunity($html);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('data-service-layout')
        ->and($corpo)->toContain('aria-label="'.e(__('community.nav')).'"')
        // `max-w-prose` è del testo dello stato vuoto comune, non una larghezza della pagina.
        ->and($corpo)->not->toMatch('/\bmax-w-(?!prose\b)/')
        ->and($corpo)->not->toMatch('/\b(?:sm:|md:|lg:|xl:)?grid-cols-[2-9]\b/');
})->with('pagine della community');

it('mostra la locandina nelle partecipazioni e nei consigli del profilo', function (): void {
    $html = $this->actingAs($this->lettore)->get(route('community.profile', 'andrea_prova'))->assertOk()->getContent();
    $corpo = corpoServizioCommunity($html);

    // Una riga evento per la partecipazione e una per il consiglio, entrambe con la locandina (o il suo segnaposto).
    expect(substr_count($corpo, 'data-event-row'))->toBe(2)
        ->and(substr_count($corpo, 'svc-poster'))->toBeGreaterThanOrEqual(2)
        ->and($corpo)->toContain('Concerto in piazza')
        ->and($corpo)->toContain('Da non perdere');
});

it('mette Segnala e Blocca in «Altre azioni» sul profilo di un\'altra persona', function (): void {
    $html = $this->actingAs($this->lettore)->get(route('community.profile', 'andrea_prova'))->assertOk()->getContent();

    expect($html)->toContain('data-profile-more')
        ->toContain(e(__('community.report_profile')))
        ->toContain('action="'.route('community.block', $this->autrice->id).'"');
});

it('sul proprio profilo mostra Modifica e non Segui né Blocca', function (): void {
    $html = $this->actingAs($this->autrice)->get(route('community.profile', 'andrea_prova'))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('community.settings').'"')
        ->not->toContain('data-profile-more')
        ->not->toContain('action="'.route('community.follow', $this->autrice->id).'"');
});

it('un ospite vede il profilo pubblico senza il ritorno al profilo personale', function (): void {
    $html = $this->get(route('community.profile', 'andrea_prova'))->assertOk()->getContent();

    expect($html)->toContain('data-service-page')->not->toContain('data-profile-back');
});

it('la bacheca mostra i consigli in una sola pila, con autore e locandina', function (): void {
    $corpo = corpoServizioCommunity($this->actingAs($this->lettore)->get(route('community.feed'))->assertOk()->getContent());

    expect($corpo)->toContain('data-community-post="'.$this->post->id.'"')
        ->toContain('Andrea Prova')
        ->toContain('data-event-row');
});

it('la pagina per consigliare mostra la data con la sua locandina', function (): void {
    /** @var User $autrice */
    $autrice = $this->autrice;
    $corpo = corpoServizioCommunity($this->actingAs($autrice)->get(route('community.compose', $this->data->id))->assertOk()->getContent());

    expect($corpo)->toContain('data-event-row')->toContain('Concerto in piazza');
});
