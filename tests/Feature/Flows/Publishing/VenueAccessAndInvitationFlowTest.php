<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\ContactEmailConfirmationRequested;
use App\Notifications\VenueAccessGranted;
use App\Services\Contact\ContactEmailConfirmation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Chi può toccare che cosa: l'isolamento fra due locali, l'anteprima delle
 * bozze, gli inviti dello staff e la conferma del recapito dei contatti.
 * Sono i flussi in cui una persona arriva da un collegamento — scritto a
 * mano, girato da altri, aperto da uno scanner di posta — e il sistema deve
 * capire chi è prima di fare qualcosa.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    Notification::fake();
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Il referente invita qualcuno dalla pagina dei collaboratori. */
function pubInvitaDallaPagina(User $referente, Venue $venue, string $email, string $nome = 'Persona Invitata'): void
{
    test()->actingAs($referente)->get(Collaborators::getUrl(panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => $nome, 'email' => $email])->assertHasNoTableActionErrors();
}

/** Il collegamento «Accetta l'invito» arrivato nella casella della persona. */
function pubCollegamentoInvito(User $invitato): string
{
    $url = null;
    Notification::assertSentTo($invitato, VenueAccessGranted::class, function (VenueAccessGranted $n) use ($invitato, &$url): bool {
        $url = $n->toMail($invitato)->actionUrl;

        return true;
    });

    return (string) $url;
}

it('impedisce al gestore di un locale di raggiungere gli eventi di un altro, anche scrivendo l’indirizzo', function (): void {
    $s = $this->scenario;

    // Dal proprio locale, l'evento proprio si apre.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/eventi/'.$s->publishedEventA->getRouteKey().'/modifica')->assertOk();

    // Dal proprio locale, l'evento altrui no; e il locale altrui non esiste.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/eventi/'.$s->publishedEventB->getRouteKey().'/modifica')->assertNotFound();
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/eventi/'.$s->draftEventB->getRouteKey().'/modifica')->assertNotFound();
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueB->slug.'/eventi')->assertNotFound();
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueB->slug.'/eventi/nuovo')->assertNotFound();

    // L'elenco mostra solo il proprio cartellone.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/eventi')->assertOk()
        ->assertSee($s->draftEventA->title)
        ->assertDontSee($s->publishedEventB->title)
        ->assertDontSee($s->draftEventB->title);

    // Nemmeno l'anteprima della bozza altrui. (Lo scope di tenancy di Filament,
    // rimasto attivo nel processo di test dopo le GET su /gestione, va tolto:
    // in produzione ogni richiesta parte pulita.)
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    $this->actingAs($s->ownerA)->get(route('events.preview', $s->draftEventB))->assertForbidden();

    // E le Policy dicono di no a ogni gesto sul locale B, letto dalla chiave grezza.
    $eventoB = Event::query()->findOrFail($s->publishedEventB->id);
    $dataB = EventOccurrence::query()->findOrFail($s->occurrenceB->id);
    expect($s->ownerA->can('update', $eventoB))->toBeFalse()
        ->and($s->ownerA->can('delete', $eventoB))->toBeFalse()
        ->and($s->ownerA->can('publish', $s->draftEventB))->toBeFalse()
        ->and($s->ownerA->can('update', $dataB))->toBeFalse()
        ->and($s->ownerA->can('create', [Event::class, $s->venueB]))->toBeFalse()
        ->and($s->ownerA->can('create', [EventOccurrence::class, $eventoB]))->toBeFalse();

    // Un evento creato dal wizard nasce sempre nel locale corrente, anche con un venue_id forzato.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/eventi/nuovo')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($s->venueA);
    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Evento con locale forzato', 'starts_at' => '2026-09-20 21:00:00', 'category_id' => $s->category->id, 'price_type' => 'free'])
        ->set('data.venue_id', $s->venueB->id)
        ->call('create');
    expect(Event::query()->where('title', 'Evento con locale forzato')->sole()->venue_id)->toBe($s->venueA->id)
        ->and($s->publishedEventB->fresh()->title)->toBe('Reading su poesia contemporanea');
});

