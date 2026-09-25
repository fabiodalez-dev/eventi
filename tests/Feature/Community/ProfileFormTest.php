<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Models\User;
use App\Models\Venue;
use App\Services\Community\Community;

/**
 * Il modulo del profilo pubblico: quanto lavoro chiede, e quando lo dice.
 *
 * Tre difetti dello stesso tipo — informazione che esiste ma arriva tardi o non
 * arriva: il nome utente già preso lo si scopriva inviando il modulo, il nome
 * pubblico si riscriveva a mano pur avendolo già dato all'iscrizione, e i locali
 * consigliati erano un elenco di caselle senza modo di cercarli.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    $this->user = User::factory()->create(['first_name' => 'Chiara', 'last_name' => 'Bersani']);
    /* Il modulo esiste solo per chi può partecipare: senza numero verificato la
       pagina mostra l'invito a verificarlo, non i campi. */
    $this->user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'profilo-form')])->save();
    $this->user = $this->user->fresh();
});

/** Un'altra persona che può già partecipare: creare un profilo lo richiede. */
function personaVerificata(): User
{
    $persona = User::factory()->create();
    $persona->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'altra-'.$persona->id)])->save();

    return $persona->fresh();
}

it('dice subito se un nome utente è già preso, con le regole del salvataggio', function (): void {
    $altra = personaVerificata();
    app(Community::class)->profile($altra, ['handle' => 'chi_c_era_prima',
        'display_name' => 'Chi c’era prima', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $chiedi = fn (string $handle) => $this->actingAs($this->user)
        ->getJson(route('community.handle', ['handle' => $handle]))->assertOk();

    $chiedi('chi_c_era_prima')->assertJsonPath('data.available', false);
    $chiedi('nome_libero')->assertJsonPath('data.available', true)->assertJsonPath('data.reason', null);

    /* Le regole sono quelle di `ProfileRequest`, non una copia: troppo corto e
       caratteri non ammessi devono risultare non disponibili qui esattamente
       come lo sarebbero al salvataggio. */
    $chiedi('ab')->assertJsonPath('data.available', false);
    $chiedi('Nome Con Spazi')->assertJsonPath('data.available', false);
    $chiedi('')->assertJsonPath('data.available', false)->assertJsonPath('data.reason', null);
});

it('non considera occupato il proprio nome utente', function (): void {
    app(Community::class)->profile($this->user, ['handle' => 'il_mio_nome',
        'display_name' => 'Chiara', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->user->fresh())->getJson(route('community.handle', ['handle' => 'il_mio_nome']))
        ->assertOk()->assertJsonPath('data.available', true);
});

it('quello che dice disponibile, il salvataggio lo accetta', function (): void {
    /* È la garanzia che regge tutto il resto: un «disponibile» smentito dal
       salvataggio sarebbe peggio del silenzio di prima. */
    $this->actingAs($this->user)->getJson(route('community.handle', ['handle' => 'chiara_b']))
        ->assertOk()->assertJsonPath('data.available', true);

    $this->actingAs($this->user)->post(route('community.settings.update'), [
        'handle' => 'chiara_b', 'display_name' => 'Chiara', 'visibility' => ProfileVisibility::Public->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('community_profiles', ['user_id' => $this->user->getKey(), 'handle' => 'chiara_b']);
});

it('chiede di accedere prima di rispondere sui nomi utente', function (): void {
    $this->get(route('community.handle', ['handle' => 'qualsiasi']))->assertRedirect();
});

it('propone il nome di battesimo come nome pubblico, e non il cognome', function (): void {
    $this->actingAs($this->user)->get(route('community.settings'))->assertOk()
        ->assertSee('value="Chiara"', false)
        ->assertDontSee('Bersani');
});

it('non sovrascrive il nome pubblico di chi ha già un profilo', function (): void {
    app(Community::class)->profile($this->user, ['handle' => 'come_mi_chiamo',
        'display_name' => 'Come mi chiamo io', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->user->fresh())->get(route('community.settings'))->assertOk()
        ->assertSee('value="Come mi chiamo io"', false)
        ->assertDontSee('value="Chiara"', false);
});

it('offre la ricerca dei locali tenendo le caselle che funzionano senza JavaScript', function (): void {
    $locale = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Centro Sociale Pedro']);
    $this->user->follows()->create(['followable_type' => 'venue', 'followable_id' => $locale->getKey()]);

    $this->actingAs($this->user->fresh())->get(route('community.settings'))->assertOk()
        ->assertSee('data-locali-input', false)
        ->assertSee('data-locali-caselle', false)
        ->assertSee(__('community.venue_search'))
        // La casella resta nel documento: è lei a essere inviata.
        ->assertSee('name="venue_ids[]" value="'.$locale->getKey().'"', false);
});

it('non disegna la ricerca a chi non segue nessun locale', function (): void {
    $this->actingAs($this->user)->get(route('community.settings'))->assertOk()
        ->assertSee(__('community.no_venues'))
        ->assertDontSee('data-locali-input', false);
});
