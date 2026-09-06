<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Filament\Admin\Pages\SeoOverview;
use App\Models\User;
use App\Services\Seo\EditorialContent;
use App\Services\Seo\StructuredData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');
    $this->event = $this->date->event;
    freezeLocal($this->city, '2026-09-01 12:00');
});

it('serves a single date with its own canonical and blocks foreign and draft dates', function (): void {
    $url = route('events.occurrence', ['slug' => $this->event->slug, 'occurrence' => $this->date->id]);
    $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.$url.'">', false);
    $other = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:30');
    $this->get(route('events.occurrence', ['slug' => $this->event->slug, 'occurrence' => $other->id]))->assertNotFound();
    $this->event->update(['status' => EventStatus::Draft]);
    $this->get($url)->assertNotFound();
});

it('records the previous date and emits a rescheduled event without changing its identity', function (): void {
    $id = $this->date->id;
    $before = $this->date->starts_at->copy();
    $this->date->update(['starts_at' => $before->copy()->addDay()]);
    expect($this->date->id)->toBe($id)->and($this->date->previous_starts_at->equalTo($before))->toBeTrue();
    $node = app(StructuredData::class)->event($this->event, $this->date);
    expect($node['eventStatus'])->toBe('https://schema.org/EventRescheduled')->and($node)->toHaveKey('previousStartDate');
});

it('inherits arrival information but not policies or FAQs from the venue', function (): void {
    $this->event->venue->update(['content_details' => ['parking_notes' => 'Parcheggio esterno', 'refund_policy' => 'Non ereditare']]);
    $this->event->update(['content_details' => ['transit_notes' => 'Bus 12']]);
    $details = app(EditorialContent::class)->details($this->event);
    expect($details['parking_notes'])->toBe('Parcheggio esterno')->and($details['transit_notes'])->toBe('Bus 12')
        ->and($details)->not->toHaveKey('refund_policy');
});

it('renders FAQ text safely and produces matching JSON-LD', function (): void {
    $this->event->update(['content_details' => ['faqs' => [['question' => 'Serve prenotare?', 'answer' => '<script>alert(1)</script> No.']]]]);
    $this->get(route('events.show', $this->event))->assertOk()->assertSee('Serve prenotare?')
        ->assertSee('FAQPage')->assertDontSee('<script>alert(1)</script>', false);
});

it('excludes demo content from both robots and the sitemap', function (): void {
    $this->event->update(['is_demo' => true]);
    $this->get(route('events.show', $this->event))->assertOk()->assertSee('noindex, follow');
    $this->get('/sitemap-eventi-1.xml')->assertNotFound();
});

it('preserves administrator indexing settings when a venue user saves content', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->event->update(['is_demo' => true, 'seo' => ['indexing' => 'excluded']]);
    $owner = User::factory()->create();
    $owner->assignRole('venue_owner');
    $this->actingAs($owner);
    $this->event->update(['is_demo' => false, 'seo' => ['title' => 'Titolo aggiornato', 'indexing' => 'automatic']]);
    expect($this->event->fresh()->is_demo)->toBeTrue()->and($this->event->fresh()->seo['indexing'])->toBe('excluded');
});

it('saves the Search Console verification without overwriting city SEO settings', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    $this->city->update(['seo' => ['title' => 'Città e cultura']]);
    Livewire::test(SeoOverview::class)->fillForm(['city_id' => $this->city->id, 'verification' => 'Google-test_token-123'])->call('save')->assertHasNoFormErrors();
    expect($this->city->fresh()->seo['title'])->toBe('Città e cultura');
    $this->get('/')->assertOk()->assertSee('<meta name="google-site-verification" content="Google-test_token-123">', false);
});

it('rejects HTML instead of a Search Console token and denies venue users access', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Livewire::test(SeoOverview::class)->fillForm(['city_id' => $this->city->id, 'verification' => '<meta>'])->call('save')->assertHasFormErrors(['verification']);
    $this->actingAs(User::factory()->create())->get('/admin/seo-overview')->assertForbidden();
});
