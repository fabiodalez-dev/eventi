<?php

use App\Enums\OccurrenceStatus;
use App\Enums\SocialFormat;
use App\Enums\SocialPublicationStatus;
use App\Filament\Admin\Pages\Social;
use App\Filament\Admin\Pages\SocialSettings;
use App\Jobs\Social\PublishSocial;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Social\SocialGraphic;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudio;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function () {
    $this->scenario = VenueIsolationScenario::make();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->date = $this->scenario->publishedEventA->occurrences()->firstOrFail();
    Storage::fake('local');
    Queue::fake();
});

function socialTestBatch($test): SocialBatch
{
    return app(SocialStudio::class)->generate($test->date->event->city, $test->date->business_date->format('Y-m-d'), collect([$test->date]), SocialFormat::Portrait, [], $test->admin);
}

function socialTestConnection($test): SocialConnection
{
    return SocialConnection::create(['city_id' => $test->date->event->city_id, 'page_id' => '123', 'instagram_id' => '456', 'access_token' => 'secret-test-token', 'facebook_enabled' => true, 'instagram_enabled' => true, 'verified_at' => now()]);
}

it('renders all image formats and fits titles without silently dropping text', function () {
    foreach (SocialFormat::cases() as $format) {
        $bytes = app(SocialGraphic::class)->render($this->date, $format);
        $size = getimagesizefromstring($bytes);
        expect($size[0])->toBe(1080)->and($size[1])->toBe($format->height());
    }
    $title = 'Una serata speciale tra musica, teatro e incontri nel cuore della città di Padova';
    [$lines] = app(SocialGraphic::class)->fit($title, resource_path('fonts/og-title.ttf'), 972, 220, 64, 30);
    expect(implode(' ', $lines))->toBe($title);
    expect(fn () => app(SocialGraphic::class)->fit(str_repeat('Lunghissimo ', 150), resource_path('fonts/og-title.ttf'), 972, 220, 64, 30))->toThrow(RuntimeException::class);
});

it('protects previews downloads and venue-generated batches from other venues', function () {
    $this->actingAs($this->scenario->ownerB)->get(route('social.preview', $this->date))->assertForbidden();
    $batch = app(SocialStudio::class)->generate($this->date->event->city, $this->date->business_date->format('Y-m-d'), collect([$this->date]), SocialFormat::Portrait, [], $this->scenario->ownerA, $this->scenario->venueA->id);
    $this->get(route('social.zip', $batch))->assertForbidden();
    $this->actingAs($this->scenario->ownerA)->get(route('social.zip', $batch))->assertDownload();
    $this->get('/social/grafiche/'.$batch->id.'/0.jpg')->assertForbidden();
    $this->get(SocialStudio::imageUrl($batch, 0))->assertOk()->assertHeader('content-type', 'image/jpeg');
});

it('renders studio settings and dashboard for admins only', function () {
    $this->actingAs($this->scenario->ownerA)->get(Social::getUrl())->assertForbidden();
    $this->actingAs($this->admin)->get(Social::getUrl())->assertOk()->assertSee(__('social.lead'));
    $this->get(SocialSettings::getUrl())->assertOk()->assertSee(__('social.template'));
    $this->get('/admin')->assertOk();
});

it('rejects unknown placeholders and encrypts tokens without displaying them', function () {
    $this->actingAs($this->admin);
    Livewire::test(SocialSettings::class)->set('data.page_id', '123')->set('data.caption', ':inventato')->call('save')->assertHasErrors(['data.caption']);
    $connection = socialTestConnection($this);
    expect($connection->getRawOriginal('access_token'))->not->toContain('secret-test-token');
    $this->get(SocialSettings::getUrl())->assertDontSee('secret-test-token');
});

it('allows customizing captions without connecting social accounts', function () {
    $this->actingAs($this->admin);
    Livewire::test(SocialSettings::class)->set('data.caption', 'Il :data a :citta: :eventi :link')->call('save')->assertHasNoErrors();
    expect(SocialConnection::firstOrFail()->caption)->toContain('Il :data')->and(SocialConnection::firstOrFail()->automatic)->toBeFalse();
});

