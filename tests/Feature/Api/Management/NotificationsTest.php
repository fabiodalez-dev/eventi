<?php

declare(strict_types=1);

use App\DTOs\NotificationMessage;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\VenueStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Management\ManagementNoticeTarget;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * Notifiche e chiusura del giro (P12): gli avvisi a chi gestisce portano,
 * accanto all'`url` dell'email, il soggetto e la schermata della gestione da
 * aprire nell'app; l'area «Notifiche» del soggetto li elenca; lo slug di un
 * evento del pannello si traduce nel suo id; l'interruttore del rapporto
 * mensile compare a chi gestisce un locale, come sul sito.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    $this->target = app(ManagementNoticeTarget::class);
});

function p12Organizer(VenueIsolationScenario $s, ?User $owner = null): Organizer
{
    return Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => ($owner ?? User::factory()->create())->id, 'is_active' => true]);
}

function p12Message(NotificationType $type, ?Event $event = null, ?int $venueId = null): NotificationMessage
{
    return new NotificationMessage(
        type: $type,
        subject: 'Oggetto',
        heading: 'Titolo dell’avviso',
        lines: ['Una riga'],
        actionLabel: 'Apri',
        url: 'https://example.test/eventi/qualcosa',
        eventId: $event?->id,
        venueId: $venueId,
    );
}

// ------------------------------------------------------------------ bersaglio

it('porta chi gestisce il locale alla scheda dell evento pubblicato o rifiutato', function (): void {
    foreach ([NotificationType::EventPublished, NotificationType::EventRejected] as $type) {
        expect($this->target->for($this->s->editorA, $type, $this->s->publishedEventA->id))
            ->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'event', 'id' => $this->s->publishedEventA->id]);
    }
});

it('non porta nessuno dentro un locale che non gestisce o che è sospeso', function (): void {
    expect($this->target->for($this->s->ownerB, NotificationType::EventPublished, $this->s->publishedEventA->id))->toBeNull()
        ->and($this->target->for($this->s->plainUser, NotificationType::EventNewComment, $this->s->publishedEventA->id))->toBeNull()
        ->and($this->target->for($this->s->ownerA, NotificationType::EventPublished, null))->toBeNull()
        ->and($this->target->for($this->s->ownerA, NotificationType::EventReminder, $this->s->publishedEventA->id))->toBeNull();

    $this->s->venueA->update(['status' => VenueStatus::Suspended]);
    expect($this->target->for($this->s->ownerA->fresh(), NotificationType::EventPublished, $this->s->publishedEventA->id))->toBeNull();
});

it('porta l organizzazione all evento, e ai commenti solo se è la principale', function (): void {
    $member = User::factory()->create();
    $organizer = p12Organizer($this->s);
    $organizer->users()->attach($member);
    $event = $this->s->publishedEventB;
    $event->update(['organizer_id' => $organizer->id]);

    expect($this->target->for($member, NotificationType::EventRejected, $event->id))
        ->toBe(['subject_type' => 'organizer', 'subject_id' => $organizer->id, 'area' => 'event', 'id' => $event->id])
        ->and($this->target->for($member, NotificationType::EventNewComment, $event->id))
        ->toBe(['subject_type' => 'organizer', 'subject_id' => $organizer->id, 'area' => 'comments', 'id' => $event->id]);

    $coHost = p12Organizer($this->s);
    $other = $this->s->publishedEventA;
    $other->organizers()->attach($coHost->id);

    expect($this->target->for($coHost->owner, NotificationType::EventPublished, $other->id))
        ->toBe(['subject_type' => 'organizer', 'subject_id' => $coHost->id, 'area' => 'event', 'id' => $other->id])
        ->and($this->target->for($coHost->owner, NotificationType::EventNewComment, $other->id))->toBeNull();
});

it('porta il referente alla pagina del locale fermo da tempo', function (): void {
    expect($this->target->for($this->s->ownerA, NotificationType::VenueInactive, venueId: $this->s->venueA->id))
        ->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'home', 'id' => null])
        ->and($this->target->for($this->s->ownerB, NotificationType::VenueInactive, venueId: $this->s->venueA->id))->toBeNull();
});

// ------------------------------------------------------------------ canali

