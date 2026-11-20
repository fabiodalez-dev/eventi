<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\EventUrl;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * La navigazione fra le pagine di servizio dei passaggi come la fa una persona: dal profilo
 * ai passaggi, alla richiesta, alla chat e indietro seguendo solo i collegamenti delle
 * pagine. Protegge che ogni pagina abbia il ritorno giusto (le pagine personali tornano al
 * profilo), la scheda attiva giusta, e le intestazioni che la tengono fuori da cache e
 * motori di ricerca.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(function (): void {
    cpSetup($this);
    $this->ride = cpAccepted($this);
    $this->offer = $this->ride->offer;
    $this->chat = percorsoCpChat($this->ride);
    $this->case = cpCase($this, $this->ride);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** L'etichetta della scheda dei passaggi segnata come pagina corrente. */
function percorsoCpActiveTab(TestResponse $response): ?string
{
    $label = preg_quote(e(__('carpool.web.tabs.label')), '~');
    if (preg_match('~<nav[^>]*aria-label="'.$label.'">(.*?)</nav>~s', $response->getContent(), $nav) !== 1) {
        return null;
    }

    return preg_match('~aria-current="page"\s*>([^<]+)~', $nav[1], $match) === 1 ? html_entity_decode(trim($match[1])) : null;
}

it('le pagine personali dei passaggi hanno il ritorno al profilo', function (): void {
    flussoCpWeb($this, $this->passenger);
    foreach ([route('carpool.index'), route('carpool.chats'), route('carpool.chats', ['archived' => 1]), route('carpool.cases'), route('carpool.requirements'),
        route('carpool.terms'), route('carpool.reviews', $this->driver)] as $url) {
        $response = $this->get($url)->assertOk();
        expect(percorsoCpBack($response))->toBe(route('account.profile'), $url);
        $response->assertSee(__('ui.service.back_profile'));
    }
    // E dal profilo si torna ai passaggi.
    $this->get(route('account.profile'))->assertOk()->assertSee(route('carpool.index'), false)->assertSee(route('carpool.chats'), false);
});

it('le pagine di dettaglio tornano alla pagina da cui si arriva, diversa per conducente e passeggero', function (): void {
    $back = function (User $user, string $url): ?string {
        flussoCpWeb($this, $user);

        return percorsoCpBack($this->get($url)->assertOk());
    };

    expect($back($this->driver, route('carpool.offer', $this->offer)))->toBe(route('carpool.index'))
        ->and($back($this->passenger, route('carpool.offer', $this->offer)))->toBe(route('carpool.dates', $this->date->id))
        ->and($back($this->passenger, route('carpool.request', $this->ride)))->toBe(route('carpool.index'))
        ->and($back($this->driver, route('carpool.request', $this->ride)))->toBe(route('carpool.offer', $this->offer->id))
        ->and($back($this->passenger, route('carpool.chat', $this->chat)))->toBe(route('carpool.chats'))
        ->and($back($this->passenger, route('carpool.case', $this->case)))->toBe(route('carpool.cases'))
        ->and($back($this->driver, route('carpool.create', $this->date)))->toBe(route('carpool.dates', $this->date->id))
        ->and($back($this->passenger, route('carpool.dates', $this->date)))->toBe(EventUrl::occurrence($this->date));
});

it('dal profilo si arriva alla richiesta, alla chat e all\'assistenza e si torna indietro seguendo solo i collegamenti', function (): void {
    flussoCpWeb($this, $this->passenger);
    $this->get(route('account.profile'))->assertOk()->assertSee(route('carpool.index'), false);
    $this->get(route('carpool.index'))->assertOk()->assertSee(route('carpool.request', $this->ride), false);
    $this->get(route('carpool.request', $this->ride))->assertOk()->assertSee(route('carpool.chat', $this->chat), false)->assertSee(route('carpool.offer', $this->offer), false);
    $chat = $this->get(route('carpool.chat', $this->chat))->assertOk()->assertSee(route('carpool.request', $this->ride), false);
    $this->get(percorsoCpBack($chat))->assertOk()->assertSee(route('carpool.chat', $this->chat), false);
    $this->get(route('carpool.requirements'))->assertOk()->assertSee(route('carpool.cases'), false)->assertSee(route('carpool.terms'), false);
    $cases = $this->get(route('carpool.cases'))->assertOk()->assertSee(route('carpool.case', $this->case), false);
    $case = $this->get(route('carpool.case', $this->case))->assertOk();
    expect(percorsoCpBack($case))->toBe(route('carpool.cases'))->and(percorsoCpBack($cases))->toBe(route('account.profile'));
});

it('la scheda attiva segue la pagina: Passaggi, Messaggi o Aiuto', function (): void {
    $pages = [
        'rides' => [route('carpool.index'), route('carpool.offer', $this->offer), route('carpool.request', $this->ride), route('carpool.dates', $this->date), route('carpool.reviews', $this->driver)],
        'messages' => [route('carpool.chats'), route('carpool.chat', $this->chat)],
        'help' => [route('carpool.requirements'), route('carpool.terms'), route('carpool.cases'), route('carpool.case', $this->case)],
    ];
    flussoCpWeb($this, $this->passenger);
    foreach ($pages as $tab => $urls) {
        foreach ($urls as $url) {
            expect(percorsoCpActiveTab($this->get($url)->assertOk()))->toBe(__('carpool.web.tabs.'.$tab), $url);
        }
    }
});

it('le pagine e le risposte dell\'app dei passaggi non restano in cache e non si indicizzano', function (): void {
    flussoCpWeb($this, $this->passenger);
    foreach ([route('carpool.index'), route('carpool.offer', $this->offer), route('carpool.request', $this->ride), route('carpool.chat', $this->chat),
        route('carpool.case', $this->case), route('carpool.reviews', $this->driver), route('carpool.terms')] as $url) {
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'same-origin')
            ->assertSee('<meta name="robots" content="noindex', false);
        expect($this->get($url)->headers->get('Cache-Control'))->toContain('no-store');
    }
    Sanctum::actingAs($this->passenger->fresh());
    foreach (['/api/v1/carpool/me', '/api/v1/carpool/chats/'.$this->chat->id, '/api/v1/carpool/requests/'.$this->ride->id] as $path) {
        $this->getJson($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Vary', 'Cookie, Authorization');
    }
});

it('chi apre da ospite il collegamento di un avviso passa dall\'accesso e torna alla pagina del passaggio', function (): void {
    $url = $this->driver->notifications()->where('type', 'carpool.requested')->firstOrFail()->data['url'];
    expect($url)->toBe(route('carpool.request', $this->ride));
    flussoCpGuest();

    $this->get($url)->assertRedirect(route('login'));
    $this->post(route('account.login.store'), ['email' => $this->driver->email, 'password' => 'password'])->assertRedirect($url);
    $this->get($url)->assertOk()->assertSee('data-request-person', false);
});
