<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\SeoOverview;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Venue;
use App\Services\Editorial\EditorialEventForm;
use App\Services\Management\ManagementOptions;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\ImageFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * Seconda revisione di sicurezza della redazione nell'app, la parte di
 * eventi, date e SEO: le azioni che fermano un evento o le sue date chiedono
 * la password recente come «Annulla evento» ed «Elimina data», il codice di
 * Search Console la chiede e va nel registro, i locali nel cestino o di
 * un'altra città non si legano, galleria e date del modulo hanno un tetto,
 * le regole di ricorrenza troppo fitte o illeggibili si rifiutano con un
 * messaggio invece di un 500.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

/** Una prenotazione confermata sulla data. */
function securityBooking(EventOccurrence $date, VenueIsolationScenario $s): Booking
{
    return Booking::query()->create([
        'occurrence_id' => $date->id, 'user_id' => $s->plainUser->id, 'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('a', 64), 'status' => BookingStatus::Confirmed,
    ]);
}

/** Una data futura dell'evento, fuori da ogni serie. */
function securityDate(Event $event, int $days = 7): EventOccurrence
{
    return EventOccurrence::factory()->create([
        'event_id' => $event->id, 'starts_at' => CarbonImmutable::now('UTC')->addDays($days), 'ends_at' => null, 'doors_at' => null,
        'status' => OccurrenceStatus::Scheduled,
    ]);
}

it('chiede la password recente per annullare, archiviare o rifiutare un evento dal modulo', function (): void {
    $event = $this->s->publishedEventA;
    $url = '/api/v1/editorial/events/'.$event->id;
    $stale = EditorialSession::token($this->s->admin, passwordMinutesAgo: 16);

    foreach (['cancelled', 'archived', 'rejected'] as $status) {
        EditorialSession::as($this, $stale)->patchJson($url, ['status' => $status])
            ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
        expect($event->fresh()->status)->toBe(EventStatus::Published);
    }
    // Le altre modifiche, e lo stesso stato di prima, non la chiedono.
    EditorialSession::as($this, $stale)->patchJson($url, ['title' => 'Titolo nuovo', 'status' => 'published'])->assertOk();
    // Il moderatore resta fuori prima della password (`EventPolicy::update`).
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 16))->patchJson($url, ['status' => 'cancelled'])->assertForbidden();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, ['status' => 'cancelled'])->assertOk()
        ->assertJsonPath('data.status.value', 'cancelled');
    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
});

