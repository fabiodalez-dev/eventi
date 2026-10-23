<?php

declare(strict_types=1);

use App\Filament\Organizer\Resources\Events\Pages\ListEvents;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Support\VideoEmbed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory(['slug' => 'musica-dal-vivo']);
    freezeLocal($this->city, '2026-10-23 12:00');
});

it('parses only youtube and vimeo addresses into privacy-preserving embeds', function (): void {
    expect(VideoEmbed::from('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3')?->embedUrl)->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0')
        ->and(VideoEmbed::from('https://youtu.be/dQw4w9WgXcQ')?->provider)->toBe('youtube')
        ->and(VideoEmbed::from('https://youtube.com/shorts/dQw4w9WgXcQ')?->id)->toBe('dQw4w9WgXcQ')
        ->and(VideoEmbed::from('https://vimeo.com/123456789')?->embedUrl)->toBe('https://player.vimeo.com/video/123456789?dnt=1')
        ->and(VideoEmbed::from('https://example.com/video.mp4'))->toBeNull()
        ->and(VideoEmbed::from('https://www.youtube.com/watch?v=short'))->toBeNull()
        ->and(VideoEmbed::from('javascript:alert(1)'))->toBeNull();
});

it('shows the gallery, a click-to-load video and the co-organizers on the event page', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Serata con video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ']);
    $event = $date->event;
    $event->addMedia(ImageFixtures::upload('foto-1.png', ImageFixtures::png(800, 600)))->toMediaCollection('gallery');
    $primary = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Collettivo A', 'is_active' => true]);
    $co = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Associazione B', 'is_active' => true]);
    $inactive = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Spenta C', 'is_active' => false]);
    $event->update(['organizer_id' => $primary->id]);
    $event->organizers()->sync([$co->id, $inactive->id]);

    $page = $this->get(route('events.show', $event))->assertOk();
    $page->assertSee(__('events.detail.gallery'))->assertSee('foto-1')
        ->assertSee('data-video-embed', false)->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->assertDontSee('<iframe', false)
        ->assertSee('Collettivo A')->assertSee('Associazione B')->assertDontSee('Spenta C');
    $this->get(route('organizers.show', $co))->assertOk()->assertSee('Serata con video');
});

it('lets a co-organizer see and edit the event from its own panel while an unrelated organizer cannot', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Rassegna condivisa']);
    $event = $date->event;
    $primary = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Collettivo A', 'is_active' => true]);
    $co = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Associazione B', 'is_active' => true]);
    $stranger = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Estranei', 'is_active' => true]);
    $event->update(['organizer_id' => $primary->id]);
    $event->organizers()->sync([$co->id]);
    $coUser = User::factory()->create();
    $co->update(['owner_id' => $coUser->id]);
    $strangerUser = User::factory()->create();
    $stranger->update(['owner_id' => $strangerUser->id]);

    expect($coUser->can('update', $event))->toBeTrue()
        ->and($coUser->can('view', $event))->toBeTrue()
        ->and($strangerUser->can('update', $event))->toBeFalse();

    $this->actingAs($coUser);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($co, true);
    Livewire::test(ListEvents::class)->assertCanSeeTableRecords([$event]);

    $this->actingAs($strangerUser);
    Filament::setTenant($stranger, true);
    Livewire::test(ListEvents::class)->assertCanNotSeeTableRecords([$event]);
});

it('serves public calendars for a venue, an organizer and a category, and a printable weekly programme', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Nord']);
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-27 21:00', event: ['title' => 'Martedì jazz'], venue: $venue);
    occurrenceAtLocal($this->city, $this->category, '2026-10-31 22:00', event: ['title' => 'Sabato funk'], venue: $venue);
    occurrenceAtLocal($this->city, $this->category, '2026-11-05 21:00', event: ['title' => 'Fuori settimana'], venue: $venue);
    $organizer = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Collettivo', 'is_active' => true]);
    $date->event->update(['organizer_id' => $organizer->id]);

    $ics = $this->get(route('venues.calendar', $venue->slug))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($ics->getContent())->toContain('BEGIN:VCALENDAR')->toContain('Martedì jazz')->toContain('Sabato funk')->toContain('Fuori settimana')
        ->and($ics->headers->get('Cache-Control'))->toContain('public');
    expect($this->get(route('organizers.calendar', $organizer->slug))->assertOk()->getContent())->toContain('Martedì jazz')->not->toContain('Sabato funk');
    expect($this->get(route('categories.calendar', $this->category->slug))->assertOk()->getContent())->toContain('Sabato funk');
    $this->get(route('venues.calendar', 'non-esiste'))->assertNotFound();
    $this->get(route('venues.show', $venue))->assertOk()->assertSee(route('venues.calendar', $venue->slug))->assertSee(route('venues.programme', $venue->slug));
    $this->get(route('organizers.show', $organizer))->assertOk()->assertSee(route('organizers.calendar', $organizer->slug));
    $this->get(route('events.category', $this->category))->assertOk()->assertSee(route('categories.calendar', $this->category->slug));

    $pdf = $this->get(route('venues.programme', ['slug' => $venue->slug, 'settimana' => '2026-10-28']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF');
    $this->get(route('venues.programme', ['slug' => $venue->slug, 'settimana' => 'non-una-data']))->assertOk();
});

it('exposes co-organizers, gallery and video to the API without loading any player', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Serata API', 'video_url' => 'https://vimeo.com/123456789']);
    $event = $date->event;
    $event->addMedia(ImageFixtures::upload('api-1.png', ImageFixtures::png(640, 480)))->toMediaCollection('gallery');
    $co = Organizer::query()->create(['city_id' => $this->city->id, 'name' => 'Associazione B', 'is_active' => true]);
    $event->organizers()->sync([$co->id]);
    $response = $this->getJson('/api/v1/events/'.$event->slug)->assertOk();
    expect($response->json('data.organizers.0.slug'))->toBe($co->slug)
        ->and($response->json('data.organizers.0.url'))->toBe(route('organizers.show', $co))
        ->and($response->json('data.gallery'))->toHaveCount(1)
        ->and($response->json('data.gallery.0.full'))->toContain('api-1')
        ->and($response->json('data.video.provider'))->toBe('vimeo')
        ->and($response->json('data.video.embed_url'))->toBe('https://player.vimeo.com/video/123456789?dnt=1');
    $plain = occurrenceAtLocal($this->city, $this->category, '2026-11-02 21:00', event: ['title' => 'Senza extra']);
    $bare = $this->getJson('/api/v1/events/'.$plain->event->slug)->assertOk();
    expect($bare->json('data.organizers'))->toBe([])->and($bare->json('data.gallery'))->toBe([])->and($bare->json('data.video'))->toBeNull();
});
