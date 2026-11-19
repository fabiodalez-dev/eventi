<?php

declare(strict_types=1);

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\VenueApplications\Pages\EditVenueApplication;
use App\Filament\Admin\Resources\VenueApplications\Pages\ListVenueApplications;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\VenueApplicationApproved;
use App\Notifications\VenueApplicationRejected;
use App\Services\Geo\AddressGeocoder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * La coda delle richieste in admin: si decide dalla pagina che si legge, con
 * «Approva» e «Rifiuta» nell'intestazione e nella riga, mai con una select
 * dello stato che salta l'approvazione.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->admin = User::factory()->create(['name' => 'Redazione Centrale']);
    $this->admin->assignRole(UserRole::Admin->value);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

/** La redazione apre la pagina come farebbe il browser, poi parla con Livewire. */
function revisioneApri(User $chi, string $url): void
{
    test()->actingAs($chi)->get($url)->assertOk();
    Filament::setCurrentPanel('admin');
}

/** Una richiesta di locale nuovo mandata da un account con l'email confermata. */
function revisioneRichiesta(array $overrides = []): VenueApplication
{
    $mittente = User::factory()->create(['email' => 'gestore@bottega.example', 'name' => 'Giulia Gestore']);

    return VenueApplication::factory()->create([
        'user_id' => $mittente->id,
        'kind' => ApplicationKind::Venue,
        'venue_name' => 'Bottega del Jazz',
        'contact_name' => 'Giulia Gestore',
        'contact_email' => 'gestore@bottega.example',
        'contact_phone' => '+39 333 1111111',
        'venue_data' => ['website' => 'https://bottega.example', 'public_email' => 'info@bottega.example', 'capacity' => 80],
        ...$overrides,
    ]);
}

it('non ha più select di stato e locale: si scrivono solo le note, e salvarle non è una revisione', function (): void {
    $richiesta = revisioneRichiesta();
    revisioneApri($this->admin, VenueApplicationResource::getUrl('edit', ['record' => $richiesta]));

    Livewire::test(EditVenueApplication::class, ['record' => $richiesta->getKey()])
        ->assertFormFieldDoesNotExist('status')
        ->assertFormFieldDoesNotExist('venue_id')
        ->assertFormFieldExists('notes')
        ->assertActionVisible('approva')
        ->assertActionVisible('rifiuta')
        ->fillForm(['notes' => 'Telefonato, sembra tutto in ordine.'])
        ->call('save')
        ->assertHasNoFormErrors();

    $richiesta->refresh();
    expect($richiesta->notes)->toBe('Telefonato, sembra tutto in ordine.')
        ->and($richiesta->status)->toBe(ApplicationStatus::Pending)
        ->and($richiesta->reviewed_by)->toBeNull()
        ->and($richiesta->venue_id)->toBeNull();
});

it('mostra i dati nuovi della richiesta: sito, contatti pubblici, account collegato', function (): void {
    $richiesta = revisioneRichiesta();

    $this->actingAs($this->admin)->get(VenueApplicationResource::getUrl('edit', ['record' => $richiesta]))
        ->assertOk()
        ->assertSee('Bottega del Jazz')
        ->assertSee('https://bottega.example')
        ->assertSee('info@bottega.example')
        ->assertSee(__('venue_applications.admin.account_verified'))
        ->assertSee(__('venue_applications.admin.sections.public_contacts'))
        ->assertDontSee(__('venue_applications.admin.possible_duplicate'));
});

