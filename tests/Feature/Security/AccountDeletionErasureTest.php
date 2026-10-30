<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Models\AvailabilityAlert;
use App\Models\BookingOpeningAlert;
use App\Models\EditionAlert;
use App\Models\EventCollection;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollVote;
use App\Models\PresenceShare;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\RememberedLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * La cancellazione dell'account è un soft delete: le chiavi `cascadeOnDelete`
 * non scattano, e ciò che non si toglie a mano resta. Qui si verifica che
 * spariscano anche nome e cognome, posizione, esigenze di accessibilità,
 * ricerche, raccolte, link «sono qui», avvisi, sondaggi e voti — e che i link
 * condivisi prima smettano di funzionare.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('toglie dati personali e righe collegate quando l account viene cancellato', function (): void {
    $user = User::factory()->create(['first_name' => 'Giulia', 'last_name' => 'Rossi', 'content_preferences' => ['access_needs' => ['step_free']]]);
    app(RememberedLocation::class)->save(45.4064, 11.8768, $user);
    $altro = User::factory()->create();

    SavedSearch::query()->create(['user_id' => $user->id, 'city_id' => $this->city->id, 'name' => 'Jazz', 'filters' => []]);
    $collection = EventCollection::query()->create(['user_id' => $user->id, 'city_id' => $this->city->id, 'name' => 'Le mie serate', 'is_public' => true]);
    $collection->items()->create(['occurrence_id' => $this->occurrence->id, 'note' => 'Nota personalissima']);
    $share = PresenceShare::query()->create(['user_id' => $user->id, 'occurrence_id' => $this->occurrence->id, 'token' => Str::random(32), 'display_name' => 'Giulia', 'expires_at' => now()->addHours(6)]);
    AvailabilityAlert::query()->create(['user_id' => $user->id, 'occurrence_id' => $this->occurrence->id]);
    BookingOpeningAlert::query()->create(['user_id' => $user->id, 'occurrence_id' => $this->occurrence->id]);
    EditionAlert::query()->create(['user_id' => $user->id, 'event_id' => $this->occurrence->event_id]);
    $poll = EventPoll::query()->create(['token' => Str::random(32), 'user_id' => $user->id, 'event_id' => $this->occurrence->event_id, 'closes_at' => now()->addDay()]);
    $option = EventPollOption::query()->create(['event_poll_id' => $poll->id, 'occurrence_id' => $this->occurrence->id]);
    $altroPoll = EventPoll::query()->create(['token' => Str::random(32), 'user_id' => $altro->id, 'event_id' => $this->occurrence->event_id, 'closes_at' => now()->addDay()]);
    $altraOpzione = EventPollOption::query()->create(['event_poll_id' => $altroPoll->id, 'occurrence_id' => $this->occurrence->id]);
    EventPollVote::query()->create(['event_poll_id' => $altroPoll->id, 'event_poll_option_id' => $altraOpzione->id, 'user_id' => $user->id]);
    EventPollVote::query()->create(['event_poll_id' => $altroPoll->id, 'event_poll_option_id' => $altraOpzione->id, 'user_id' => $altro->id]);

    $this->get('/raccolte/'.$collection->token)->assertOk()->assertSee('Nota personalissima');
    $this->get('/sono-qui/'.$share->token)->assertOk();

    app(DeleteAccount::class)($user);

    $erased = User::withTrashed()->findOrFail($user->id);
    expect($erased->first_name)->toBeNull()
        ->and($erased->last_name)->toBeNull()
        ->and($erased->content_preferences)->toBeNull()
        ->and($erased->remembered_location)->toBeNull()
        ->and($erased->location_lat)->toBeNull()
        ->and($erased->location_lng)->toBeNull()
        ->and($erased->location_expires_at)->toBeNull();

    foreach (['saved_searches', 'event_collections', 'presence_shares', 'availability_alerts', 'booking_opening_alerts', 'edition_alerts', 'event_polls', 'event_poll_votes'] as $table) {
        $this->assertDatabaseMissing($table, ['user_id' => $user->id]);
    }
    $this->assertDatabaseMissing('event_collection_items', ['collection_id' => $collection->id]);
    $this->assertDatabaseMissing('event_poll_options', ['id' => $option->id]);

    /* I dati degli altri restano: il loro sondaggio e il loro voto. */
    $this->assertDatabaseHas('event_polls', ['id' => $altroPoll->id]);
    $this->assertDatabaseHas('event_poll_votes', ['user_id' => $altro->id]);

    $this->get('/raccolte/'.$collection->token)->assertNotFound();
    $this->getJson('/api/v1/collections/'.$collection->token)->assertNotFound();
    $this->get('/sono-qui/'.$share->token)->assertNotFound();
});

it('non apre la raccolta pubblica né il link sono qui di un account già cestinato', function (): void {
    /* Account cancellati prima della correzione: le righe sono rimaste. */
    $user = User::factory()->create();
    $collection = EventCollection::query()->create(['user_id' => $user->id, 'city_id' => $this->city->id, 'name' => 'Vecchia raccolta', 'is_public' => true]);
    $share = PresenceShare::query()->create(['user_id' => $user->id, 'occurrence_id' => $this->occurrence->id, 'token' => Str::random(32), 'display_name' => 'Giulia', 'expires_at' => now()->addHours(6)]);
    $user->delete();

    $this->get('/raccolte/'.$collection->token)->assertNotFound();
    $this->get('/sono-qui/'.$share->token)->assertNotFound();
});
