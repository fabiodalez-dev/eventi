<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Models\Event;
use App\Models\EventCollection;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/*
 * Le pagine dell'area personale hanno tutte lo stesso disegno: il layout di
 * servizio (una colonna da 48rem), un solo titolo, nessuna larghezza o
 * griglia a più colonne scritta nella pagina, le locandine negli elenchi.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', null, [], ['title' => 'Concerto salvato']);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->date->id]);
    $this->collection = EventCollection::create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'name' => 'Il weekend', 'is_public' => true]);
    $this->collection->items()->create(['occurrence_id' => $this->date->id, 'position' => 1, 'note' => 'Prendere i biglietti']);
    Follow::query()->create(['user_id' => $this->user->id, 'followable_type' => FollowableType::Venue->value, 'followable_id' => $this->date->event->venue_id]);
});

afterEach(fn () => Carbon::setTestNow());

/** Il corpo della pagina di servizio, senza testata, piè di pagina e layout. */
function servicePageBody(string $html): string
{
    expect($html)->toContain('data-service-page');

    return substr($html, (int) strpos($html, 'data-service-page'));
}

/** @return array<string, array{0: Closure(): string, 1: bool}> */
function accountServiceRoutes(): array
{
    return [
        'profilo' => [fn (): string => '/il-mio-profilo', true],
        'salvati' => [fn (): string => '/i-miei-salvataggi', true],
        'salvati calendario' => [fn (): string => '/i-miei-salvataggi?vista=calendario&mese=2026-11', true],
        'salvati passati' => [fn (): string => '/i-miei-salvataggi?passate=1', true],
        'feed' => [fn (): string => '/il-mio-feed', true],
        'raccolte' => [fn (): string => '/le-mie-raccolte', true],
        'raccolta' => [fn (): string => route('account.collections.show', EventCollection::query()->firstOrFail()), true],
        'raccolta condivisa' => [fn (): string => route('collections.show', EventCollection::query()->firstOrFail()->token), false],
        'serate' => [fn (): string => '/le-mie-serate', true],
        'aspetto' => [fn (): string => '/aspetto', true],
        'interessi' => [fn (): string => '/profilo/interessi', true],
        'notifiche' => [fn (): string => URL::temporarySignedRoute('notifications.preferences', now()->addHour(), ['user' => User::query()->firstOrFail()->id]), true],
        'avvisi da seguire' => [fn (): string => '/notifiche/interessi', true],
        'calendario google' => [fn (): string => '/il-mio-calendario/google', true],
        'proponi evento' => [fn (): string => '/proponi-evento', false],
        'calendario personalizzato' => [fn (): string => '/calendario/personalizza?step=2', false],
        'segnala' => [fn (): string => '/eventi/'.Event::query()->firstOrFail()->slug.'/segnala', false],
    ];
}

it('disegna ogni pagina dell area personale con il layout di servizio', function (string $name): void {
    [$url, $auth] = accountServiceRoutes()[$name];
    $request = $auth ? $this->actingAs($this->user) : $this;
    $html = $request->get($url())->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('data-service-layout');

    $body = servicePageBody($html);
    $body = substr($body, 0, (int) (strpos($body, '</main>') ?: strlen($body)));
    /* Il riquadro sponsorizzato e lo stato vuoto sono componenti comuni con
       la propria misura: qui conta ciò che scrive la pagina. */
    $body = (string) preg_replace('/<aside hidden data-live-sponsorship.*?<\/aside>/s', '', $body);
    $body = (string) preg_replace('/<dialog .*?<\/dialog>/s', '', $body);
    $body = str_replace(['max-w-prose', 'max-w-full'], '', $body);

    expect($body)->not->toMatch('/class="[^"]*\bmax-w-/')
        ->and($body)->not->toMatch('/class="[^"]*\b(?:sm:|md:|lg:|xl:)?grid-cols-(?!7\b)\d/')
        ->and($body)->not->toMatch('/class="[^"]*\brounded/');
})->with(array_keys(accountServiceRoutes()));

it('mostra le locandine negli elenchi di salvati, feed e raccolte', function (Closure $url): void {
    $this->actingAs($this->user)->get($url())->assertOk()
        ->assertSee('data-event-row', false)
        ->assertSee('Concerto salvato');
})->with([
    'salvati' => [fn (): string => '/i-miei-salvataggi'],
    'feed' => [fn (): string => '/il-mio-feed'],
    'raccolta' => [fn (): string => route('account.collections.show', EventCollection::query()->firstOrFail())],
]);

it('mostra la nota della raccolta e le prime locandine nell elenco delle raccolte', function (): void {
    $this->actingAs($this->user)->get('/le-mie-raccolte')->assertOk()->assertSee('Il weekend')->assertSee('svc-poster', false);
    $this->get(route('collections.show', $this->collection->token))->assertOk()->assertSee('Prendere i biglietti')->assertSee('data-event-row', false);
});

it('torna al profilo da ogni pagina tranne il profilo stesso', function (): void {
    $this->actingAs($this->user)->get('/il-mio-profilo')->assertOk()->assertDontSee('data-profile-back', false);
    $this->get('/i-miei-salvataggi')->assertOk()->assertSee('data-profile-back', false)->assertSee('href="'.route('account.profile').'"', false);
});

it('elenca le aree di lavoro solo a chi le ha', function (): void {
    $this->actingAs($this->user)->get('/il-mio-profilo')->assertOk()->assertDontSee(__('account.profile.manage_title'));

    $this->date->event->venue->members()->attach($this->user, ['role' => 'owner', 'accepted_at' => now()]);
    $this->actingAs($this->user->fresh())->get('/il-mio-profilo')->assertOk()
        ->assertSee(__('account.profile.manage_title'))->assertSee(url('/gestione'), false);
});

it('non carica relazioni una riga alla volta nelle pagine con gli elenchi', function (Closure $url): void {
    $address = $url();
    Model::preventLazyLoading();

    try {
        $this->actingAs($this->user)->get($address)->assertOk();
    } finally {
        Model::preventLazyLoading(false);
    }
})->with([
    'salvati' => [fn (): string => '/i-miei-salvataggi'],
    'salvati calendario' => [fn (): string => '/i-miei-salvataggi?vista=calendario&mese=2026-11'],
    'feed' => [fn (): string => '/il-mio-feed'],
    'raccolte' => [fn (): string => '/le-mie-raccolte'],
    'raccolta' => [fn (): string => route('account.collections.show', EventCollection::query()->firstOrFail())],
    'serate' => [fn (): string => '/le-mie-serate'],
]);