it('approva dall’intestazione della pagina: crea il locale e consegna l’accesso a chi ha scritto', function (): void {
    $richiesta = revisioneRichiesta();
    revisioneApri($this->admin, VenueApplicationResource::getUrl('edit', ['record' => $richiesta]));

    Livewire::test(EditVenueApplication::class, ['record' => $richiesta->getKey()])
        ->callAction('approva')
        ->assertHasNoActionErrors()
        ->assertActionHidden('approva')
        ->assertActionHidden('rifiuta');

    $locale = Venue::query()->sole();
    $mittente = $richiesta->user->fresh();
    expect($locale->name)->toBe('Bottega del Jazz')
        ->and($locale->status)->toBe(VenueStatus::Draft)
        ->and($locale->website)->toBe('https://bottega.example')
        ->and($locale->email)->toBe('info@bottega.example')
        ->and($richiesta->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($richiesta->fresh()->venue_id)->toBe($locale->id)
        ->and($richiesta->fresh()->reviewed_by)->toBe($this->admin->id)
        ->and($mittente->isOwnerOfVenue((int) $locale->id))->toBeTrue()
        ->and(DB::table('venue_user')->where('venue_id', $locale->id)->where('user_id', $mittente->id)->value('accepted_at'))->not->toBeNull();
    Notification::assertSentTo($mittente, VenueApplicationApproved::class);
});

it('non approva due volte: la pagina letta prima di un’approvazione non crea un secondo locale', function (): void {
    $richiesta = revisioneRichiesta();
    revisioneApri($this->admin, VenueApplicationResource::getUrl('edit', ['record' => $richiesta]));

    $pagina = Livewire::test(EditVenueApplication::class, ['record' => $richiesta->getKey()]);

    // Nel frattempo, da un'altra scheda, qualcuno l'ha già approvata.
    $locale = Venue::factory()->create(['city_id' => $this->city->id, 'name' => 'Bottega del Jazz']);
    VenueApplication::query()->whereKey($richiesta->getKey())->update(['status' => ApplicationStatus::Approved, 'venue_id' => $locale->id]);

    $pagina->callAction('approva');

    expect(Venue::query()->count())->toBe(1)
        ->and($richiesta->fresh()->venue_id)->toBe($locale->id);
    Notification::assertNotSentTo($richiesta->user, VenueApplicationApproved::class);
});

it('rifiuta con il motivo interno e, solo se lo si sceglie, avvisa l’account che ha scritto', function (): void {
    $prima = revisioneRichiesta();
    revisioneApri($this->admin, VenueApplicationResource::getUrl('edit', ['record' => $prima]));

    Livewire::test(EditVenueApplication::class, ['record' => $prima->getKey()])
        ->callAction('rifiuta', data: ['notes' => 'Locale fuori città', 'notify' => true, 'message' => 'Per ora seguo solo Padova.'])
        ->assertHasNoActionErrors();

    $prima->refresh();
    expect($prima->status)->toBe(ApplicationStatus::Rejected)
        ->and($prima->notes)->toBe('Locale fuori città')
        ->and($prima->reviewed_by)->toBe($this->admin->id)
        ->and(Venue::query()->count())->toBe(0);
    Notification::assertSentTo($prima->user, VenueApplicationRejected::class, function (VenueApplicationRejected $avviso) use ($prima): bool {
        $mail = $avviso->toMail($prima->user);

        return collect($mail->introLines)->contains('Per ora seguo solo Padova.')
            && ! collect($mail->introLines)->contains('Locale fuori città');
    });

    $seconda = VenueApplication::factory()->create(['venue_name' => 'Circolo Silenzioso']);
    Livewire::test(ListVenueApplications::class)
        ->callTableAction('rifiuta', $seconda, data: ['notes' => 'Doppione di una richiesta già valutata'])
        ->assertHasNoTableActionErrors();

    expect($seconda->fresh()->status)->toBe(ApplicationStatus::Rejected);
    Notification::assertNotSentTo($seconda->user, VenueApplicationRejected::class);
});

it('apre la coda su quelle da valutare, senza quelle in attesa dell’email, e segnala i possibili doppioni', function (): void {
    $omonimo = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Bottega del Jazz']);
    $doppione = revisioneRichiesta();
    $nuova = VenueApplication::factory()->create(['venue_name' => 'Osteria Nuova']);
    $accolta = VenueApplication::factory()->approved()->create(['venue_name' => 'Già Accolta']);
    $daConfermare = VenueApplication::factory()->create(['venue_name' => 'Da Confermare', 'email_confirmed_at' => null]);

    revisioneApri($this->admin, VenueApplicationResource::getUrl());

    Livewire::test(ListVenueApplications::class)
        ->assertCanSeeTableRecords([$doppione, $nuova])
        ->assertCanNotSeeTableRecords([$accolta])
        ->assertTableColumnStateSet('possible_duplicate', __('venue_applications.admin.possible_duplicate'), $doppione)
        ->assertTableColumnStateNotSet('possible_duplicate', __('venue_applications.admin.possible_duplicate'), $nuova)
        ->assertTableActionVisible('approva', $nuova)
        ->assertTableActionVisible('rifiuta', $nuova)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$doppione, $nuova, $accolta])
        ->assertTableActionHidden('approva', $accolta);

    expect(VenueApplication::query()->whereKey($daConfermare->getKey())->exists())->toBeFalse();
    $this->actingAs($this->admin)->get(VenueApplicationResource::getUrl('edit', ['record' => $doppione]))
        ->assertOk()
        ->assertSee(__('venue_applications.admin.possible_duplicate'))
        ->assertSee(__('venue_applications.admin.duplicate_venue', ['name' => 'Bottega del Jazz', 'address' => $omonimo->address ?: '—']), escape: true);
});