it('chiede la password recente per annullare la serie o una data con prenotazioni, anche dal PATCH', function (): void {
    $event = $this->s->draftEventA;
    $base = '/api/v1/editorial/events/'.$event->id.'/dates/';
    $stale = EditorialSession::token($this->s->admin, passwordMinutesAgo: 16);
    $free = securityDate($event, 5);
    $booked = securityDate($event, 9);
    $booking = securityBooking($booked, $this->s);

    // La serie da qui in avanti: sempre.
    EditorialSession::as($this, $stale)->postJson($base.$free->id.'/status', ['action' => 'cancel', 'scope' => 'series'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    // Una data con prenotazioni attive: anche da sola.
    EditorialSession::as($this, $stale)->postJson($base.$booked->id.'/status', ['action' => 'cancel', 'scope' => 'single'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    EditorialSession::as($this, $stale)->patchJson($base.$booked->id, ['status' => 'cancelled'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect($booked->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // Senza prenotazioni la sola data si annulla come prima, e il resto del PATCH non la chiede.
    EditorialSession::as($this, $stale)->postJson($base.$free->id.'/status', ['action' => 'cancel', 'scope' => 'single'])->assertOk()
        ->assertJsonPath('data.status.value', 'cancelled');
    EditorialSession::as($this, $stale)->patchJson($base.$booked->id, ['highlight' => 'Ultimi posti'])->assertOk();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($base.$booked->id, ['status' => 'cancelled'])->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('chiede la password recente per il codice di Search Console e scrive il registro anche dal pannello', function (): void {
    $city = $this->s->city;
    $city->update(['seo' => ['google_verification' => 'vecchio-codice']]);
    $url = '/api/v1/editorial/seo/verification';

    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->putJson($url, ['city_id' => $city->id, 'verification' => 'codice-mio'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect($city->fresh()->seo['google_verification'])->toBe('vecchio-codice');

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->putJson($url, ['city_id' => $city->id, 'verification' => 'codice-nuovo'])->assertOk();
    $log = Activity::query()->where('log_name', 'sicurezza')->where('description', 'search_console_codice_cambiato')->latest('id')->firstOrFail();
    expect($log->getProperty('codice_precedente'))->toBe('vecchio-codice')
        ->and($log->getProperty('codice_nuovo'))->toBe('codice-nuovo')
        ->and($log->getProperty('citta_id'))->toBe($city->id)
        ->and($log->getProperty('canale'))->toBe('app')
        ->and($log->causer_id)->toBe($this->s->admin->id);

    // Dal pannello: stesso registro, senza canale dell'app.
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->superAdmin, 'web');
    Filament::setCurrentPanel('admin');
    $this->get(SeoOverview::getUrl(panel: 'admin'))->assertOk();
    Livewire::test(SeoOverview::class)->fillForm(['city_id' => $city->id, 'verification' => 'dal-pannello'])->call('save')->assertHasNoFormErrors();
    $panel = Activity::query()->where('description', 'search_console_codice_cambiato')->latest('id')->firstOrFail();
    expect($panel->getProperty('codice_precedente'))->toBe('codice-nuovo')
        ->and($panel->getProperty('codice_nuovo'))->toBe('dal-pannello')
        ->and($panel->getProperty('canale'))->toBeNull()
        ->and($panel->causer_id)->toBe($this->superAdmin->id);
});

it('non lega un evento o una data a un locale nel cestino o di un\'altra città', function (): void {
    $event = $this->s->draftEventA;
    $token = EditorialSession::token($this->s->admin);
    $trashed = Venue::factory()->create(['city_id' => $this->s->city->id]);
    $trashed->delete();
    $elsewhere = Venue::factory()->create(['city_id' => City::factory()->create()->id]);

    EditorialSession::as($this, $token)->patchJson('/api/v1/editorial/events/'.$event->id, ['venue_id' => $trashed->id])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['venue_id']]]);

    $date = securityDate($event);
    $url = '/api/v1/editorial/events/'.$event->id.'/dates/'.$date->id;
    foreach ([$trashed, $elsewhere] as $venue) {
        EditorialSession::as($this, $token)->patchJson($url, ['venue_id' => $venue->id])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['venue_id']]]);
    }
    EditorialSession::as($this, $token)->patchJson($url, ['venue_id' => $this->s->venueB->id])->assertOk()
        ->assertJsonPath('data.venue.id', $this->s->venueB->id);

    // Il campo del pannello offre solo i locali approvati della città dell'evento.
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/'.$event->getRouteKey().'/edit')->assertOk();
    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->mountTableAction('edit', $date)
        ->assertFormFieldExists('venue_id', checkFieldUsing: function ($field) use ($elsewhere): bool {
            $options = $field->getSearchResults($elsewhere->name);

            return ! array_key_exists($elsewhere->id, $options);
        });
});

it('ferma la galleria dell\'evento al tetto della gestione', function (): void {
    $disk = config()->string('media-library.disk_name');
    Storage::fake($disk);
    $event = $this->s->draftEventA;
    foreach (range(1, ManagementOptions::GALLERY_MAX) as $i) {
        $event->media()->create([
            'uuid' => (string) Str::uuid(), 'collection_name' => 'gallery', 'name' => 'foto'.$i, 'file_name' => 'foto'.$i.'.jpg',
            'mime_type' => 'image/jpeg', 'disk' => $disk, 'conversions_disk' => $disk, 'size' => 10, 'manipulations' => [],
            'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [], 'order_column' => $i,
        ]);
    }

    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->postJson('/api/v1/editorial/events/'.$event->id.'/gallery', ['image' => ImageFixtures::upload('foto.png', ImageFixtures::png(400, 500))])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['image']]]);
    expect($event->fresh()->getMedia('gallery'))->toHaveCount(ManagementOptions::GALLERY_MAX);
});

it('manda nel modulo solo le date non finite, al massimo cento, e dice se ne ha tagliate', function (): void {
    $event = $this->s->draftEventA;
    $past = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::now('UTC')->subMonth(), 'ends_at' => null, 'doors_at' => null]);
    $url = '/api/v1/editorial/events/'.$event->id.'/form';
    $token = EditorialSession::token($this->s->admin);

    $refs = EditorialSession::as($this, $token)->getJson($url)->assertOk()->assertJsonPath('data.event.date_refs_meta.truncated', false)->json('data.event.date_refs');
    expect(collect($refs)->pluck('id'))->not->toContain($past->id);

    $start = CarbonImmutable::now('UTC')->addDay();
    EventOccurrence::factory()->count(EditorialEventForm::DATE_REFS_MAX + 5)
        ->sequence(fn ($sequence): array => ['starts_at' => $start->addDays($sequence->index + 1), 'ends_at' => null, 'doors_at' => null])
        ->create(['event_id' => $event->id, 'status' => OccurrenceStatus::Scheduled]);

    EditorialSession::as($this, $token)->getJson($url)->assertOk()
        ->assertJsonCount(EditorialEventForm::DATE_REFS_MAX, 'data.event.date_refs')
        ->assertJsonPath('data.event.date_refs_meta.truncated', true)
        ->assertJsonPath('data.event.date_refs_meta.limit', EditorialEventForm::DATE_REFS_MAX);
});