it('scrive il bersaglio nell archivio e nella push, e lascia l url dell email com è', function (): void {
    $message = p12Message(NotificationType::EventPublished, $this->s->publishedEventA);
    $notification = new ScheduledMessage($message, NotificationChannel::Database);

    $this->s->ownerA->notifyNow($notification);

    $row = $this->s->ownerA->notifications()->firstOrFail();
    expect($row->data['url'])->toBe('https://example.test/eventi/qualcosa')
        ->and($row->data['management'])->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'event', 'id' => $this->s->publishedEventA->id]);

    $data = $notification->toFcm($this->s->ownerA)->toArray()['data'];
    expect($data['url'])->toBe('https://example.test/eventi/qualcosa')
        ->and($data['management_subject_type'])->toBe('venue')
        ->and($data['management_subject_id'])->toBe((string) $this->s->venueA->id)
        ->and($data['management_area'])->toBe('event')
        ->and($data['management_id'])->toBe((string) $this->s->publishedEventA->id);

    $home = (new ScheduledMessage(p12Message(NotificationType::VenueInactive, venueId: $this->s->venueA->id)))->toFcm($this->s->ownerA)->toArray()['data'];
    expect($home['management_area'])->toBe('home')->and($home['management_id'])->toBe('');
});

it('non aggiunge niente agli avvisi di chi non gestisce', function (): void {
    $reminder = new ScheduledMessage(p12Message(NotificationType::EventReminder, $this->s->publishedEventA));
    $stranger = new ScheduledMessage(p12Message(NotificationType::EventPublished, $this->s->publishedEventA));

    expect($reminder->toArray($this->s->ownerA))->not->toHaveKey('management')
        ->and($reminder->toFcm($this->s->ownerA)->toArray()['data'])->not->toHaveKey('management_area')
        ->and($stranger->toArray($this->s->ownerB))->not->toHaveKey('management');
});

// ------------------------------------------------------------------ area «Notifiche»

it('elenca gli avvisi del solo soggetto aperto, con la forma della casella dell account', function (): void {
    $this->s->ownerA->notifyNow(new ScheduledMessage(p12Message(NotificationType::EventPublished, $this->s->publishedEventA), NotificationChannel::Database));
    $this->s->ownerA->notifyNow(new ScheduledMessage(p12Message(NotificationType::EventReminder, $this->s->publishedEventA), NotificationChannel::Database));
    $this->s->ownerB->notifyNow(new ScheduledMessage(p12Message(NotificationType::EventPublished, $this->s->publishedEventB), NotificationChannel::Database));
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson('/api/v1/management/venues/'.$this->s->venueA->id.'/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'event_published')
        ->assertJsonPath('data.0.data.title', 'Titolo dell’avviso')
        ->assertJsonPath('data.0.data.management.area', 'event')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread_count', 1);

    $this->getJson('/api/v1/management/venues/'.$this->s->venueB->id.'/notifications')->assertNotFound();
});

it('elenca gli avvisi dell organizzazione nella sua area', function (): void {
    $organizer = p12Organizer($this->s);
    $event = $this->s->publishedEventB;
    $event->update(['organizer_id' => $organizer->id]);
    $organizer->owner->notifyNow(new ScheduledMessage(p12Message(NotificationType::EventNewComment, $event), NotificationChannel::Database));
    Sanctum::actingAs($organizer->owner);

    $this->getJson('/api/v1/management/organizers/'.$organizer->id.'/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.management.subject_type', 'organizer')
        ->assertJsonPath('data.0.data.management.area', 'comments');
});

// ------------------------------------------------------------------ slug del pannello

it('traduce lo slug di un evento del soggetto nel suo id, e nient altro', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $base = '/api/v1/management/venues/'.$this->s->venueA->id;

    $this->getJson($base.'/events/by-slug/'.$this->s->draftEventA->slug)->assertOk()
        ->assertJsonPath('data.id', $this->s->draftEventA->id)
        ->assertJsonPath('data.title', 'Bozza del circolo Aurora');
    $this->getJson($base.'/events/by-slug/'.$this->s->publishedEventB->slug)->assertNotFound();
    $this->getJson($base.'/events/by-slug/non-esiste')->assertNotFound();
});

// ------------------------------------------------------------------ preferenze

it('dice all app se mostrare l interruttore del rapporto mensile, come la pagina del sito', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $this->getJson('/api/v1/me/notification-preferences')->assertOk()
        ->assertJsonPath('data.venue_report_available', true)
        ->assertJsonPath('data.venue_report', true);

    $this->patchJson('/api/v1/me/notification-preferences', ['venue_report' => false])->assertOk()
        ->assertJsonPath('data.venue_report', false);

    Sanctum::actingAs($this->s->plainUser);
    $this->getJson('/api/v1/me/notification-preferences')->assertOk()
        ->assertJsonPath('data.venue_report_available', false);
});
