<?php

declare(strict_types=1);

use App\Enums\AccessibilityFeature;
use App\Models\EventFeature;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Policies\EventFeaturePolicy;
use App\Services\Seo\EditorialContent;
use Database\Seeders\EventFeatureSeeder;

it('shows selected practical features with safe icons and keeps membership below the description', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-11 12:00');
    $this->seed(EventFeatureSeeder::class);
    $feature = EventFeature::where('slug', 'accesso-in-coppia')->firstOrFail();
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00', event: ['description' => 'Descrizione unica di prova', 'content_details' => [
        'membership' => 'required', 'feature_ids' => [$feature->id],
        'practical_custom' => [['label' => 'Porta acqua', 'icon' => '<script>', 'text' => 'Una borraccia']],
    ]]);
    $response = $this->get('/eventi/'.$date->event->slug.'/1')->assertOk();
    $response->assertSeeInOrder(['id="salva-evento"', 'Descrizione unica di prova', 'id="prima-di-andare"', 'Tessera richiesta'], false)
        ->assertSee('Accesso in coppia')->assertSee('Porta acqua')->assertDontSee(__('seo.all_dates'));
    $items = app(EditorialContent::class)->details($date->event)['practical_items'];
    expect(collect($items)->firstWhere('label', 'Porta acqua')['icon'])->toBe('information-circle');
    $this->getJson('/api/v1/events/'.$date->event->slug)->assertOk()->assertJsonPath('data.content_details.practical_items.0.label', 'Tessera richiesta');
    $feature->update(['name' => 'Ingresso in coppia', 'icon' => 'users']);
    expect(collect(app(EditorialContent::class)->details($date->event)['practical_items'])->firstWhere('label', 'Ingresso in coppia')['icon'])->toBe('users');
    $feature->update(['is_active' => false]);
    expect(collect(app(EditorialContent::class)->details($date->event)['practical_items'])->pluck('label')->all())->not->toContain('Ingresso in coppia');
});

it('keeps empty practical information invisible and limits catalogue administration to admins', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-11 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00', event: ['price_type' => 'free', 'content_details' => []]);
    $this->get('/eventi/'.$date->event->slug.'/1')->assertOk()->assertDontSee('Tessera: informazione non dichiarata');
    $user = User::factory()->create();
    expect((new EventFeaturePolicy)->create($user))->toBeFalse();
});

it('reads the practical catalogue from file cache and only links to other dates when they exist', function (): void {
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', storage_path('framework/cache/before-going-test'));
    app('cache')->forgetDriver('file');
    try {
        $city = testCity();
        freezeLocal($city, '2026-09-11 12:00');
        $feature = EventFeature::create(['name' => 'Bagno accessibile', 'icon' => 'hand-raised']);
        $first = occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00', event: ['content_details' => ['feature_ids' => [$feature->id]]]);
        $service = app(EditorialContent::class);
        expect($service->details($first->event)['practical_items'])->toBe($service->details($first->event)['practical_items']);
        EventOccurrence::factory()->create(['event_id' => $first->event_id, 'starts_at' => localInstant($city, '2026-09-13 21:00')->utc()]);
        $this->get('/eventi/'.$first->event->slug.'/1')->assertOk()->assertSee(__('seo.all_dates'));
    } finally {
        app('cache')->store('file')->flush();
        app('cache')->forgetDriver('file');
        config()->set('cache.default', 'array');
    }
});

it('shows venue accessibility once inside practical advice with its source', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-11 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00');
    $date->event->venue->update(['accessibility' => ['step_free_entrance' => true]]);
    $date->event->unsetRelation('venue');
    $items = app(EditorialContent::class)->details($date->event)['practical_items'];
    $label = AccessibilityFeature::StepFreeEntrance->label();
    expect(collect($items)->where('label', $label)->count())->toBe(1);
    expect(collect($items)->firstWhere('label', $label)['text'])->toBe('Disponibile nel locale');
    $this->get('/eventi/'.$date->event->slug.'/1')->assertOk()
        ->assertSee($label)->assertDontSee('id="accessibilita-evento"', false);
});
