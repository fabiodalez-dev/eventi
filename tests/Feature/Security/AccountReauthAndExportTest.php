<?php

declare(strict_types=1);

use App\Models\AvailabilityAlert;
use App\Models\ConsentLog;
use App\Models\EventCollection;
use App\Models\PresenceShare;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Services\RememberedLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Cancellazione dal sito con la password attuale (come nell'API), scarico dei
 * dati con un limite di frequenza, e scarico che contiene davvero ciò che la
 * persona ha dato.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->user = User::factory()->create(['first_name' => 'Giulia', 'last_name' => 'Rossi']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('non cancella l account dal sito senza la password attuale', function (): void {
    $this->actingAs($this->user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertSessionHasErrors('password');

    $this->actingAs($this->user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'non-e-questa'])
        ->assertSessionHasErrors('password');

    expect($this->user->fresh()?->trashed())->toBeFalse();

    $this->actingAs($this->user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password'])
        ->assertRedirect(route('home'));

    expect(User::withTrashed()->find($this->user->id)?->trashed())->toBeTrue();
});

it('limita i tentativi di password sulla cancellazione dal sito', function (): void {
    foreach (range(1, 5) as $i) {
        $this->actingAs($this->user)
            ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'tentativo-'.$i])
            ->assertSessionHasErrors('password');
    }

    $this->actingAs($this->user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password'])
        ->assertTooManyRequests();

    expect($this->user->fresh()?->trashed())->toBeFalse();
});

it('chi è collegato e non ha mai scelto una password può farsene mandare una dal profilo', function (): void {
    $this->actingAs($this->user)->get(route('account.profile'))
        ->assertOk()
        ->assertSee(route('account.password.request'), false);

    $this->actingAs($this->user)->get(route('account.password.request'))->assertOk();
    $this->actingAs($this->user)
        ->post(route('account.password.email'), ['email' => $this->user->email])
        ->assertRedirect(route('account.password.request'));

    Notification::assertSentTo($this->user, ResetPasswordLink::class);
});

it('limita lo scarico dei dati a cinque all ora, fra sito e API', function (): void {
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->user)->get('/il-mio-profilo/dati')->assertOk();
    }
    $token = $this->user->createToken('Telefono')->plainTextToken;
    foreach (range(1, 2) as $i) {
        $this->withToken($token)->getJson('/api/v1/me/export')->assertOk();
    }

    $this->withToken($token)->getJson('/api/v1/me/export')->assertTooManyRequests();
});

it('consegna nome, cognome, posizione e le categorie di dati che mancavano', function (): void {
    app(RememberedLocation::class)->save(45.4064, 11.8768, $this->user);
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00');
    $altro = User::factory()->create();

    SavedSearch::query()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'name' => 'Jazz del giovedì', 'filters' => ['q' => 'jazz']]);
    $collection = EventCollection::query()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'name' => 'Da vedere']);
    $collection->items()->create(['occurrence_id' => $occurrence->id, 'note' => 'Con Marco']);
    EventCollection::query()->create(['user_id' => $altro->id, 'city_id' => $this->city->id, 'name' => 'Raccolta di un altro']);
    PresenceShare::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $occurrence->id, 'token' => Str::random(32), 'display_name' => 'Giulia', 'expires_at' => now()->addHours(6)]);
    AvailabilityAlert::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $occurrence->id]);
    ConsentLog::query()->create(['consent_id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'action' => 'reject_all', 'choices' => ['necessary' => true], 'policy_version' => '1']);

    $response = $this->actingAs($this->user)->get('/il-mio-profilo/dati')->assertOk();
    $data = $response->json();

    expect($data['profile']['first_name'])->toBe('Giulia')
        ->and($data['profile']['last_name'])->toBe('Rossi')
        ->and($data['profile']['remembered_location']['lat'])->toBe(45.4064)
        ->and($data['saved_searches'])->toHaveCount(1)
        ->and($data['saved_searches'][0]['name'])->toBe('Jazz del giovedì')
        ->and($data['collections'])->toHaveCount(1)
        ->and($data['collections'][0]['items'][0]['note'])->toBe('Con Marco')
        ->and($data['presence_shares'])->toHaveCount(1)
        ->and($data['alerts']['availability'])->toBe([(int) $occurrence->id])
        ->and($data['consent_logs'][0]['action'])->toBe('reject_all')
        ->and($data)->toHaveKeys(['event_comments', 'polls', 'poll_votes'])
        ->and((string) $response->getContent())->not->toContain('Raccolta di un altro');
});
