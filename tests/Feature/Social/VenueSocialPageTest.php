<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Exceptions\SocialStudioException;
use App\Filament\Admin\Pages\Social as AdminSocial;
use App\Filament\Concerns\InteractsWithSocialStudio;
use App\Filament\Venue\Pages\Social as VenueSocial;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\Venue;
use App\Services\Social\SocialGraphic;
use App\Services\Social\SocialStudio;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * La pagina Social del pannello del locale elenca le serate di un periodo a
 * card, con la locandina: sceglierne una sposta il giorno delle grafiche e
 * la preseleziona. Gli eventi dimostrativi del locale compaiono con il loro
 * distintivo; il carosello automatico della redazione continua a ignorarli.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 12:00');
    $this->demo = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-20 21:00',
        event: ['is_demo' => true, 'title' => 'Serata dimostrativa del portico'], venue: $this->s->venueA);
});

afterEach(fn () => Carbon::setTestNow());

function openVenueSocial($test): Testable
{
    $test->actingAs($test->s->ownerA)->get('/gestione/'.$test->s->venueA->slug.'/social')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($test->s->venueA);

    return Livewire::test(VenueSocial::class);
}

it('elenca le serate dei prossimi 30 giorni a card, con locandina e distintivo dei dimostrativi', function (): void {
    $page = openVenueSocial($this)
        ->assertSet('period', 'next30')
        ->assertSee('data-social-card="'.$this->s->occurrenceA->id.'"', false)
        ->assertSee('data-social-card="'.$this->demo->id.'"', false)
        ->assertSee('Serata dimostrativa del portico')
        ->assertSee(__('social.studio.demo'))
        ->assertSee('data-social-poster', false)
        ->assertSee('images/poster-placeholder.svg', false)
        // Le serate di un altro locale non compaiono mai.
        ->assertDontSee('data-social-card="'.$this->s->occurrenceB->id.'"', false)
        // Niente colori scritti a mano né stili in linea nello studio.
        ->assertDontSee('#F5F5F0', false)->assertDontSee('style="max-width', false)->assertDontSee('#b42318', false);

    $page->call('setPeriod', 'today')->assertDontSee('data-social-card="'.$this->demo->id.'"', false)
        ->call('setPeriod', 'week')->assertSee('data-social-card="'.$this->s->occurrenceA->id.'"', false)
        ->assertDontSee('data-social-card="'.$this->demo->id.'"', false)
        ->set('search', 'dimostrativa')->call('setPeriod', 'next30')
        ->assertSee('data-social-card="'.$this->demo->id.'"', false)
        ->assertDontSee('data-social-card="'.$this->s->occurrenceA->id.'"', false);
});

it('si apre sulla prima serata in arrivo quando oggi non ce ne sono', function (): void {
    openVenueSocial($this)
        ->assertSet('date', '2026-09-11')
        ->assertSet('selected', [$this->s->occurrenceA->id]);
});

it('scegliendo una card imposta il giorno, preseleziona la serata e prepara le grafiche', function (): void {
    $page = openVenueSocial($this)
        ->call('pick', $this->demo->id)
        ->assertSet('date', '2026-09-20')
        ->assertSet('selected', [$this->demo->id])
        ->call('generate')
        ->assertHasNoErrors();

    $batch = SocialBatch::query()->sole();
    expect($batch->venue_id)->toBe($this->s->venueA->id)
        ->and($batch->date->format('Y-m-d'))->toBe('2026-09-20')
        ->and($batch->items)->toHaveCount(1)
        ->and($page->get('batchId'))->toBe($batch->id);

    // Una seconda scelta dello stesso giorno la toglie dalla selezione.
    $page->call('pick', $this->demo->id)->assertSet('selected', []);
});

it('il calendario conta le serate e un giorno scelto diventa il periodo', function (): void {
    $page = openVenueSocial($this);
    $days = collect($page->instance()->calendarDays())->keyBy('date');
    expect($days['2026-09-11']['count'])->toBe(1)
        ->and($days['2026-09-20']['count'])->toBe(1)
        ->and($days['2026-09-12']['count'])->toBe(0);

    $page->call('pickDay', '2026-09-20')
        ->assertSet('period', 'custom')->assertSet('from', '2026-09-20')->assertSet('until', '2026-09-20')
        ->assertSet('selected', [$this->demo->id])
        ->assertDontSee('data-social-card="'.$this->s->occurrenceA->id.'"', false);
});

