<?php

declare(strict_types=1);

use App\Actions\MergeEventsAction;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\RelationManagers\ActivityRelationManager;
use App\Filament\Admin\Resources\SearchMisses\Pages\ManageSearchMisses;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\Redirect;
use App\Models\SavedEvent;
use App\Models\SearchMiss;
use App\Models\User;
use App\Services\Search\SearchMissLog;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $this->admin = User::factory()->create(['name' => 'Redazione']);
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
    $this->city = testCity();
    $this->category = testCategory();
});

it('merges a duplicate event: twin dates fuse, lone dates move, everything attached follows, and the old address redirects', function (): void {
    $keepDate = occurrenceAt($this->city, $this->category, '2026-11-05 20:00:00', null, [], ['title' => 'Concerto al circolo', 'description' => null]);
    $keep = $keepDate->event;
    $venue = $keep->venue;
    $twinDate = occurrenceAt($this->city, $this->category, '2026-11-05 20:00:00', null, ['booking_enabled' => true, 'booking_capacity' => 10], ['title' => 'CONCERTO AL CIRCOLO (import)', 'description' => 'Testo dal feed'], $venue);
    $absorbed = $twinDate->event;
    $loneDate = occurrenceAt($this->city, $this->category, '2026-11-12 20:00:00', null, [], [], $venue);
    DB::table('event_occurrences')->where('id', $loneDate->id)->update(['event_id' => $absorbed->id, 'url_number' => 2]);
    $loneDate->refresh();
    $venue->update(['ticketing_enabled' => true]);

    $both = User::factory()->create();
    $onlyDup = User::factory()->create();
    SavedEvent::create(['user_id' => $both->id, 'occurrence_id' => $keepDate->id]);
    SavedEvent::create(['user_id' => $both->id, 'occurrence_id' => $twinDate->id]);
    SavedEvent::create(['user_id' => $onlyDup->id, 'occurrence_id' => $twinDate->id]);
    $booking = app(TicketingService::class)->reserve($onlyDup, $twinDate, ['Ada'], (string) Str::uuid(), false);
    Follow::create(['user_id' => $onlyDup->id, 'followable_type' => 'event', 'followable_id' => $absorbed->id, 'notify' => true]);
    DB::table('event_views_daily')->insert([
        ['event_id' => $keep->id, 'date' => '2026-10-20', 'views' => 5, 'unique_views' => 4, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $absorbed->id, 'date' => '2026-10-20', 'views' => 7, 'unique_views' => 6, 'created_at' => now(), 'updated_at' => now()],
        ['event_id' => $absorbed->id, 'date' => '2026-10-21', 'views' => 1, 'unique_views' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $absorbedSlug = $absorbed->slug;

    $report = app(MergeEventsAction::class)->execute($keep, $absorbed, $this->admin);

    expect($report['dates_merged'])->toBe(1)->and($report['dates_moved'])->toBe(1)->and($report['saves'])->toBe(1)->and($report['bookings'])->toBe(1);
    expect(Event::query()->find($absorbed->id))->toBeNull();
    expect(Event::withTrashed()->find($absorbed->id))->not->toBeNull();
    expect($keep->fresh()->occurrences()->count())->toBe(2);
    expect($loneDate->fresh()->event_id)->toBe($keep->id);
    expect($loneDate->fresh()->url_number)->toBe(2);
    expect(EventOccurrence::query()->find($twinDate->id))->toBeNull();
    expect(SavedEvent::query()->where('occurrence_id', $keepDate->id)->count())->toBe(2);
    expect($booking->fresh()->occurrence_id)->toBe($keepDate->id);
    expect(Follow::query()->where('followable_id', $keep->id)->where('followable_type', 'event')->exists())->toBeTrue();
    expect($keep->fresh()->description)->toBe('Testo dal feed');
    expect($keep->fresh()->title)->toBe('Concerto al circolo');
    expect($keep->fresh()->saves_count)->toBe(2);
    expect((int) DB::table('event_views_daily')->where('event_id', $keep->id)->where('date', '2026-10-20')->value('views'))->toBe(12);
    expect(DB::table('event_views_daily')->where('event_id', $keep->id)->count())->toBe(2);
    expect(Redirect::query()->where('from_path', '/eventi/'.$absorbedSlug)->value('to_path'))->toBe('/eventi/'.$keep->slug);
    expect($keep->activitiesAsSubject()->where('event', 'merged')->exists())->toBeTrue();
    $this->get('/eventi/'.$absorbedSlug)->assertRedirect('/eventi/'.$keep->slug);

    $other = occurrenceAt($this->city, $this->category, '2026-12-01 20:00:00', null, [], ['title' => 'Da unire dal pannello'], $venue)->event;
    Livewire::test(ListEvents::class)->callTableAction('merge', $keep, ['absorbed' => $other->getKey()])->assertHasNoTableActionErrors();
    expect(Event::query()->find($other->id))->toBeNull()->and($keep->fresh()->occurrences()->count())->toBe(3);
});

it('restores the previous values of an edit from the history and records the restoration', function (): void {
    $event = occurrenceAt($this->city, $this->category, '2026-11-05 20:00:00', null, [], ['title' => 'Titolo giusto', 'description' => 'Descrizione buona'])->event;
    $event->update(['title' => 'Titolo rovinato', 'description' => 'Pasticcio', 'price_min' => 12]);
    $event->update(['price_min' => 15]);
    $ruin = $event->activitiesAsSubject()->where('event', 'updated')->orderBy('id')->first();
    expect($ruin->attribute_changes['old']['title'])->toBe('Titolo giusto');

    Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('restore', $ruin)->assertHasNoTableActionErrors();
    $event->refresh();
    expect($event->title)->toBe('Titolo giusto')
        ->and($event->description)->toBe('Descrizione buona')
        ->and((float) $event->price_min)->toBe(0.0)
        ->and($event->activitiesAsSubject()->where('event', 'updated')->count())->toBe(3);
    $created = $event->activitiesAsSubject()->where('event', 'created')->first();
    Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionHidden('restore', $created);
});

it('logs searches without results once per term, counts repeats, and lists them in the panel', function (): void {
    $this->get(route('search', ['q' => 'Burraco']))->assertOk();
    $this->get(route('search', ['q' => '  burraco ']))->assertOk();
    $this->get(route('events.index', ['q' => 'burraco']))->assertOk();
    $this->getJson('/api/v1/search?q=burraco')->assertOk();
    $this->get(route('search', ['q' => 'ab']))->assertOk();
    expect(SearchMiss::query()->count())->toBe(1)
        ->and(app(SearchMissLog::class)->count($this->city, 'burraco'))->toBe(4);
    occurrenceAt($this->city, $this->category, '2026-11-05 20:00:00', null, [], ['title' => 'Torneo di burraco']);
    $this->get(route('search', ['q' => 'burraco']))->assertOk()->assertSee('Torneo di burraco');
    expect(app(SearchMissLog::class)->count($this->city, 'burraco'))->toBe(4);
    Livewire::test(ManageSearchMisses::class)->assertOk()->assertSee('burraco')->assertSee('4');
    $this->get('/admin/ricerche-senza-risultati')->assertOk();
});
