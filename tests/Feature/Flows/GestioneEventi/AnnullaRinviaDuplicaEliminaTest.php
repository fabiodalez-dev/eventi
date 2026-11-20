<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventStatus;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge i gesti che tolgono o rifanno un evento: annullare una data o un
 * pezzo di serie, rinviarla spostandola, duplicare una serata per rifarla,
 * eliminare un evento. Quello che conta è cosa resta al pubblico — scheda,
 * giornate, pagina del locale — e che una serie rigenerata non resusciti le
 * date che il gestore ha già annullato o spostato a mano.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->venue->update(['auto_publish' => true]);
    $this->evento = $this->scenario->publishedEventA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Una serie del giovedì creata dal modulo rapido, dal 17 settembre all'8 ottobre. */
function geSerieDelGiovedi(object $test, string $titolo): Event
{
    geEntra($test->owner, $test->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($test->scenario->category, [
            'title' => $titolo,
            'starts_at' => '2026-09-17 21:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Thursday->value],
            'repeat_until' => '2026-10-08',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    return Event::query()->where('title', $titolo)->sole();
}

it('annulla l’unica data di un evento: la scheda resta e dice «annullato», la giornata non lo propone come serata', function (): void {
    $data = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geDate($this->evento)->callTableAction('cancel_occurrence', $data)->assertHasNoTableActionErrors();

    expect($data->fresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($this->evento->fresh()->status)->toBe(EventStatus::Published);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertSee(OccurrenceStatus::Cancelled->label());
    $this->get('/eventi/'.$this->evento->slug.'/'.$data->url_number)->assertOk();

    // Nell'elenco del pannello l'evento non offre più «tutto esaurito»: non c'è nulla da vendere.
    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)->assertCanSeeTableRecords([$this->evento]);
});

it('annulla una sola data di una serie e rigenerare la serie non la resuscita', function (): void {
    $serie = geSerieDelGiovedi($this, 'Giovedì del liscio');
    $date = $serie->occurrences()->orderBy('starts_at')->get();
    expect($date)->toHaveCount(4);

    geEntra($this->owner, $this->venue, '/eventi/'.$serie->getRouteKey().'/modifica');
    geDate($serie)
        ->callTableAction('cancel_occurrence', $date[1], data: ['scope' => OccurrenceScope::Single->value])
        ->assertHasNoTableActionErrors();

    // «Ripeti» con la stessa regola, fino a più avanti: aggiunge in coda, non tocca l'annullata.
    geEntra($this->owner, $this->venue, '/eventi/'.$serie->getRouteKey());
    Livewire::test(ViewEvent::class, ['record' => $serie->getRouteKey()])
        ->callAction('repeat', data: ['frequency' => RecurrenceFrequency::Weekly->value, 'weekdays' => [Weekday::Thursday->value], 'until' => '2026-10-15'])
        ->assertHasNoActionErrors();

    $dopo = $serie->occurrences()->orderBy('starts_at')->get();
    expect($dopo)->toHaveCount(5)
        ->and($dopo->map(fn (EventOccurrence $o): string => geOraLocale($o).' '.$o->status->value)->all())->toBe([
            '2026-09-17 21:00 scheduled',
            '2026-09-24 21:00 cancelled',
            '2026-10-01 21:00 scheduled',
            '2026-10-08 21:00 scheduled',
            '2026-10-15 21:00 scheduled',
        ]);

    geSitoPubblico();
    $this->get('/eventi/2026-10-01')->assertOk()->assertSee('Giovedì del liscio');
});

it('rinvia una data della serie spostandola, e la serie rigenerata non la rimette al giorno vecchio', function (): void {
    $serie = geSerieDelGiovedi($this, 'Giovedì della poesia');
    $date = $serie->occurrences()->orderBy('starts_at')->get();

    geEntra($this->owner, $this->venue, '/eventi/'.$serie->getRouteKey().'/modifica');
    geDate($serie)
        ->callTableAction('edit', $date[2], data: ['starts_at' => '2026-10-03 18:00:00'])
        ->assertHasNoTableActionErrors();

    $spostata = $date[2]->fresh();
    expect(geOraLocale($spostata))->toBe('2026-10-03 18:00')
        ->and($spostata->is_exception)->toBeTrue();

    geEntra($this->owner, $this->venue, '/eventi/'.$serie->getRouteKey());
    Livewire::test(ViewEvent::class, ['record' => $serie->getRouteKey()])
        ->callAction('repeat', data: ['frequency' => RecurrenceFrequency::Weekly->value, 'weekdays' => [Weekday::Thursday->value], 'until' => '2026-10-08']);

    expect($serie->occurrences()->orderBy('starts_at')->get()->map(geOraLocale(...))->all())
        ->toBe(['2026-09-17 21:00', '2026-09-24 21:00', '2026-10-03 18:00', '2026-10-08 21:00']);

    geSitoPubblico();
    $this->get('/eventi/2026-10-01')->assertOk()->assertDontSee('Giovedì della poesia');
    $this->get('/eventi/2026-10-03')->assertOk()->assertSee('Giovedì della poesia');
});

it('duplica una serata dall’elenco: la copia è una bozza senza date, con la locandina, e va online solo quando la si completa', function (): void {
    Storage::fake('media');
    $this->evento->addMedia(UploadedFile::fake()->image('serata.png', 800, 1000))->toMediaCollection('poster');
    $this->evento->update(['description' => '<p>Canti della tradizione.</p>', 'price_type' => 'ticket', 'price_min' => 10]);

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)
        ->callTableAction('duplicate', $this->evento)
        ->assertNotified(__('manage.notifications.duplicated'));

    $copia = Event::query()->where('venue_id', $this->venue->id)->whereKeyNot($this->evento->id)->latest('id')->firstOrFail();
    expect($copia->status)->toBe(EventStatus::Draft)
        ->and($copia->title)->toBe(__('admin.notifications.duplicate_title', ['title' => $this->evento->title]))
        ->and($copia->description)->toBe('<p>Canti della tradizione.</p>')
        ->and((float) $copia->price_min)->toBe(10.0)
        ->and($copia->occurrences()->exists())->toBeFalse()
        ->and($copia->getMedia('poster'))->toHaveCount(1)
        ->and($copia->slug)->not->toBe($this->evento->slug)
        ->and($copia->created_by)->toBe($this->owner->id);

    geSitoPubblico();
    $this->get('/eventi/'.$copia->slug)->assertNotFound();

    geEntra($this->owner, $this->venue, '/eventi/'.$copia->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $copia->getRouteKey()])->fillForm(['title' => 'Concerto di musica popolare — replica'])->call('save')->assertHasNoFormErrors();
    geDate($copia)->callTableAction('create', data: ['starts_at' => '2026-10-02 21:00:00']);
    Livewire::test(ViewEvent::class, ['record' => $copia->getRouteKey()])->callAction('publish');

    expect($copia->fresh()->status)->toBe(EventStatus::Published);
    geSitoPubblico();
    $this->get('/eventi/'.$copia->fresh()->slug)->assertOk()->assertSee('Concerto di musica popolare — replica');
    // L'originale non è stato toccato.
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertSee('Concerto di musica popolare');
    expect($this->evento->fresh()->getMedia('poster'))->toHaveCount(1);
});

it('duplica anche un evento a cui la redazione ha dato un punteggio, senza portarsi dietro le sue decisioni', function (): void {
    $this->evento->forceFill(['editorial_score' => 40])->saveQuietly();

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)
        ->callTableAction('duplicate', $this->evento)
        ->assertNotified(__('manage.notifications.duplicated'));

    $copia = Event::query()->where('venue_id', $this->venue->id)->whereKeyNot($this->evento->id)->latest('id')->firstOrFail();
    expect($copia->status)->toBe(EventStatus::Draft)
        ->and($copia->editorial_score)->toBe(0)
        ->and($copia->is_featured)->toBeFalse()
        ->and($this->evento->fresh()->editorial_score)->toBe(40);
});

