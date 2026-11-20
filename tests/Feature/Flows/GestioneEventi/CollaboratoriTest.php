<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Actions\InviteVenueMemberAction;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueAccessGranted;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge il lavoro a più mani su un locale: il referente invita, la persona
 * accetta dal collegamento e lavora sugli eventi, il referente ritira un
 * invito o toglie un collaboratore. Ogni passo controlla che cosa può fare
 * chi è appena entrato o appena uscito — e che gli eventi scritti da un
 * collaboratore restino del locale anche dopo che se n'è andato.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    Notification::fake();
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function geInvita(User $referente, Venue $venue, string $email, string $nome): void
{
    geEntra($referente, $venue, '/collaboratori');
    Livewire::test(Collaborators::class)
        ->callTableAction('invite', data: ['name' => $nome, 'email' => $email])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('manage.collaborators.invited'));
}

/** L'ultimo collegamento «Accetta l'invito» arrivato alla persona. */
function geCollegamentoInvito(User $invitato): string
{
    $url = null;
    Notification::assertSentTo($invitato, VenueAccessGranted::class, function (VenueAccessGranted $avviso) use ($invitato, &$url): bool {
        $url = $avviso->toMail($invitato)->actionUrl;

        return true;
    });

    return (string) $url;
}

it('fa lavorare la collaboratrice appena entrata sugli eventi del locale, che il referente ritrova', function (): void {
    $giulia = User::factory()->create(['name' => 'Giulia Rossi', 'email' => 'giulia.rossi@esempio.test']);
    geInvita($this->owner, $this->venue, 'giulia.rossi@esempio.test', 'Giulia Rossi');
    geFuoriDalPannello();
    $this->actingAs($giulia)->post(geCollegamentoInvito($giulia), ['decision' => 'accept'])->assertRedirect();

    // Corregge un evento scritto dal referente…
    geEntra($giulia->fresh(), $this->venue, '/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $this->scenario->publishedEventA->getRouteKey()])
        ->fillForm(['price_type' => 'ticket', 'price_min' => '7'])
        ->call('save')
        ->assertHasNoFormErrors();

    // …e ne scrive uno suo, che va in redazione come quelli del referente.
    geEntra($giulia, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Laboratorio di ceramica']))
        ->call('create')
        ->assertHasNoFormErrors();
    $suo = Event::query()->where('title', 'Laboratorio di ceramica')->sole();
    expect($suo->created_by)->toBe($giulia->id)
        ->and($suo->venue_id)->toBe($this->venue->id)
        ->and($suo->status)->toBe(EventStatus::Pending)
        ->and((float) $this->scenario->publishedEventA->fresh()->price_min)->toBe(7.0);

    // Il referente vede tutto nel proprio elenco, e la collaboratrice nella pagina delle persone.
    geEntra($this->owner, $this->venue, '/eventi');
    $this->get('/gestione/'.$this->venue->slug.'/eventi')->assertSee('Laboratorio di ceramica');
    geEntra($this->owner, $this->venue, '/collaboratori');
    Livewire::test(Collaborators::class)->assertSee('Giulia Rossi')->assertSee('giulia.rossi@esempio.test');
});

it('dà al collaboratore gli eventi ma non le chiavi del locale', function (): void {
    $editor = $this->scenario->editorA;
    $base = '/gestione/'.$this->venue->slug;

    $this->actingAs($editor)->get($base.'/eventi')->assertOk();
    $this->actingAs($editor)->get($base.'/eventi/nuovo')->assertOk();
    $this->actingAs($editor)->get($base.'/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica')->assertOk();
    $this->actingAs($editor)->get($base.'/collaboratori')->assertForbidden();
    $this->actingAs($editor)->get($base.'/calendario')->assertForbidden();

    expect($editor->can('update', $this->venue))->toBeFalse()
        ->and($editor->can('manageCollaborators', $this->venue))->toBeFalse()
        ->and($editor->can('create', [Event::class, $this->venue]))->toBeTrue();
});

it('toglie un collaboratore: perde subito il pannello, i suoi eventi restano al locale', function (): void {
    $editor = $this->scenario->editorA;
    geEntra($editor, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Serata del collaboratore']))
        ->call('create')
        ->assertHasNoFormErrors();
    $suo = Event::query()->where('title', 'Serata del collaboratore')->sole();

    geEntra($this->owner, $this->venue, '/collaboratori');
    Livewire::test(Collaborators::class)
        ->callTableAction('remove', $editor)
        ->assertNotified(__('manage.collaborators.removed'));

    $editor = $editor->fresh();
    expect($editor)->not->toBeNull()
        ->and($editor->canAccessTenant($this->venue))->toBeFalse()
        ->and($editor->can('update', $suo))->toBeFalse();

    geFuoriDalPannello();
    $this->actingAs($editor)->get('/gestione/'.$this->venue->slug)->assertForbidden();
    $this->actingAs($editor)->get('/gestione/'.$this->venue->slug.'/eventi/'.$suo->getRouteKey().'/modifica')->assertForbidden();

    // L'evento resta del locale e il referente lo gestisce.
    expect($suo->fresh()->venue_id)->toBe($this->venue->id);
    geEntra($this->owner, $this->venue, '/eventi/'.$suo->getRouteKey().'/modifica');
    Livewire::test(EditEvent::class, ['record' => $suo->getRouteKey()])->fillForm(['title' => 'Serata ripresa dal referente'])->call('save')->assertHasNoFormErrors();
    expect($suo->fresh()->title)->toBe('Serata ripresa dal referente');
});

it('ritira un invito ancora in attesa e il collegamento già spedito non apre più niente', function (): void {
    $marco = User::factory()->create(['email' => 'marco@esempio.test']);
    geInvita($this->owner, $this->venue, 'marco@esempio.test', 'Marco Verdi');
    $url = geCollegamentoInvito($marco);

    Livewire::test(Collaborators::class)
        ->assertTableActionVisible('withdraw', $marco)
        ->assertTableActionHidden('remove', $marco)
        ->callTableAction('withdraw', $marco)
        ->assertNotified(__('manage.collaborators.withdrawn'));

    geFuoriDalPannello();
    $this->actingAs($marco)->post($url, ['decision' => 'accept'])->assertForbidden();
    expect($marco->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($marco->fresh()->hasRole(UserRole::VenueEditor->value))->toBeFalse();
    $this->actingAs($marco)->get('/gestione/'.$this->venue->slug)->assertForbidden();
});

it('lascia scadere un invito e lo fa ripartire con «Reinvia», che vale al posto del vecchio', function (): void {
    $sara = User::factory()->create(['email' => 'sara@esempio.test']);
    geInvita($this->owner, $this->venue, 'sara@esempio.test', 'Sara Neri');
    $vecchio = geCollegamentoInvito($sara);

    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    $this->travel(InviteVenueMemberAction::EXPIRES_DAYS + 1)->days();
    geFuoriDalPannello();
    expect($this->actingAs($sara)->post($vecchio, ['decision' => 'accept'])->status())->toBeIn([403, 410]);

    Notification::fake();
    geEntra($this->owner, $this->venue, '/collaboratori');
    Livewire::test(Collaborators::class)
        ->callTableAction('resend', $sara)
        ->assertNotified(__('manage.collaborators.resent'));
    $nuovo = geCollegamentoInvito($sara);
    expect($nuovo)->not->toBe($vecchio);

    geFuoriDalPannello();
    $this->actingAs($sara)->post($nuovo, ['decision' => 'accept'])->assertRedirect();
    expect($sara->fresh()->canAccessTenant($this->venue))->toBeTrue();
});

it('non reinvita né duplica chi è già del locale', function (): void {
    geEntra($this->owner, $this->venue, '/collaboratori');
    Notification::fake();
    Livewire::test(Collaborators::class)
        ->callTableAction('invite', data: ['name' => 'Di nuovo', 'email' => strtoupper((string) $this->scenario->editorA->email)])
        ->assertNotified(__('manage.collaborators.already_member'));

    Notification::assertNothingSent();
    expect($this->venue->membersIncludingInvited()->whereKey($this->scenario->editorA->id)->count())->toBe(1);
});
