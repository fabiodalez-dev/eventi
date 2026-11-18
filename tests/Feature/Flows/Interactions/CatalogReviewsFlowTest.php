<?php

declare(strict_types=1);

use App\Enums\CarpoolCaseStatus;
use App\Enums\CatalogReviewStatus;
use App\Models\CarpoolCase;
use App\Models\CatalogRating;
use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/*
 * Le recensioni di locali e organizzatori come le vive chi le scrive: dal
 * modulo della scheda o dall'app, in attesa di moderazione, pubblicate,
 * corrette, ritirate e segnalate da chi le trova offensive.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    $this->city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria dei Fiori']);
    $this->organizer = Organizer::create(['name' => 'Suoni in città', 'city_id' => $this->city->id, 'is_active' => true]);
    $this->reviews = app(CatalogReviews::class);
    $this->venuePage = route('venues.show', ['slug' => $this->venue->slug]);
    $this->organizerPage = route('organizers.show', ['slug' => $this->organizer->slug]);
});

/** Una persona che può partecipare alla community, con nome e cognome. */
function intFlowReviewer(string $first = 'Mario', string $last = 'Rossi'): User
{
    $user = carpoolPerson(false);
    $user->forceFill(['first_name' => $first, 'last_name' => $last, 'name' => $first.' '.$last])->save();

    return $user->fresh();
}

it('una recensione scritta dalla scheda del locale resta in attesa finché la moderazione non la pubblica', function (): void {
    $mario = intFlowReviewer();
    $giulia = intFlowReviewer('Giulia', 'Neri');
    $store = route('venues.review.store', ['slug' => $this->venue->slug]);

    $this->actingAs($mario)->get($this->venuePage)->assertOk()
        ->assertSee(__('reviews.empty'))->assertSee($store, false);

    $this->post($store, ['rating' => 5, 'body' => '  Cucina ottima e personale gentile.  ', 'revision' => 0])
        ->assertRedirect($this->venuePage.'#recensioni')->assertSessionHas('status', __('reviews.submitted'));

    $review = CatalogReview::query()->with('ratings')->sole();
    expect($review->status)->toBe(CatalogReviewStatus::Pending)
        ->and($review->approved)->toBeFalse()
        ->and($review->revision)->toBe(1)
        ->and($review->rating)->toBe(5)
        ->and($review->body)->toBe('Cucina ottima e personale gentile.')
        ->and($review->user_id)->toBe($mario->id);

    // Chi l'ha scritta vede lo stato; il pubblico non vede ancora nulla.
    $this->get($this->venuePage)->assertOk()->assertSee(__('reviews.pending'));
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/venues/'.$this->venue->slug.'/reviews')->assertOk()
        ->assertJsonPath('data.count', 0)->assertJsonPath('data.reviews', []);

    // Anche Giulia recensisce; la moderazione approva entrambe.
    $this->actingAs($giulia)->post($store, ['rating' => 4, 'body' => 'Buon vino, servizio un po\' lento.'])->assertRedirect();
    $staff = cpStaff();
    foreach (CatalogReview::query()->get() as $item) {
        $this->reviews->moderate($item, $staff, 'approved', $item->revision, null);
    }

    // Ora la scheda e l'API mostrano entrambe, con la media e il solo nome proprio.
    $this->app['auth']->forgetGuards();
    $this->get($this->venuePage)->assertOk()
        ->assertSee('Cucina ottima e personale gentile.')
        ->assertSee(__('reviews.average', ['rating' => 4.5]))
        ->assertDontSee('Rossi');
    $this->getJson('/api/v1/venues/'.$this->venue->slug.'/reviews')->assertOk()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.rated_count', 2)
        ->assertJsonPath('data.average', 4.5)
        ->assertJsonPath('data.can_review', false);
});