it('elimina un evento dalla scheda: sparisce da sito, giornata, pagina del locale e pannello', function (): void {
    $slug = $this->evento->slug;
    geSitoPubblico();
    $this->get('/eventi/'.$slug)->assertOk();

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->evento->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(EventResource::getUrl('index'));

    expect(Event::query()->find($this->evento->id))->toBeNull()
        ->and(Event::withTrashed()->find($this->evento->id)?->trashed())->toBeTrue();

    $this->get('/gestione/'.$this->venue->slug.'/eventi')->assertOk()->assertDontSee('Concerto di musica popolare');
    $this->get('/gestione/'.$this->venue->slug.'/eventi/'.$this->evento->getRouteKey())->assertNotFound();

    geSitoPubblico();
    $this->get('/eventi/'.$slug)->assertNotFound();
    $this->get('/eventi/'.$slug.'/'.$this->scenario->occurrenceA->url_number)->assertNotFound();
    $this->get('/eventi/2026-09-11')->assertOk()->assertDontSee('Concerto di musica popolare');
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertDontSee('Concerto di musica popolare');
    $this->getJson('/api/v1/events/'.$slug)->assertNotFound();
});

it('lascia eliminare anche al collaboratore, ma solo gli eventi del suo locale', function (): void {
    $editor = $this->scenario->editorA;
    geEntra($editor, $this->venue, '/eventi/'.$this->scenario->draftEventA->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->scenario->draftEventA->getRouteKey()])
        ->assertActionVisible('delete')
        ->callAction('delete');

    expect(Event::query()->find($this->scenario->draftEventA->id))->toBeNull()
        // La cancellazione definitiva non è mai del locale.
        ->and($editor->can('forceDelete', Event::withTrashed()->find($this->scenario->draftEventA->id)))->toBeFalse()
        ->and($editor->can('delete', $this->scenario->publishedEventB))->toBeFalse();

    $this->get('/gestione/'.$this->venue->slug.'/eventi/'.$this->scenario->publishedEventB->getRouteKey().'/modifica')->assertNotFound();
    expect($this->scenario->publishedEventB->fresh())->not->toBeNull();
});