it('mostra l’anteprima di una bozza solo a chi può modificarla, senza indicizzazione né cache', function (): void {
    $bozza = $this->scenario->draftEventA;
    EventOccurrence::factory()->create(['event_id' => $bozza->id, 'starts_at' => CarbonImmutable::parse('2026-09-19 19:00', 'UTC'), 'ends_at' => null, 'doors_at' => null]);
    $url = route('events.preview', $bozza);

    // Il pubblico non vede la bozza, nemmeno con lo slug giusto.
    $this->get('/eventi/'.$bozza->slug)->assertNotFound();
    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs($this->scenario->plainUser)->get($url)->assertForbidden();
    $this->actingAs($this->scenario->ownerB)->get($url)->assertForbidden();

    foreach ([$this->owner, $this->scenario->editorA, $this->scenario->admin] as $chiPuo) {
        $risposta = $this->actingAs($chiPuo)->get($url)->assertOk()->assertSee('Bozza del circolo Aurora');
        expect($risposta->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($risposta->headers->get('Cache-Control'))->toContain('no-store')
            ->and($risposta->headers->get('Cache-Control'))->toContain('private');
    }

    // Guardare l'anteprima non pubblica niente.
    expect($bozza->fresh()->status)->toBe(EventStatus::Draft);
});

it('fa entrare nello staff chi accetta l’invito, che poi crea un evento del locale', function (): void {
    $invitata = User::factory()->create(['name' => 'Giulia Collaboratrice']);
    pubInvitaDallaPagina($this->owner, $this->venue, $invitata->email);

    $pivot = DB::table('venue_user')->where('venue_id', $this->venue->id)->where('user_id', $invitata->id)->first();
    expect($pivot)->not->toBeNull()->and($pivot->accepted_at)->toBeNull()->and($pivot->role)->toBe(VenueRole::Editor->value);
    $url = pubCollegamentoInvito($invitata);
    expect($url)->toContain('/inviti-locali/'.$this->venue->id.'/'.$invitata->id.'/');

    // Prima di accettare il pannello è chiuso.
    $this->actingAs($invitata)->get('/gestione/'.$this->venue->slug)->assertForbidden();

    // La GET mostra la domanda e non decide niente.
    $this->actingAs($invitata)->get($url)->assertOk()->assertSee('Circolo Aurora')->assertSee(__('manage.invitation.accept_action'));
    expect($invitata->fresh()->canAccessTenant($this->venue))->toBeFalse();

    $this->actingAs($invitata)->post($url, ['decision' => 'accept'])
        ->assertRedirect(Filament::getPanel('venue')->getUrl($this->venue));

    $invitata = $invitata->fresh();
    expect($invitata->canAccessTenant($this->venue))->toBeTrue()
        ->and($invitata->hasRole(UserRole::VenueEditor->value))->toBeTrue()
        ->and($invitata->isOwnerOfVenue((int) $this->venue->id))->toBeFalse();

    // Da collaboratrice crea un evento del locale.
    $this->actingAs($invitata)->get('/gestione/'.$this->venue->slug.'/eventi/nuovo')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Aperitivo della collaboratrice', 'starts_at' => '2026-09-22 19:00:00', 'category_id' => $this->scenario->category->id, 'price_type' => 'free'])
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Aperitivo della collaboratrice')->sole();
    expect($evento->venue_id)->toBe($this->venue->id)->and($evento->created_by)->toBe($invitata->id);

    // Ma non gestisce lo staff: quella è del referente.
    $this->actingAs($invitata)->get(Collaborators::getUrl(panel: 'venue', tenant: $this->venue))->assertForbidden();
});

it('lascia rifiutare un invito, e dopo il collegamento non vale più nemmeno per accettare', function (): void {
    $invitato = User::factory()->create();
    pubInvitaDallaPagina($this->owner, $this->venue, $invitato->email);
    $url = pubCollegamentoInvito($invitato);

    $this->actingAs($invitato)->post($url, ['decision' => 'decline'])
        ->assertRedirect(route('account.profile'))
        ->assertSessionHas('status', __('manage.invitation.declined', ['venue' => 'Circolo Aurora']));

    expect(DB::table('venue_user')->where('venue_id', $this->venue->id)->where('user_id', $invitato->id)->exists())->toBeFalse();

    $this->actingAs($invitato)->get($url)->assertForbidden()->assertSee(__('manage.invitation.gone'));
    $this->actingAs($invitato)->post($url, ['decision' => 'accept'])->assertForbidden();
    $this->actingAs($invitato)->post($url, ['decision' => 'boh'])->assertSessionHasErrors('decision');

    expect($invitato->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($invitato->fresh()->hasRole(UserRole::VenueEditor->value))->toBeFalse();
    $this->actingAs($invitato->fresh())->get('/gestione/'.$this->venue->slug)->assertForbidden();
});

it('rifiuta l’invito aperto da un altro account, quello manomesso e chi non è entrato', function (): void {
    $invitata = User::factory()->create(['email' => 'giulia@esempio.test']);
    pubInvitaDallaPagina($this->owner, $this->venue, $invitata->email);
    $url = pubCollegamentoInvito($invitata);

    // Il collegamento girato a un'altra persona: 403, con l'indirizzo giusto mascherato.
    $altro = $this->scenario->plainUser;
    $this->actingAs($altro)->get($url)->assertForbidden()->assertSee('g*****@esempio.test');
    $this->actingAs($altro)->post($url, ['decision' => 'accept'])->assertForbidden();
    // Anche il referente di un altro locale non lo usa.
    $this->actingAs($this->scenario->ownerB)->post($url, ['decision' => 'accept'])->assertForbidden();

    // Collegamento alterato: un altro locale nello stesso percorso.
    $alterato = str_replace('/inviti-locali/'.$this->venue->id.'/', '/inviti-locali/'.$this->scenario->venueB->id.'/', $url);
    $this->actingAs($invitata)->post($alterato, ['decision' => 'accept'])->assertForbidden()->assertSee(__('manage.invitation.invalid'));

    // Senza accesso si passa dalla pagina di login.
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));

    expect($altro->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($this->scenario->ownerB->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($invitata->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($invitata->fresh()->canAccessTenant($this->scenario->venueB))->toBeFalse();
});

it('conferma il recapito dei contatti solo dal pulsante del collegamento firmato, per locali e organizzazioni', function (): void {
    // Il referente imposta un recapito che non è la sua email.
    $this->actingAs($this->owner)->get(VenueProfile::getUrl(panel: 'venue', tenant: $this->venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(VenueProfile::class)
        ->fillForm(['address' => 'Via Portello 1', 'municipality' => 'Padova', 'zone' => 'Portello', 'contact_mode' => 'members', 'contact_email' => 'info@aurora.example'])
        ->call('save')
        ->assertHasNoErrors();

    $collegamento = null;
    Notification::assertSentOnDemand(ContactEmailConfirmationRequested::class, function (ContactEmailConfirmationRequested $n, array $c, AnonymousNotifiable $chi) use (&$collegamento): bool {
        $collegamento = $n->url;

        return ($chi->routes['mail'] ?? null) === 'info@aurora.example';
    });
    expect($collegamento)->toContain('/conferma-recapito/venues/'.$this->venue->id)
        ->and($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();
    auth()->logout();

    // Senza firma, niente.
    $nonFirmato = strtok((string) $collegamento, '?').'?email='.ContactEmailConfirmation::fingerprint('info@aurora.example');
    $this->get($nonFirmato)->assertForbidden();
    $this->post($nonFirmato)->assertForbidden();

    // La GET mostra il pulsante: gli scanner di posta non confermano al posto della persona.
    $this->get($collegamento)->assertOk()->assertSee(__('contact.confirmation.page_submit'));
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();

    $this->post($collegamento)->assertOk()->assertSee(__('contact.confirmation.page_confirmed', ['name' => 'Circolo Aurora']));
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue()
        ->and($this->venue->fresh()->contact_email_verified_at)->not->toBeNull();

    // Lo stesso percorso per un'organizzazione; un locale inesistente è 404.
    $organizzazione = Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $this->owner->id, 'city_id' => $this->scenario->city->id,
        'is_active' => true, 'contact_mode' => ContactMode::Members, 'contact_email' => 'posta@portici.example']);
    $link = app(ContactEmailConfirmation::class)->url($organizzazione->fresh());
    expect($link)->toContain('/conferma-recapito/organizers/'.$organizzazione->id);
    $this->get($link)->assertOk();
    $this->post($link)->assertOk();
    expect($organizzazione->fresh()->hasConfirmedContactEmail())->toBeTrue();

    $inesistente = URL::temporarySignedRoute('contact-email.confirm', now()->addDay(), ['type' => 'venues', 'id' => 99999999, 'email' => 'x']);
    $this->get($inesistente)->assertNotFound();
});