it('chi ha recensito il locale corregge il testo, viene fermato su una versione vecchia e poi la ritira', function (): void {
    $mario = intFlowReviewer();
    $store = route('venues.review.store', ['slug' => $this->venue->slug]);
    $destroy = route('venues.review.destroy', ['slug' => $this->venue->slug]);

    $this->actingAs($mario)->post($store, ['rating' => 3, 'body' => 'Serata piacevole ma rumorosa.', 'revision' => 0])->assertRedirect();
    $review = CatalogReview::query()->sole();
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $review->refresh();
    expect($review->revision)->toBe(2)->and($review->status)->toBe(CatalogReviewStatus::Approved);

    // La modifica riparte dalla moderazione con la revisione che vede nel modulo.
    $this->actingAs($mario)->get($this->venuePage)->assertOk()->assertSee('name="revision" value="2"', false);
    $this->post($store, ['rating' => 4, 'body' => 'Serata piacevole, ora hanno insonorizzato.', 'revision' => 2])
        ->assertRedirect($this->venuePage.'#recensioni');
    $review->refresh();
    expect($review->status)->toBe(CatalogReviewStatus::Pending)
        ->and($review->revision)->toBe(3)
        ->and($review->body)->toBe('Serata piacevole, ora hanno insonorizzato.')
        ->and(CatalogRating::query()->sole()->value)->toBe(4);

    // Il testo nuovo non è ancora pubblico.
    $this->app['auth']->forgetGuards();
    $this->get($this->venuePage)->assertOk()->assertDontSee('ora hanno insonorizzato');

    // Un modulo rimasto aperto su una versione vecchia viene respinto.
    $this->actingAs($mario)->from($this->venuePage)->post($store, ['rating' => 1, 'body' => 'Testo scritto su una versione vecchia.', 'revision' => 2])
        ->assertSessionHasErrors('revision');
    expect($review->fresh()->rating)->toBe(4);

    // Lo stesso invio ripetuto non consuma una revisione.
    $this->post($store, ['rating' => 4, 'body' => 'Serata piacevole, ora hanno insonorizzato.', 'revision' => 3])->assertRedirect();
    expect($review->fresh()->revision)->toBe(3);

    // La ritira: spariscono recensione e voto.
    $this->delete($destroy)->assertRedirect($this->venuePage.'#recensioni')->assertSessionHas('status', __('reviews.deleted'));
    expect(CatalogReview::query()->count())->toBe(0)->and(CatalogRating::query()->count())->toBe(0);
    $this->delete($destroy)->assertNotFound();
});

it('recensisce un organizzatore dal sito, segue lo stato dall\'app e la cancella dall\'app; chi lo gestisce non può', function (): void {
    $mario = intFlowReviewer();
    $owner = intFlowReviewer('Paola', 'Gialli');
    $this->organizer->update(['owner_id' => $owner->id]);
    $store = route('organizers.review.store', ['slug' => $this->organizer->slug]);
    $api = '/api/v1/organizers/'.$this->organizer->slug.'/reviews';

    $this->actingAs($mario)->get($this->organizerPage)->assertOk()->assertSee($store, false);
    $this->post($store, ['body' => 'Rassegna curata, acustica eccellente.'])
        ->assertRedirect($this->organizerPage.'#recensioni')->assertSessionHas('status', __('reviews.submitted'));
    $review = CatalogReview::query()->sole();
    expect($review->reviewable_type)->toBe('organizer')->and($review->rating)->toBeNull();

    // Dall'app la vede come propria, in attesa.
    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($mario);
    $this->getJson($api)->assertOk()
        ->assertJsonPath('data.count', 0)
        ->assertJsonPath('data.my_review.status', 'pending')
        ->assertJsonPath('data.my_review.rating', 0)
        ->assertJsonPath('data.my_review.body', 'Rassegna curata, acustica eccellente.');

    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $this->getJson($api)->assertJsonPath('data.count', 1)->assertJsonPath('data.my_review.status', 'approved')
        ->assertJsonPath('data.reviews.0.body', 'Rassegna curata, acustica eccellente.')
        ->assertJsonPath('data.reviews.0.author', 'Mario');

    // Chi gestisce l'organizzatore non recensisce se stesso, né dal sito né dall'app.
    $this->app['auth']->forgetGuards();
    $this->actingAs($owner, 'web')->post($store, ['rating' => 5])->assertForbidden();
    Sanctum::actingAs($owner);
    $this->postJson($api, ['rating' => 5])->assertForbidden();
    $this->getJson($api)->assertJsonPath('data.can_review', false);

    // L'autore cancella dall'app.
    Sanctum::actingAs($mario);
    $this->deleteJson($api)->assertOk()->assertJsonPath('data.message', __('reviews.deleted'));
    $this->getJson($api)->assertJsonPath('data.count', 0)->assertJsonPath('data.my_review', null);

    // Un organizzatore spento non accetta più recensioni.
    $this->organizer->update(['is_active' => false]);
    $this->postJson($api, ['rating' => 4])->assertNotFound();
    expect(CatalogReview::query()->count())->toBe(0);
});

