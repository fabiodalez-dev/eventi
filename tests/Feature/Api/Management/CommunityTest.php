<?php

declare(strict_types=1);

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\PostComment;
use App\Enums\EventCommentStatus;
use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\CarpoolCase;
use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * Commenti e recensioni dall'app di gestione (P7): gli stessi elenchi e le
 * stesse azioni delle pagine «Commenti agli eventi» e «Recensioni ricevute»
 * dei pannelli, con le stesse regole. Qui il collaboratore può tutto quello
 * che può il referente, come nel pannello.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
    $this->reviews = app(CatalogReviews::class);
});

function p7Comment(Event $event, string $body = 'Ci sarà posto anche per chi arriva tardi?', ?User $author = null): EventComment
{
    return app(PostComment::class)->handle($event, $author ?? User::factory()->create(['name' => 'Giulia Ferrari']), $body);
}

function p7Review(Venue|Organizer $subject, string $body = 'Cena ottima, servizio un po’ lento.', bool $approve = true): CatalogReview
{
    $reviews = app(CatalogReviews::class);
    $review = $reviews->submit($subject, carpoolPerson(), 4, $body);
    if ($approve) {
        $reviews->moderate($review, cpStaff(), 'approved', $review->revision, null);
    }

    return $review->fresh();
}

function p7Organizer(VenueIsolationScenario $s, ?User $owner = null): Organizer
{
    return Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => ($owner ?? User::factory()->create())->id, 'is_active' => true]);
}

// ------------------------------------------------------------------ commenti

it('elenca i soli commenti agli eventi del locale, con i campi della tabella del pannello', function (): void {
    $own = p7Comment($this->s->publishedEventA, 'Domanda sul parcheggio del circolo');
    p7Comment($this->s->publishedEventB, 'Commento del teatro accanto');
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->venueUrl.'/comments')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.event.id', $this->s->publishedEventA->id)
        ->assertJsonPath('data.0.event.title', 'Concerto di musica popolare')
        ->assertJsonPath('data.0.author', 'Giulia Ferrari')
        ->assertJsonPath('data.0.body', 'Domanda sul parcheggio del circolo')
        ->assertJsonPath('data.0.reactions_count', 0)
        ->assertJsonPath('data.0.status', 'published')
        ->assertJsonPath('data.0.status_label', 'Pubblicato')
        ->assertJsonPath('data.0.moderated_by', null)
        ->assertJsonPath('data.0.revision', 1)
        ->assertJsonPath('data.0.can_hide', true)
        ->assertJsonPath('data.0.can_restore', false)
        ->assertJsonPath('meta.next_page', null)
        ->assertJsonPath('meta.total', 1);
});

it('filtra per stato, cerca su evento, autore e testo, e restringe a un evento', function (): void {
    $parking = p7Comment($this->s->publishedEventA, 'Domanda sul parcheggio');
    $hidden = p7Comment($this->s->publishedEventA, 'Messaggio fuori tema', User::factory()->create(['name' => 'Luca Bianchi']));
    app(ModerateComment::class)->nascondi($hidden, $this->s->ownerA, 1, 'Fuori tema');
    $draft = p7Comment($this->s->draftEventA, 'Commento sulla bozza');
    Sanctum::actingAs($this->s->ownerA);

    expect($this->getJson($this->venueUrl.'/comments?status=hidden')->assertOk()->json('data.*.id'))->toBe([$hidden->id])
        ->and($this->getJson($this->venueUrl.'/comments?status=published')->json('data.*.id'))->toBe([$draft->id, $parking->id])
        ->and($this->getJson($this->venueUrl.'/comments?q=parcheggio')->json('data.*.id'))->toBe([$parking->id])
        ->and($this->getJson($this->venueUrl.'/comments?q=Bianchi')->json('data.*.id'))->toBe([$hidden->id])
        ->and($this->getJson($this->venueUrl.'/comments?q=Bozza del circolo')->json('data.*.id'))->toBe([$draft->id])
        ->and($this->getJson($this->venueUrl.'/comments?event='.$this->s->draftEventA->id)->json('data.*.id'))->toBe([$draft->id])
        // Un evento di un altro locale non apre niente.
        ->and($this->getJson($this->venueUrl.'/comments?event='.$this->s->publishedEventB->id)->json('data'))->toBe([]);

    $this->getJson($this->venueUrl.'/comments?status=deleted')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['status']]]);
    // Il nascosto porta chi l'ha nascosto e offre «Rimetti in vista».
    $this->getJson($this->venueUrl.'/comments?status=hidden')
        ->assertJsonPath('data.0.moderated_by', $this->s->ownerA->name)
        ->assertJsonPath('data.0.can_hide', false)
        ->assertJsonPath('data.0.can_restore', true);
});