it('rifiuta serate di altri locali e serate che non si possono preparare', function (): void {
    $cancelled = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-22 21:00', occurrence: ['status' => OccurrenceStatus::Cancelled], venue: $this->s->venueA);
    openVenueSocial($this)
        ->assertSee(__('social.studio.not_available'))
        ->call('pick', $this->s->occurrenceB->id)->assertForbidden();
    openVenueSocial($this)->call('pick', $cancelled->id)->assertForbidden();
});

it('lo studio della redazione e il carosello automatico continuano a escludere i dimostrativi', function (): void {
    $this->actingAs($this->s->admin);
    Livewire::test(AdminSocial::class)
        ->assertSee('data-social-card="'.$this->s->occurrenceA->id.'"', false)
        ->assertDontSee('data-social-card="'.$this->demo->id.'"', false);

    SocialConnection::create(['city_id' => $this->s->city->id, 'page_id' => '123', 'access_token' => 'secret', 'facebook_enabled' => true, 'automatic' => true, 'publish_time' => '09:00', 'caption' => ':data :eventi :link', 'verified_at' => now()]);
    freezeLocal($this->s->city, '2026-09-20 12:00');
    $this->artisan('social:daily')->assertSuccessful();
    expect(SocialBatch::query()->count())->toBe(0)->and(SocialPublication::query()->count())->toBe(0);
});

it('un periodo non valido torna a quello predefinito invece di rompere la pagina', function (): void {
    openVenueSocial($this)
        ->set('from', '')
        ->assertOk()
        ->assertSet('period', 'next30')
        ->assertSee('data-social-card="'.$this->demo->id.'"', false);
});

it('scrive il periodo dello studio in parole', function (): void {
    $page = new class
    {
        use InteractsWithSocialStudio;

        public function socialVenue(): ?Venue
        {
            return null;
        }
    };
    $city = testCity();
    freezeLocal($city, '2026-11-19 12:00');
    $page->cityId = $city->id;
    $page->from = '2026-11-19';
    $page->until = '2026-11-22';
    expect($page->periodLabel())->toBe('da oggi a dom 22 nov');
    $page->until = '2026-11-19';
    expect($page->periodLabel())->toBe('oggi');
    $page->from = $page->until = '2026-11-22';
    expect($page->periodLabel())->toBe('solo dom 22 nov');
});

/** Sostituisce il disegno delle grafiche (`SocialGraphic` è final, non si simula con Mockery). */
function venueSocialGraphic(Closure $render): void
{
    app()->instance(SocialGraphic::class, new class($render)
    {
        public function __construct(private readonly Closure $render) {}

        public function render(mixed ...$arguments): string
        {
            return ($this->render)(...$arguments);
        }
    });
}

it('nel pannello tiene solo i dodici pacchetti più recenti del locale e lo ferma a trenta in 24 ore', function (): void {
    venueSocialGraphic(fn (): string => 'jpeg');
    $page = openVenueSocial($this)->assertSet('selected', [$this->s->occurrenceA->id]);

    foreach (range(1, SocialStudio::DAILY_LIMIT_PER_VENUE) as $ignored) {
        $page->call('generate')->assertHasNoErrors();
    }
    expect(SocialBatch::query()->where('venue_id', $this->s->venueA->id)->count())->toBe(12)
        ->and(Storage::disk('local')->directories('social'))->toHaveCount(12);

    $page->call('generate')
        ->assertHasErrors(['generate'])
        ->assertNotified(__('social.daily_limit', ['count' => 30]));
    expect(SocialBatch::query()->where('venue_id', $this->s->venueA->id)->count())->toBe(12);
});

it('nel pannello mostra gli errori previsti della grafica e lascia gli altri al gestore degli errori', function (): void {
    venueSocialGraphic(fn () => throw SocialStudioException::textOverflow());
    openVenueSocial($this)->call('generate')
        ->assertHasErrors(['generate'])
        ->assertNotified(__('social.text_overflow'));

    venueSocialGraphic(fn () => throw new RuntimeException('SQLSTATE[42S02]: select * from `segreta`'));
    expect(fn () => openVenueSocial($this)->call('generate'))->toThrow(RuntimeException::class, 'SQLSTATE');
    expect(SocialBatch::query()->count())->toBe(0);
});
