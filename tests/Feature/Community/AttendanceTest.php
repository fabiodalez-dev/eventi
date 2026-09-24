<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Community\Community;
use App\Services\Community\CommunityAccess;
use Carbon\Carbon;

/**
 * «Ci vado»: la sola dichiarazione pubblica su una singola data.
 *
 * Il rischio di questa funzione non è tecnico, è di esposizione: qualcuno che
 * compare in un elenco senza averlo scelto, o che resta esposto dopo aver
 * cambiato idea. I test che contano sono quelli.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00', '2026-09-25 23:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function attendee(string $handle, ProfileVisibility $visibility = ProfileVisibility::Public): User
{
    $user = User::factory()->create();
    $user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'att-'.$user->id)])->save();
    $user->communityProfile()->create(['handle' => $handle,
        'display_name' => ucfirst($handle), 'city_id' => test()->city->getKey(), 'visibility' => $visibility]);

    return $user->fresh();
}

it('salva la data e la rende pubblica in un gesto solo, e torna indietro cancellando tutto', function (): void {
    $user = attendee('giulia');

    expect(app(Community::class)->attendance($user, $this->occurrence, true))->toBeTrue();

    $saved = SavedEvent::query()->where('user_id', $user->getKey())->firstOrFail();
    expect($saved->occurrence_id)->toBe($this->occurrence->getKey())
        ->and($saved->visibility)->toBe(SavedVisibility::Public);

    app(Community::class)->attendance($user, $this->occurrence, false);

    expect($saved->fresh()->visibility)->toBe(SavedVisibility::Private);
});

it('non espone chi ha solo salvato la data', function (): void {
    $user = attendee('marco');
    app(\App\Actions\Account\SaveOccurrences::class)->one($user, $this->occurrence);

    expect(app(CommunityAccess::class)->attendees($this->occurrence, null)->count())->toBe(0);
});

it('pretende il numero verificato e un profilo pubblico prima di esporre qualcuno', function (): void {
    $withoutWhatsapp = User::factory()->create();
    expect(fn () => app(Community::class)->attendance($withoutWhatsapp, $this->occurrence, true))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    $withoutProfile = User::factory()->create();
    $withoutProfile->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'np')])->save();
    expect(fn () => app(Community::class)->attendance($withoutProfile->fresh(), $this->occurrence, true))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    expect(app(CommunityAccess::class)->attendees($this->occurrence, null)->count())->toBe(0);
});

it('toglie dall elenco chi perde la verifica o chiude il profilo, senza toccare il dato', function (): void {
    $user = attendee('elena');
    app(Community::class)->attendance($user, $this->occurrence, true);
    $viewer = attendee('chi_guarda');

    expect(app(CommunityAccess::class)->attendees($this->occurrence, $viewer)->count())->toBe(1);

    $user->forceFill(['whatsapp_verified_at' => null])->save();
    expect(app(CommunityAccess::class)->attendees($this->occurrence, $viewer)->count())->toBe(0);

    $user->forceFill(['whatsapp_verified_at' => now()])->save();
    $user->communityProfile->update(['visibility' => ProfileVisibility::Private]);
    expect(app(CommunityAccess::class)->attendees($this->occurrence, $viewer)->count())->toBe(0);

    // Il salvataggio è rimasto pubblico: è la lettura a proteggere, non una cancellazione.
    expect(SavedEvent::query()->where('user_id', $user->getKey())->value('visibility'))->toBe(SavedVisibility::Public);
});

it('non mostra nell elenco chi si è bloccato, in nessuna delle due direzioni', function (): void {
    $going = attendee('andrea');
    $viewer = attendee('sara');
    app(Community::class)->attendance($going, $this->occurrence, true);
    app(Community::class)->attendance($viewer, $this->occurrence, true);

    UserBlock::query()->create(['user_id' => $viewer->getKey(), 'blocked_user_id' => $going->getKey()]);

    $names = app(CommunityAccess::class)->attendees($this->occurrence, $viewer)->pluck('id')->all();
    expect($names)->not->toContain($going->getKey())->toContain($viewer->getKey());

    $reverse = app(CommunityAccess::class)->attendees($this->occurrence, $going)->pluck('id')->all();
    expect($reverse)->not->toContain($viewer->getKey());
});

it('accetta il gesto dal sito e dall app, e risponde con il numero aggiornato', function (): void {
    $user = attendee('paolo');

    $this->actingAs($user)->post(route('community.attendance', $this->occurrence), ['going' => 1])->assertRedirect();
    expect(app(CommunityAccess::class)->attendees($this->occurrence, null)->count())->toBe(1);

    Laravel\Sanctum\Sanctum::actingAs($user->fresh());
    $this->postJson('/api/v1/community/saved/'.$this->occurrence->getKey().'/attendance', ['going' => false])
        ->assertOk()->assertJsonPath('data.going', false)->assertJsonPath('data.count', 0);
});

it('mostra il numero a tutti e i nomi solo a chi è verificato', function (): void {
    $user = attendee('nadia');
    app(Community::class)->attendance($user, $this->occurrence, true);

    $url = \App\Support\EventUrl::occurrence($this->occurrence->fresh());

    $this->get($url)->assertOk()->assertSee(__('community.attendance.count_one'))->assertDontSee('Nadia');
    $this->actingAs(attendee('chi_legge'))->get($url)->assertOk()->assertSee('Nadia');
});