it('lascia al collaboratore nascondere con una nota e rimettere in vista, con il controllo di revisione', function (): void {
    $comment = p7Comment($this->s->publishedEventA);
    Sanctum::actingAs($this->s->editorA);

    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/hide', ['revision' => 1, 'note' => 'Fuori tema'])->assertOk()
        ->assertJsonPath('data.status', 'hidden')
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.moderated_by', $this->s->editorA->name)
        ->assertJsonPath('data.can_restore', true)
        ->assertJsonPath('meta.message', __('comments.moderation.hidden'));
    $row = $comment->fresh();
    expect($row->status)->toBe(EventCommentStatus::Hidden)
        ->and($row->moderation_note)->toBe('Fuori tema')
        ->and($row->moderated_by)->toBe($this->s->editorA->id);

    // Con la revisione vecchia la seconda decisione non sovrascrive la prima.
    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/restore', ['revision' => 1])->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.fields.revision.0', __('comments.moderation.changed'));
    // Nascondere di nuovo un nascosto è un conflitto, non una seconda scrittura.
    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/hide', ['revision' => 2])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['revision']]]);

    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/restore', ['revision' => 2])->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.can_hide', true);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);
});

it('rifiuta una nota oltre i mille caratteri e una richiesta senza revisione', function (): void {
    $comment = p7Comment($this->s->publishedEventA);
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/hide', ['revision' => 1, 'note' => str_repeat('a', 1001)])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['note']]]);
    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/hide', [])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['revision']]]);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);
});

it('non lascia rimettere in vista ciò che ha nascosto la redazione', function (): void {
    $comment = p7Comment($this->s->publishedEventA);
    app(ModerateComment::class)->nascondi($comment, $this->s->moderator, 1, 'Dati personali');
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->venueUrl.'/comments')->assertJsonPath('data.0.can_restore', false);
    $this->postJson($this->venueUrl.'/comments/'.$comment->id.'/restore', ['revision' => 2])->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Hidden);
});

it('tiene fuori i commenti degli altri e chi non gestisce il locale', function (): void {
    $foreign = p7Comment($this->s->publishedEventB);
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->venueUrl.'/comments/'.$foreign->id.'/hide', ['revision' => 1])->assertNotFound();
    expect($foreign->fresh()->status)->toBe(EventCommentStatus::Published);

    Sanctum::actingAs($this->s->plainUser);
    $this->getJson($this->venueUrl.'/comments')->assertNotFound();

    // Un locale sospeso esce dalla gestione anche nell'app.
    $this->s->venueA->forceFill(['status' => VenueStatus::Suspended])->save();
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->venueUrl.'/comments')->assertNotFound();
});

