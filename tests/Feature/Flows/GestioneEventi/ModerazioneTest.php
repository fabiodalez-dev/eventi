<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Actions\Account\FollowSubject;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Filament\Admin\Resources\Events\Pages\ListEvents as AdminListEvents;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge il passaggio dalla redazione per i locali che non pubblicano da
 * sé: l'invio in revisione, l'approvazione e il rifiuto con il motivo, la
 * correzione e il nuovo invio, il ritiro di un evento già online. Il punto
 * fermo è uno: finché la redazione non dice sì, il pubblico non vede niente,
 * e lo staff del locale sa sempre com'è andata.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->venue->update(['auto_publish' => false]);
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Gli id di chi ha in coda un avviso di quel tipo per quell'evento. */
function geAvvisati(NotificationType $type, Event $event): array
{
    return ScheduledNotification::query()->ofType($type->value)
        ->where('notifiable_id', $event->id)
        ->pluck('user_id')->sort()->values()->all();
}

it('manda in redazione l’evento di un locale senza pubblicazione autonoma, che va online solo dopo l’approvazione', function (): void {
    $seguace = User::factory()->create();
    app(FollowSubject::class)($seguace, FollowableType::Venue, $this->venue->id);

    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Jam session del mercoledì']))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(__('manage.notifications.sent_to_review'));

    $evento = Event::query()->where('title', 'Jam session del mercoledì')->sole();
    expect($evento->status)->toBe(EventStatus::Pending)
        ->and($evento->published_at)->toBeNull()
        // Nessuno è stato avvisato di un evento che ancora non esiste per il pubblico.
        ->and(geAvvisati(NotificationType::VenueNewEvent, $evento))->toBe([])
        ->and(geAvvisati(NotificationType::EventPublished, $evento))->toBe([]);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();
    $this->get('/eventi/2026-09-17')->assertOk()->assertDontSee('Jam session del mercoledì');

    geRedazione($this->scenario->admin, $evento)->callAction('publish')->assertHasNoActionErrors();

    $evento->refresh();
    expect($evento->status)->toBe(EventStatus::Published)
        ->and($evento->published_at)->not->toBeNull()
        ->and(geAvvisati(NotificationType::EventPublished, $evento))->toBe(collect([$this->owner->id, $this->scenario->editorA->id])->sort()->values()->all())
        ->and(geAvvisati(NotificationType::VenueNewEvent, $evento))->toBe([$seguace->id]);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Jam session del mercoledì');
    $this->get('/eventi/2026-09-17')->assertOk()->assertSee('Jam session del mercoledì');
});

it('fa arrivare il rifiuto allo staff del locale con il motivo, anche nel pannello', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Serata senza dettagli']))
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Serata senza dettagli')->sole();

    geRedazione($this->scenario->admin, $evento)
        ->callAction('reject', data: ['reason' => 'Manca il programma della serata'])
        ->assertHasNoActionErrors();

    $evento->refresh();
    expect($evento->status)->toBe(EventStatus::Rejected)
        ->and($evento->rejection_reason)->toBe('Manca il programma della serata')
        ->and(geAvvisati(NotificationType::EventRejected, $evento))->toBe(collect([$this->owner->id, $this->scenario->editorA->id])->sort()->values()->all());

    // Il gestore apre la scheda e legge perché.
    geFuoriDalPannello();
    $this->actingAs($this->owner)->get('/gestione/'.$this->venue->slug.'/eventi/'.$evento->getRouteKey())
        ->assertOk()
        ->assertSee(EventStatus::Rejected->label())
        ->assertSee('Manca il programma della serata');

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();
});

it('lascia correggere e rimandare un evento rifiutato, e l’approvazione toglie il motivo', function (): void {
    $evento = $this->scenario->draftEventA;
    geData($evento, '2026-09-20 21:00');
    $evento->update(['status' => EventStatus::Pending]);
    geRedazione($this->scenario->admin, $evento)->callAction('reject', data: ['reason' => 'Titolo poco chiaro']);
    expect($evento->fresh()->status)->toBe(EventStatus::Rejected);

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['title' => 'Concerto per pianoforte solo'])
        ->call('save')
        ->assertHasNoFormErrors();
    // Correggere non rimanda da solo: resta rifiutato finché il gestore non lo ripropone.
    expect($evento->fresh()->status)->toBe(EventStatus::Rejected);

    // Un evento rifiutato non si pubblica da sé, nemmeno con la pubblicazione autonoma accesa nel frattempo.
    $this->venue->update(['auto_publish' => true]);
    Livewire::test(ViewEvent::class, ['record' => $evento->getRouteKey()])
        ->callAction('publish')
        ->assertNotified(__('manage.notifications.sent_to_review'));
    expect($evento->fresh()->status)->toBe(EventStatus::Pending);

    geRedazione($this->scenario->admin, $evento)->callAction('publish');
    $evento->refresh();
    expect($evento->status)->toBe(EventStatus::Published)
        ->and($evento->rejection_reason)->toBeNull()
        ->and($evento->title)->toBe('Concerto per pianoforte solo');

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Concerto per pianoforte solo');
});

