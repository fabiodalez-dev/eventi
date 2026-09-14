<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Support\EventUrl;

afterEach(fn () => Carbon\Carbon::setTestNow());

it('counts real unique savers per date in cards and detail excluding deleted accounts', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-13 21:00');
    $users = User::factory()->count(3)->create();
    foreach ($users as $user) {
        app(SaveOccurrences::class)->many($user, $city, [$date->id, $date->id]);
    }
    $users->last()->delete();
    $loaded = EventOccurrenceQuery::for($city)->forOccurrences([$date->id])->get()->sole();
    expect($loaded->interestedCount())->toBe(2);
    $detail = $this->get(EventUrl::occurrence($date))->assertOk()->assertSee('2 persone interessate');
    $document = new DOMDocument;
    @$document->loadHTML($detail->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@data-interest-id="'.$date->id.'"]//*[local-name()="svg"]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-interest-id="'.$date->id.'" and @data-interest-count="2"]')->length)->toBeGreaterThan(0);
    $this->get('/')->assertOk()->assertSee('2 persone interessate');
    $this->actingAs($users->first())->deleteJson(route('account.saved.destroy', $date->id))
        ->assertOk()->assertJsonPath('interested_counts.'.$date->id, 1);
    $this->actingAs($users->first())->postJson(route('account.saved.store'), ['occurrence_id' => $date->id])
        ->assertOk()->assertJsonPath('interested_counts.'.$date->id, 2);
});