it('l\'app scrive, legge e cancella la recensione di un locale e respinge chi non può scriverla', function (): void {
    $api = '/api/v1/venues/'.$this->venue->slug.'/reviews';
    $mario = intFlowReviewer();
    $nonVerificato = User::factory()->create();
    $staffDelLocale = intFlowReviewer('Elena', 'Blu');
    $this->venue->members()->attach($staffDelLocale, ['role' => 'editor']);

    $this->postJson($api, ['rating' => 5])->assertUnauthorized();

    // Senza WhatsApp verificato non si partecipa alla community.
    Sanctum::actingAs($nonVerificato);
    $this->getJson($api)->assertOk()->assertJsonPath('data.can_review', false)->assertJsonPath('data.verified', false);
    $this->postJson($api, ['rating' => 5])->assertForbidden();

    // Chi lavora nel locale non recensisce il proprio locale.
    Sanctum::actingAs($staffDelLocale);
    $this->postJson($api, ['rating' => 5, 'body' => 'Il nostro locale è il migliore.'])->assertForbidden();

    Sanctum::actingAs($mario);
    $this->getJson($api)->assertJsonPath('data.can_review', true)->assertJsonPath('data.my_review', null);
    $this->postJson($api, ['body' => 'troppo'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);
    $this->postJson($api, ['rating' => 6])->assertUnprocessable();
    $this->postJson($api, ['rating' => 2, 'revision' => 0])->assertOk()->assertJsonPath('data.message', __('reviews.submitted'));
    $this->getJson($api)->assertJsonPath('data.my_review.rating', 2)->assertJsonPath('data.my_review.body', '')
        ->assertJsonPath('data.my_review.revision', 1);

    // Campi del moderatore mandati dal client non contano.
    $this->postJson($api, ['rating' => 3, 'approved' => true, 'status' => 'approved', 'user_id' => $staffDelLocale->id])->assertOk();
    $review = CatalogReview::query()->sole();
    expect($review->status)->toBe(CatalogReviewStatus::Pending)->and($review->user_id)->toBe($mario->id)->and($review->rating)->toBe(3);

    $this->deleteJson($api)->assertOk();
    expect(CatalogReview::query()->count())->toBe(0);
    $this->deleteJson($api)->assertNotFound();
});

it('una recensione offensiva si segnala dalla scheda e apre una sola pratica, che segue l\'area assistenza', function (): void {
    config(['carpool.enabled' => true]);
    $autore = intFlowReviewer();
    $review = $this->reviews->submit($this->venue, $autore, 1, 'Il cameriere è un incapace totale.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $report = route('venues.review.report', ['slug' => $this->venue->slug, 'review' => $review->id]);
    $lettrice = intFlowReviewer('Chiara', 'Viola');

    // Il modulo di segnalazione compare a chi è verificato.
    $this->actingAs($lettrice)->get($this->venuePage)->assertOk()->assertSee($report, false);

    $this->post($report, ['body' => 'Breve'])->assertSessionHasErrors('body');
    $risposta = $this->post($report, ['body' => 'Contiene un insulto verso il personale.']);
    $case = CarpoolCase::query()->sole();
    $risposta->assertRedirect(route('carpool.case', $case->id));
    expect($case->reporter_id)->toBe($lettrice->id)
        ->and($case->social_type)->toBe('catalog_review')
        ->and($case->social_id)->toBe($review->id)
        ->and($case->status)->toBe(CarpoolCaseStatus::Open)
        ->and($case->review_snapshot['body'])->toBe('Il cameriere è un incapace totale.')
        ->and($case->review_snapshot['subject_name'])->toBe('Osteria dei Fiori');

    // Un secondo invio della stessa persona non apre un'altra pratica.
    $this->post($report, ['body' => 'La segnalo di nuovo, per sicurezza.'])->assertRedirect(route('carpool.case', $case->id));
    expect(CarpoolCase::query()->count())->toBe(1);

    // A car pooling spento si torna alla scheda con la conferma.
    config(['carpool.enabled' => false]);
    $altro = intFlowReviewer('Luca', 'Rosa');
    $this->actingAs($altro)->post($report, ['body' => 'Anche io lo trovo offensivo.'])
        ->assertRedirect($this->venuePage.'#recensioni')->assertSessionHas('status', __('reviews.reported'));
    expect(CarpoolCase::query()->count())->toBe(2);

    // Chi non è verificato non segnala.
    $this->actingAs(User::factory()->create())->post($report, ['body' => 'Segnalazione senza verifica.'])->assertForbidden();
    expect(CarpoolCase::query()->count())->toBe(2);
});

it('dall\'app si segnala la recensione di un organizzatore, una volta sola, e la prova resta anche se l\'autore la cancella', function (): void {
    $autore = intFlowReviewer();
    $review = $this->reviews->submit($this->organizer, $autore, 1, 'Organizzazione pessima, mai più.');
    $this->reviews->moderate($review, cpStaff(), 'approved', 1, null);
    $url = '/api/v1/organizers/'.$this->organizer->slug.'/reviews/'.$review->id.'/report';
    $lettore = intFlowReviewer('Sara', 'Neri');

    Sanctum::actingAs(User::factory()->create());
    $this->postJson($url, ['body' => 'Vorrei segnalare questo testo.'])->assertForbidden();

    Sanctum::actingAs($lettore);
    $this->postJson($url, ['body' => 'corto'])->assertUnprocessable();
    // La stessa recensione cercata sotto un locale non esiste.
    $this->postJson('/api/v1/venues/'.$this->venue->slug.'/reviews/'.$review->id.'/report', ['body' => 'Vorrei segnalare questo testo.'])->assertNotFound();

    $primo = $this->postJson($url, ['body' => 'Vorrei segnalare questo testo.'])->assertOk()->json('data.case_id');
    $secondo = $this->postJson($url, ['body' => 'Vorrei segnalare questo testo.'])->assertOk()->json('data.case_id');
    expect($secondo)->toBe($primo)->and(CarpoolCase::query()->count())->toBe(1);

    // L'autore la cancella: la pratica conserva la copia del testo.
    Sanctum::actingAs($autore);
    $this->deleteJson('/api/v1/organizers/'.$this->organizer->slug.'/reviews')->assertOk();
    expect(CatalogReview::query()->count())->toBe(0)
        ->and(CarpoolCase::query()->findOrFail($primo)->review_snapshot['body'])->toBe('Organizzazione pessima, mai più.');

    // Una recensione non ancora pubblicata non si può segnalare.
    $inAttesa = $this->reviews->submit($this->organizer, intFlowReviewer('Ugo', 'Grigi'), 2, 'Ancora in moderazione questa.');
    Sanctum::actingAs($lettore);
    $this->postJson('/api/v1/organizers/'.$this->organizer->slug.'/reviews/'.$inAttesa->id.'/report', ['body' => 'Vorrei segnalare questo testo.'])->assertNotFound();
});