it('dà all’organizzazione i commenti degli eventi che firma, non di quelli che co-organizza', function (): void {
    $member = User::factory()->create();
    $organizer = p7Organizer($this->s);
    $organizer->users()->attach($member);
    $signed = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'organizer_id' => $organizer->id,
        'venue_id' => $this->s->venueB->id, 'status' => EventStatus::Published, 'published_at' => now()]);
    $this->s->publishedEventA->organizers()->attach($organizer);
    $mine = p7Comment($signed);
    $coOrganized = p7Comment($this->s->publishedEventA);
    $url = '/api/v1/management/organizers/'.$organizer->id;

    Sanctum::actingAs($member);
    expect($this->getJson($url.'/comments')->assertOk()->json('data.*.id'))->toBe([$mine->id]);
    $this->postJson($url.'/comments/'.$mine->id.'/hide', ['revision' => 1])->assertOk()->assertJsonPath('data.status', 'hidden');
    $this->postJson($url.'/comments/'.$coOrganized->id.'/hide', ['revision' => 1])->assertNotFound();
});

// --------------------------------------------------------------- recensioni

it('elenca le sole recensioni pubblicate del locale, con la risposta', function (): void {
    $published = p7Review($this->s->venueA);
    p7Review($this->s->venueA, 'Ancora da approvare, questa.', approve: false);
    p7Review($this->s->venueB, 'Recensione del teatro accanto.');
    $this->reviews->reply($published, $this->s->ownerA, 'Grazie, ci vediamo presto.');
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->venueUrl.'/reviews')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $published->id)
        ->assertJsonPath('data.0.rating', 4)
        ->assertJsonPath('data.0.body', 'Cena ottima, servizio un po’ lento.')
        ->assertJsonPath('data.0.reply.body', 'Grazie, ci vediamo presto.')
        ->assertJsonPath('data.0.reply.author', 'Circolo Aurora')
        ->assertJsonPath('data.0.can_reply', true)
        ->assertJsonPath('data.0.can_remove_reply', true)
        ->assertJsonPath('data.0.can_report', false)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.next_page', null);
    expect($this->getJson($this->venueUrl.'/reviews')->json('data.0.published_at'))->not->toBeNull();
});

it('lascia al collaboratore scrivere, cambiare e togliere la risposta', function (): void {
    $review = p7Review($this->s->venueA);
    Sanctum::actingAs($this->s->editorA);
    $url = $this->venueUrl.'/reviews/'.$review->id.'/reply';

    $this->putJson($url, ['reply' => '  Grazie della visita!  '])->assertOk()
        ->assertJsonPath('data.reply.body', 'Grazie della visita!')
        ->assertJsonPath('data.can_remove_reply', true)
        ->assertJsonPath('meta.message', __('reviews.reply.saved'));
    $this->putJson($url, ['reply' => 'Grazie della visita, a presto.'])->assertOk()->assertJsonPath('data.reply.body', 'Grazie della visita, a presto.');
    expect($review->fresh()->owner_reply_by)->toBe($this->s->editorA->id);

    $this->deleteJson($url)->assertOk()->assertJsonPath('data.reply', null)->assertJsonPath('data.can_remove_reply', false);
    expect($review->fresh()->owner_reply)->toBeNull();
    // Togliere una risposta che non c'è non fa niente.
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.reply', null);
});