it('rifiuta le ricorrenze più fitte di un giorno o illeggibili, nell\'app e nel pannello', function (): void {
    $event = $this->s->draftEventA;
    $token = EditorialSession::token($this->s->admin);
    $url = '/api/v1/editorial/events/'.$event->id;

    foreach (['FREQ=MINUTELY', 'FREQ=HOURLY;INTERVAL=2', 'FREQ=DAILY;BYHOUR=0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23', 'non è una regola'] as $rrule) {
        EditorialSession::as($this, $token)->patchJson($url, ['recurrences' => [['rrule' => $rrule]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['recurrences.0.rrule']]]);
    }
    expect($event->recurrences()->count())->toBe(0);
    EditorialSession::as($this, $token)->patchJson($url, ['recurrences' => [['rrule' => 'FREQ=WEEKLY;BYDAY=TH;COUNT=10']]])->assertOk();
    expect(RecurrenceRule::problem('FREQ=DAILY'))->toBeNull();

    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/'.$event->getRouteKey().'/edit')->assertOk();
    $form = Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()]);
    $key = array_key_first($form->get('data.recurrences'));
    $form->set('data.recurrences.'.$key.'.rrule', 'FREQ=MINUTELY')->call('save')->assertHasFormErrors(['recurrences.'.$key.'.rrule']);
});

it('dà un messaggio chiaro invece di un 500 su «Genera date» con una regola salvata prima dei controlli', function (): void {
    $event = $this->s->draftEventA;
    securityDate($event, 2);
    EventRecurrence::query()->create(['event_id' => $event->id, 'rrule' => 'FREQ=MINUTELY', 'until' => null]);
    $before = $event->occurrences()->count();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/events/'.$event->id.'/occurrences/generate')
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['recurrences']]]);
    expect($event->occurrences()->count())->toBe($before);

    EventRecurrence::query()->where('event_id', $event->id)->update(['rrule' => 'BOH=1']);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/events/'.$event->id.'/occurrences/generate')
        ->assertUnprocessable();

    // Il pannello: un avviso, non la pagina d'errore.
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/'.$event->getRouteKey().'/edit')->assertOk();
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])->callAction('generate_occurrences')->assertNotified();
    expect($event->occurrences()->count())->toBe($before);
});