it('rimanda in redazione un testo nuovo ma non una correzione di prezzo o categoria', function (): void {
    $evento = $this->scenario->publishedEventA;
    $pubblicatoIl = $evento->published_at;
    $altra = testCategory(['name' => 'Jazz e blues']);

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['category_id' => $altra->id, 'price_type' => PriceType::Ticket->value, 'price_min' => '8'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($evento->fresh()->status)->toBe(EventStatus::Published)
        ->and($evento->fresh()->category_id)->toBe($altra->id);

    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->fillForm(['description' => '<p>Ospite a sorpresa: un trio dalla Sardegna.</p>'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('manage.notifications.sent_to_review'));
    expect($evento->fresh()->status)->toBe(EventStatus::Pending);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();

    geRedazione($this->scenario->admin, $evento)->callAction('publish');
    $evento->refresh();
    expect($evento->status)->toBe(EventStatus::Published)
        // La data della prima pubblicazione resta quella: non è un evento nuovo.
        ->and($evento->published_at->equalTo($pubblicatoIl))->toBeTrue();

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Ospite a sorpresa: un trio dalla Sardegna.');
});

it('ritira dalla redazione un evento online: torna bozza, sparisce dal sito e il gestore lo ritrova', function (): void {
    $evento = $this->scenario->publishedEventA;

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk();
    $this->get('/eventi/2026-09-11')->assertOk()->assertSee($evento->title);

    geRedazione($this->scenario->admin, $evento)->callAction('unpublish');
    expect($evento->fresh()->status)->toBe(EventStatus::Draft);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();
    $this->get('/eventi/2026-09-11')->assertOk()->assertDontSee($evento->title);
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertDontSee($evento->title);

    geEntra($this->owner, $this->venue, '/eventi');
    $this->get(EventResource::getUrl('view', ['record' => $evento]))->assertOk()->assertSee(EventStatus::Draft->label());
});

it('annulla dalla redazione un evento intero: le date future si chiudono, le passate restano', function (): void {
    $evento = $this->scenario->publishedEventA;
    $passata = geData($evento, '2026-09-05 21:00');
    $futura = geData($evento, '2026-09-25 21:00');

    geRedazione($this->scenario->admin, $evento)
        ->callAction('cancel_event', data: ['note' => 'Maltempo, recupero da definire'])
        ->assertHasNoActionErrors();

    expect($evento->fresh()->status)->toBe(EventStatus::Cancelled)
        ->and($this->scenario->occurrenceA->fresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($futura->fresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($futura->fresh()->status_note)->toBe('Maltempo, recupero da definire')
        ->and($passata->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    // Nel pannello del locale l'evento annullato non si ripubblica con un tocco.
    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey());
    Livewire::test(ViewEvent::class, ['record' => $evento->getRouteKey()])->assertActionHidden('publish');

    geSitoPubblico();
    $this->get('/eventi/2026-09-25')->assertOk()->assertDontSee($evento->title);
});

it('non lascia al gestore scavalcare la redazione forzando lo stato dal modulo', function (): void {
    $evento = $this->scenario->draftEventA;
    geData($evento, '2026-09-20 21:00');

    // Il pannello di redazione gli è chiuso.
    $this->actingAs($this->owner)->get('/admin/events/'.$evento->getRouteKey().'/edit')->assertForbidden();

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $evento->getRouteKey()])
        ->set('data.status', EventStatus::Published->value)
        ->set('data.published_at', '2026-09-01 10:00:00')
        ->set('data.is_featured', true)
        ->fillForm(['title' => 'Bozza che vorrebbe uscire da sola'])
        ->call('save');

    $evento->refresh();
    expect($evento->status)->toBe(EventStatus::Draft)
        ->and($evento->published_at)->toBeNull()
        ->and($evento->is_featured)->toBeFalse()
        ->and($evento->title)->toBe('Bozza che vorrebbe uscire da sola');

    // Il pulsante del pannello lo manda in coda, non online.
    Livewire::test(ViewEvent::class, ['record' => $evento->getRouteKey()])->callAction('publish');
    expect($evento->fresh()->status)->toBe(EventStatus::Pending);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();
});

it('mette in coda alla redazione le proposte del locale, che le ritrova filtrando', function (): void {
    $this->actingAs($this->scenario->editorA);
    geEntra($this->scenario->editorA, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Proposta della collaboratrice']))
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Proposta della collaboratrice')->sole();
    expect($evento->created_by)->toBe($this->scenario->editorA->id);

    Filament::setTenant(null);
    $this->actingAs($this->scenario->admin)->get('/admin/events')->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminListEvents::class)
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$evento])
        ->assertCanNotSeeTableRecords([$this->scenario->publishedEventA]);

    geRedazione($this->scenario->admin, $evento)->callAction('publish');
    expect(geAvvisati(NotificationType::EventPublished, $evento))
        ->toBe(collect([$this->owner->id, $this->scenario->editorA->id])->sort()->values()->all());
});