it('uses all caption placeholders and chunks without losing events', function () {
    $batch = socialTestBatch($this);
    $items = [];
    for ($i = 1; $i <= 21; $i++) {
        $items[] = array_merge($batch->items[0], ['title' => 'Evento '.$i]);
    }
    $batch->update(['items' => $items]);
    socialTestConnection($this)->update(['caption' => ':data :giorno :citta :numero :parte/:parti :link :eventi']);
    $captions = app(SocialPublisher::class)->captions($batch);
    expect($captions)->toHaveCount(3)->and($captions[2])->toContain('Evento 21', '3/3')->not->toContain(':data', 'Evento 11');
});

it('links to the exact event date and includes venue and organizer', function () {
    $this->date->event->update(['organizer_name' => 'Associazione Padova Musica']);
    $batch = socialTestBatch($this);
    $caption = app(SocialPublisher::class)->captions($batch)[0];
    expect($caption)->toContain('/eventi/'.$batch->date->format('Y-m-d'), 'Circolo Aurora', 'Organizza: Associazione Padova Musica');
});

it('generates a venue batch through the page and rejects injected event IDs', function () {
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    Livewire::test(App\Filament\Venue\Pages\Social::class)
        ->set('date', $this->date->business_date->format('Y-m-d'))->call('selectAll')->call('generate')->assertHasNoErrors();
    expect(SocialBatch::firstOrFail()->venue_id)->toBe($this->scenario->venueA->id);
    Livewire::test(App\Filament\Venue\Pages\Social::class)
        ->set('selected', [$this->scenario->occurrenceB->id])->call('generate')->assertForbidden();
});

it('runs the automatic daily publication once with the configured caption', function () {
    $this->travelTo($this->date->starts_at->copy()->timezone('Europe/Rome')->setTime(12, 0));
    $connection = socialTestConnection($this);
    $connection->update(['automatic' => true, 'publish_time' => '09:00', 'caption' => ':data :eventi :link']);
    $this->artisan('social:daily')->assertSuccessful();
    $this->artisan('social:daily')->assertSuccessful();
    expect(SocialPublication::count())->toBe(2)->and(SocialBatch::count())->toBe(1);
    $this->travelBack();
});

it('enqueues once and rejects changed events', function () {
    $batch = socialTestBatch($this);
    socialTestConnection($this);
    $publisher = app(SocialPublisher::class);
    $publisher->enqueue($batch);
    $publisher->enqueue($batch);
    expect(SocialPublication::count())->toBe(2);
    Queue::assertPushed(PublishSocial::class, 2);
    $this->date->update(['status' => OccurrenceStatus::Cancelled]);
    expect(fn () => $publisher->enqueue($batch))->toThrow(RuntimeException::class, __('social.stale'));
});

it('waits for Instagram containers and never repeats a successful publication', function () {
    $batch = socialTestBatch($this);
    $connection = socialTestConnection($this);
    $publisher = app(SocialPublisher::class);
    $publisher->enqueue($batch);
    $publication = SocialPublication::where('platform', 'instagram')->firstOrFail();
    $job = new PublishSocial($publication->id);
    Http::fake([
        '*/456/media' => Http::response(['id' => 'child']),
        '*/child*' => Http::response(['status_code' => 'FINISHED']),
        '*/456/media_publish' => Http::response(['id' => 'published']),
    ]);
    $job->handle($publisher);
    $job->handle($publisher);
    $job->handle($publisher);
    $job->handle($publisher);
    expect($publication->fresh()->status)->toBe(SocialPublicationStatus::Published);
    Http::assertSentCount(5);
});

it('does not retry an ambiguous publish timeout', function () {
    $batch = socialTestBatch($this);
    socialTestConnection($this);
    $publisher = app(SocialPublisher::class);
    $publisher->enqueue($batch);
    $publication = SocialPublication::where('platform', 'facebook')->firstOrFail();
    $publication->update(['remote_ids' => ['children' => ['photo']]]);
    Http::fake(fn () => throw new ConnectionException('Timeout'));
    $job = new PublishSocial($publication->id);
    $job->handle($publisher);
    expect($publication->fresh()->status)->toBe(SocialPublicationStatus::Uncertain);
    Http::fake();
    $job->handle($publisher);
    Http::assertNothingSent();
});
