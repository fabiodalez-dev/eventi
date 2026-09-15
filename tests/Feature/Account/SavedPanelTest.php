<?php

declare(strict_types=1);

use App\Models\SavedEvent;
use Carbon\Carbon;
use Tests\Support\VenueIsolationScenario;

it('counts only visible future saves and reflects removal without leaking another account', function (): void {
    $scenario = VenueIsolationScenario::make();
    freezeLocal($scenario->city, '2026-09-10 12:00');
    SavedEvent::create(['user_id' => $scenario->ownerA->id, 'occurrence_id' => $scenario->occurrenceA->id]);
    SavedEvent::create(['user_id' => $scenario->ownerB->id, 'occurrence_id' => $scenario->occurrenceB->id]);
    $this->actingAs($scenario->ownerA)->get('/salvataggi/pannello')->assertOk()->assertHeader('X-Saved-Count', '1')
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee($scenario->publishedEventA->title)->assertDontSee($scenario->publishedEventB->title);
    $this->deleteJson('/salvataggi/'.$scenario->occurrenceA->id)->assertSuccessful();
    $this->get('/salvataggi/pannello')->assertHeader('X-Saved-Count', '0')->assertDontSee($scenario->publishedEventA->title);
    Carbon::setTestNow();
});

it('filters expired and duplicate guest saves from the panel count', function (): void {
    $scenario = VenueIsolationScenario::make();
    freezeLocal($scenario->city, '2026-09-12 12:00');
    $this->get('/salvataggi/pannello?ids='.$scenario->occurrenceA->id.','.$scenario->occurrenceB->id.','.$scenario->occurrenceB->id)
        ->assertOk()->assertHeader('X-Saved-Count', '1')->assertSee($scenario->publishedEventB->title)->assertDontSee($scenario->publishedEventA->title);
    Carbon::setTestNow();
});
