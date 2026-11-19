<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Carpool\UnifiedNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Gli avvisi si leggono per quello che dicono.
 *
 * Gli avvisi della biglietteria portano la frase in `message` e non hanno un
 * `title`: la pagina leggeva solo `title`, e ogni «Prenotazione confermata»
 * compariva come «I tuoi avvisi», il nome della pagina. Ora titolo, testo,
 * destinazione e locandina vengono da un'unica presentazione, usata dalla
 * pagina e dalla tendina della campanella.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create();
});

/** @param array<string, mixed> $data */
function avvisoPer(User $user, array $data, ?string $readAt = null): DatabaseNotification
{
    return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test.avviso', 'data' => $data, 'read_at' => $readAt]);
}

it('usa il messaggio come titolo quando l\'avviso non ne ha uno', function (): void {
    $avviso = avvisoPer($this->user, ['booking_id' => 999999, 'message' => 'Prenotazione confermata', 'url' => url('/biglietti')]);

    $presentato = app(UnifiedNotifications::class)->presentation($avviso);

    expect($presentato['title'])->toBe('Prenotazione confermata')
        ->and($presentato['body'])->toBeNull()
        ->and($presentato['url'])->toBe(url('/biglietti'))
        ->and($presentato['unread'])->toBeTrue();
});

it('tiene titolo e testo quando ci sono entrambi, e il messaggio diventa il testo', function (): void {
    $completo = avvisoPer($this->user, ['title' => 'Hai un nuovo follower', 'body' => 'Apri il profilo per vedere chi è.']);
    $messaggio = avvisoPer($this->user, ['title' => 'Aggiornamento dalla biglietteria', 'message' => 'La sala è cambiata.']);
    $vuoto = avvisoPer($this->user, ['kind' => 'sconosciuto']);

    $unified = app(UnifiedNotifications::class);

    expect($unified->presentation($completo))->toMatchArray(['title' => 'Hai un nuovo follower', 'body' => 'Apri il profilo per vedere chi è.'])
        ->and($unified->presentation($messaggio))->toMatchArray(['title' => 'Aggiornamento dalla biglietteria', 'body' => 'La sala è cambiata.'])
        ->and($unified->presentation($vuoto)['title'])->toBe(__('community.inbox_untitled'));
});

it('riporta su questo sito un collegamento salvato con un altro indirizzo, e scarta il resto', function (): void {
    $vecchio = avvisoPer($this->user, ['title' => 'Vecchio', 'url' => 'http://127.0.0.1:8099/biglietti?filtro=1#prima']);
    $senzaPercorso = avvisoPer($this->user, ['title' => 'Strano', 'url' => 'javascript:alert(1)']);

    $unified = app(UnifiedNotifications::class);

    expect($unified->presentation($vecchio)['url'])->toBe(url('/biglietti').'?filtro=1#prima')
        ->and($unified->presentation($senzaPercorso)['url'])->toBeNull();
});

it('trova l\'evento dell\'avviso per la locandina, con una query per tipo e non per avviso', function (): void {
    $data = occurrenceAt($this->city, $this->category, '2026-09-15 19:00:00');
    foreach (range(1, 5) as $n) {
        avvisoPer($this->user, ['title' => 'Promemoria '.$n, 'occurrence_id' => $data->id, 'event_id' => $data->event_id]);
    }

    DB::enableQueryLog();
    $presentati = app(UnifiedNotifications::class)->presentations($this->user->notifications()->get());
    $query = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($presentati)->toHaveCount(5)
        ->and(collect($presentati)->pluck('event.id')->unique()->all())->toBe([$data->event_id])
        ->and($query)->toBeLessThanOrEqual(6);
});

it('mostra nella pagina il titolo vero, il distintivo dei nuovi e il layout di servizio', function (): void {
    avvisoPer($this->user, ['booking_id' => 999999, 'message' => 'Prenotazione confermata', 'url' => url('/biglietti')]);
    avvisoPer($this->user, ['title' => 'Già letto', 'body' => 'Niente di nuovo.'], now()->toDateTimeString());

    $html = $this->actingAs($this->user)->get(route('community.inbox'))->assertOk()->getContent();

    expect($html)->toContain('data-service-page')
        ->toContain('Prenotazione confermata')
        ->toContain('Già letto')
        ->toContain('href="'.url('/biglietti').'"')
        ->and(preg_match_all('/>\s*'.preg_quote(__('community.new'), '/').'\s*</', $html))->toBe(1)
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('usa lo stesso titolo nella tendina della campanella', function (): void {
    avvisoPer($this->user, ['booking_id' => 999999, 'message' => 'Prenotazione confermata', 'url' => url('/biglietti')]);

    $this->actingAs($this->user)->get(route('community.inbox.latest'))->assertOk()
        ->assertSee('Prenotazione confermata')
        ->assertDontSee(__('community.inbox'));
});

it('senza avvisi mostra lo stato vuoto invece di un elenco vuoto', function (): void {
    $html = $this->actingAs($this->user)->get(route('community.inbox'))->assertOk()->getContent();
    // Il corpo della pagina, senza la tendina della campanella nella testata del sito.
    $corpo = substr($html, (int) strpos($html, 'data-service-page'));

    expect($corpo)->toContain(e(__('community.no_notifications')))
        ->toContain(e(__('community.no_notifications_help')))
        ->not->toContain('action="'.route('community.inbox.read').'"');
});