it('serve l’immagine privata della richiesta a chi la valuta e a chi l’ha mandata, a nessun altro', function (): void {
    /* Un disco tutto suo: `Storage::fake('local')` usa una cartella fissa che
       altri test in parallelo svuotano. */
    $radice = storage_path('framework/testing/disks/claim-admin-'.getmypid());
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => $radice]));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    Storage::disk('local')->put('venue-applications/prova.png', (string) $png);

    $richiesta = revisioneRichiesta(['image_path' => 'venue-applications/prova.png']);
    $estraneo = User::factory()->create();
    $url = route('venue-applications.image', $richiesta->getKey());

    $this->actingAs($this->admin)->get(VenueApplicationResource::getUrl('edit', ['record' => $richiesta]))
        ->assertOk()->assertSee($url, escape: false);
    $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->flushSession();
    $this->actingAs($richiesta->user)->get($url)->assertOk();
    $this->flushSession();
    $this->actingAs($estraneo)->get($url)->assertNotFound();
    auth()->logout();
    $this->flushSession();
    $this->get($url)->assertRedirect();

    File::deleteDirectory($radice);
});

it('mostra la data di revisione e quella dei doppioni nel fuso della redazione, come le altre date della scheda', function (): void {
    $richiesta = revisioneRichiesta([
        'status' => ApplicationStatus::Rejected,
        'reviewed_by' => $this->admin->id,
        'reviewed_at' => '2026-11-19 07:00:00',
    ]);

    $this->actingAs($this->admin)->get(VenueApplicationResource::getUrl('edit', ['record' => $richiesta]))
        ->assertOk()
        ->assertSee(__('venue_applications.admin.reviewed', ['name' => 'Redazione Centrale', 'date' => '19/11/2026 08:00']))
        ->assertDontSee('19/11/2026 07:00');

    // Arrivata alle 23:30 UTC del 18: a Roma è già il 19.
    $altra = VenueApplication::factory()->create(['venue_name' => 'Bottega del Jazz', 'contact_name' => 'Altra Persona']);
    DB::table('venue_applications')->where('id', $altra->id)->update(['created_at' => '2026-11-18 23:30:00']);
    $nuova = VenueApplication::factory()->create(['venue_name' => 'Bottega del Jazz', 'contact_name' => 'Terza Persona']);

    $labels = collect(VenueApplicationResource::duplicates($nuova))->pluck('label');
    expect($labels->first(fn (string $label): bool => str_contains($label, 'Altra Persona')))->toContain('19/11/2026');
});