it('rifiuta una risposta fuori misura con l’errore sotto il campo', function (): void {
    $review = p7Review($this->s->venueA);
    Sanctum::actingAs($this->s->ownerA);
    $url = $this->venueUrl.'/reviews/'.$review->id.'/reply';

    $this->putJson($url, ['reply' => 'x'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reply']]]);
    $this->putJson($url, ['reply' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reply']]]);
    $this->putJson($url, [])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reply']]]);
    expect($review->fresh()->owner_reply)->toBeNull();
});

it('tiene fuori le recensioni non pubblicate e quelle degli altri', function (): void {
    $pending = p7Review($this->s->venueA, 'Ancora da approvare, questa.', approve: false);
    $foreign = p7Review($this->s->venueB);
    Sanctum::actingAs($this->s->ownerA);

    $this->putJson($this->venueUrl.'/reviews/'.$pending->id.'/reply', ['reply' => 'Grazie!'])->assertNotFound();
    $this->putJson($this->venueUrl.'/reviews/'.$foreign->id.'/reply', ['reply' => 'Grazie!'])->assertNotFound();
    $this->postJson($this->venueUrl.'/reviews/'.$foreign->id.'/report', ['body' => 'Contiene insulti verso il personale.'])->assertNotFound();
    expect($foreign->fresh()->owner_reply)->toBeNull();
});

it('lascia segnalare alla redazione a chi partecipa alla community, come sulla scheda pubblica', function (): void {
    $review = p7Review($this->s->venueA);
    $url = $this->venueUrl.'/reviews/'.$review->id.'/report';

    // Senza verifica della community niente segnalazione, come il pulsante della scheda.
    Sanctum::actingAs($this->s->editorA);
    $this->postJson($url, ['body' => 'Contiene insulti verso il personale.'])->assertForbidden();

    $this->s->editorA->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'p7-editor')])->save();
    Sanctum::actingAs($this->s->editorA->fresh());
    $this->getJson($this->venueUrl.'/reviews')->assertJsonPath('data.0.can_report', true)->assertJsonPath('meta.can_report', true);
    $this->postJson($url, ['body' => 'Corto'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);
    $first = $this->postJson($url, ['body' => 'Contiene insulti verso il personale.'])->assertOk()
        ->assertJsonPath('data.message', __('reviews.reported'))->json('data.case_id');
    // Una seconda segnalazione aperta della stessa persona non apre un'altra pratica.
    $this->postJson($url, ['body' => 'Contiene insulti verso il personale, di nuovo.'])->assertOk()->assertJsonPath('data.case_id', $first);
    expect(CarpoolCase::query()->where('social_type', 'catalog_review')->where('social_id', $review->id)->count())->toBe(1);
});

it('dà all’organizzazione le sue recensioni e la risposta, al proprietario e ai membri', function (): void {
    $member = User::factory()->create();
    $organizer = p7Organizer($this->s);
    $organizer->users()->attach($member);
    $review = p7Review($organizer);
    p7Review($this->s->venueA);
    $url = '/api/v1/management/organizers/'.$organizer->id;

    Sanctum::actingAs($member);
    expect($this->getJson($url.'/reviews')->assertOk()->json('data.*.id'))->toBe([$review->id]);
    $this->putJson($url.'/reviews/'.$review->id.'/reply', ['reply' => 'Grazie a voi!'])->assertOk()
        ->assertJsonPath('data.reply.author', 'Collettivo Portici');
});

// ------------------------------------------------------- dalla scheda pubblica

it('dice alla scheda pubblica dove si moderano commenti e recensioni, solo a chi gestisce', function (): void {
    p7Review($this->s->venueA);
    $reviewsUrl = '/api/v1/venues/'.$this->s->venueA->slug.'/reviews';
    $commentsUrl = '/api/v1/events/'.$this->s->publishedEventA->slug.'/comments';

    $this->getJson($reviewsUrl)->assertOk()->assertJsonPath('data.manage_url', null);
    Sanctum::actingAs($this->s->plainUser);
    $this->getJson($reviewsUrl)->assertJsonPath('data.manage_url', null);
    $this->getJson($commentsUrl)->assertJsonPath('data.manage_url', null);

    Sanctum::actingAs($this->s->editorA);
    expect($this->getJson($reviewsUrl)->json('data.manage_url'))->toEndWith('/gestione/'.$this->s->venueA->slug.'/recensioni')
        ->and($this->getJson($commentsUrl)->json('data.manage_url'))->toEndWith('/gestione/'.$this->s->venueA->slug.'/event-comments?event='.$this->s->publishedEventA->id);

    // Il referente di un altro locale non modera qui.
    Sanctum::actingAs($this->s->ownerB);
    $this->getJson($commentsUrl)->assertJsonPath('data.manage_url', null);
});
