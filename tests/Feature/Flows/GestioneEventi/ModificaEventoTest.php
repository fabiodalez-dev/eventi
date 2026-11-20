<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Support\Media\ImageSet;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge le correzioni che un locale fa su un evento già in pagina:
 * titolo, date, prezzi, locandina, formazione, un luogo diverso dal locale,
 * «tutto esaurito». Ogni modifica si salva dal pannello come la salverebbe il
 * gestore e si verifica dove la legge il pubblico, perché è lì che una
 * correzione mancata fa danni: un orario vecchio, un prezzo che non c'è più.
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

function geModifica(Event $event, array $dati): void
{
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm($dati)
        ->call('save')
        ->assertHasNoFormErrors();
}

it('cambia il titolo di un evento online senza rompere l’indirizzo già condiviso', function (): void {
    $slug = $this->evento->slug;

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geModifica($this->evento, ['title' => 'Concerto di musica popolare veneta']);

    $this->evento->refresh();
    expect($this->evento->title)->toBe('Concerto di musica popolare veneta')
        ->and($this->evento->status)->toBe(EventStatus::Published)
        ->and($this->evento->slug)->toBe($slug);

    geSitoPubblico();
    $this->get('/eventi/'.$slug)->assertOk()->assertSee('Concerto di musica popolare veneta');
    $this->get('/eventi/2026-09-11')->assertOk()->assertSee('Concerto di musica popolare veneta');
});

it('sposta una data: il giorno vecchio si svuota, quello nuovo la mostra, la pagina della data resta', function (): void {
    $data = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geDate($this->evento)
        ->callTableAction('edit', $data, data: ['starts_at' => '2026-09-19 21:30:00'])
        ->assertHasNoTableActionErrors();

    $data->refresh();
    expect(geOraLocale($data))->toBe('2026-09-19 21:30')
        ->and($data->business_date->toDateString())->toBe('2026-09-19')
        ->and($data->previous_starts_at)->not->toBeNull();

    geSitoPubblico();
    $this->get('/eventi/2026-09-11')->assertOk()->assertDontSee($this->evento->title);
    $this->get('/eventi/2026-09-19')->assertOk()->assertSee($this->evento->title);
    $this->get('/eventi/'.$this->evento->slug.'/'.$data->url_number)->assertOk()->assertSee($this->evento->title);
});

it('passa da gratuito a pagamento e ritorno senza lasciare importi appesi', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geModifica($this->evento, ['price_type' => PriceType::Ticket->value, 'price_min' => '18', 'price_max' => null]);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertSee(Number::currency(18, in: 'EUR', locale: 'it', precision: 0));

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geModifica($this->evento, ['price_type' => PriceType::Free->value]);

    $this->evento->refresh();
    expect($this->evento->price_type)->toBe(PriceType::Free)
        ->and($this->evento->price_min)->toBeNull()
        ->and($this->evento->price_max)->toBeNull();

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()
        ->assertSee(__('events.price.free'))
        ->assertDontSee(Number::currency(18, in: 'EUR', locale: 'it', precision: 0));
});

it('carica la locandina dal modulo di creazione e la mostra sulla scheda pubblica e nel pannello', function (): void {
    Storage::fake('media');
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Mostra di incisioni',
            'poster_media' => [UploadedFile::fake()->image('locandina.png', 900, 1200)],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Mostra di incisioni')->sole();
    $locandina = $evento->getFirstMedia('poster');
    expect($locandina)->not->toBeNull()
        ->and($evento->getMedia('poster'))->toHaveCount(1);

    $this->get('/gestione/'.$this->venue->slug.'/eventi/'.$evento->getRouteKey())->assertOk()->assertSee('data-event-poster', false);

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()
        ->assertSee('data-poster-reveal', false)
        ->assertSee(ImageSet::fromMedia($locandina)->src, false);
});

it('porta l’evento in un luogo diverso dal locale e lo riporta a casa', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geModifica($this->evento, ['custom_location' => ['name' => 'Giardino dei Giusti', 'address' => 'Via dei Giardini 3, Padova', 'lat' => '45.4012', 'lng' => '11.8760']]);

    $this->evento->refresh();
    expect($this->evento->venue_id)->toBe($this->venue->id)
        ->and($this->evento->custom_location['address'])->toBe('Via dei Giardini 3, Padova')
        ->and($this->evento->custom_location['lat'])->toBe(45.4012);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertSee('Giardino dei Giusti')->assertSee('Via dei Giardini 3, Padova');

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geModifica($this->evento, ['custom_location' => ['name' => null, 'address' => null, 'lat' => null, 'lng' => null]]);
    expect($this->evento->fresh()->custom_location)->toBeNull();

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertDontSee('Via dei Giardini 3, Padova')->assertSee('Circolo Aurora');
});

it('manda in revisione il nuovo luogo di un locale che non pubblica da sé', function (): void {
    $this->venue->update(['auto_publish' => false]);
    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->evento->getRouteKey()])
        ->fillForm(['custom_location' => ['name' => 'Piazza delle Erbe', 'address' => 'Piazza delle Erbe, Padova', 'lat' => null, 'lng' => null]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('manage.notifications.sent_to_review'));

    expect($this->evento->fresh()->status)->toBe(EventStatus::Pending);
    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertNotFound();
});

it('segna esaurita la prossima data dall’elenco e la rimette in vendita, solo quella', function (): void {
    $seconda = geData($this->evento, '2026-09-18 21:00');
    $prima = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)
        ->callTableAction('soldOutNextDate', $this->evento)
        ->assertNotified(trans_choice('manage.notifications.dates_updated', 1, ['count' => 1]));

    expect($prima->fresh()->status)->toBe(OccurrenceStatus::SoldOut)
        ->and($seconda->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    geSitoPubblico();
    $this->get('/eventi/2026-09-11')->assertOk()->assertSee(__('events.badge.sold_out'));
    $this->get('/eventi/2026-09-18')->assertOk()->assertSee($this->evento->title)->assertDontSee(__('events.badge.sold_out'));

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)->callTableAction('soldOutNextDate', $this->evento);
    expect($prima->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('aggiunge chi suona a una data e il nome compare sulla scheda pubblica', function (): void {
    $data = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geDate($this->evento)
        ->mountTableAction('edit', $data)
        ->setTableActionData(['lineups' => [['name' => 'Trio Euganeo', 'role' => LineupRole::Live->value]]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($data->fresh()->lineups()->pluck('name')->all())->toBe(['Trio Euganeo']);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk()->assertSee('Trio Euganeo');
});

it('toglie una data dalla sezione «Date» e la sua pagina sparisce, le altre restano', function (): void {
    $seconda = geData($this->evento, '2026-09-18 21:00');
    $prima = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi/'.$this->evento->getRouteKey().'/modifica');
    geDate($this->evento)->callTableAction('delete', $seconda)->assertHasNoTableActionErrors();

    expect($this->evento->occurrences()->pluck('id')->all())->toBe([$prima->id]);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug.'/'.$seconda->url_number)->assertNotFound();
    $this->get('/eventi/'.$this->evento->slug.'/'.$prima->url_number)->assertOk();
    $this->get('/eventi/2026-09-18')->assertOk()->assertDontSee($this->evento->title);
});
