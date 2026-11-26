<?php

declare(strict_types=1);

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\PostComment;
use App\Enums\CatalogReviewStatus;
use App\Enums\EventCommentStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\CarpoolAudit;
use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R6 — la moderazione dei contenuti nell'app della redazione (R-MD-01…12):
 * segnalazioni, recensioni, commenti agli eventi, con gli stessi permessi,
 * le stesse regole e gli stessi limiti delle tre risorse del gruppo
 * «Moderazione» del pannello. La griglia: moderatore, admin, super admin,
 * referente di un locale, utente comune, token non sbloccato (423), password
 * non recente sulle azioni pericolose (423).
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->staff = [$this->s->moderator, $this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
});

function r6Comment(Event $event, string $body = 'Ci sarà posto anche per chi arriva tardi?', ?User $author = null): EventComment
{
    return app(PostComment::class)->handle($event, $author ?? User::factory()->create(['name' => 'Giulia Ferrari']), $body);
}

function r6Review(Venue $venue, string $body = 'Cena ottima, servizio un po’ lento.', ?User $author = null): CatalogReview
{
    return app(CatalogReviews::class)->submit($venue, $author ?? carpoolPerson(), 4, $body)->fresh();
}

/** Lo stesso controllo per ogni indirizzo: fuori la gente comune (404), chiuso senza sblocco (423). */
function r6Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->moderator, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

// ------------------------------------------------------------ segnalazioni

it('elenca le segnalazioni a tutta la redazione, con le colonne del pannello e le azioni permesse', function (): void {
    $report = Report::factory()->about($this->s->publishedEventA)->ofReason(ReportReason::WrongInfo)->create([
        'note' => 'L’orario è sbagliato.', 'reporter_email' => 'lettore@example.test', 'ip_address' => '203.0.113.7',
    ]);

    r6Gate($this, 'GET', '/api/v1/editorial/reports');

    foreach ($this->staff as $person) {
        $row = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/reports')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 24)
            ->json('data.0');

        expect($row['id'])->toBe($report->id)
            ->and($row['reason'])->toBe('wrong_info')
            ->and($row['reason_label'])->toBe(ReportReason::WrongInfo->label())
            ->and($row['status'])->toBe('pending')
            ->and($row['subject'])->toBe(['type' => 'event', 'id' => $this->s->publishedEventA->id, 'label' => 'evento · Concerto di musica popolare', 'community' => false])
            ->and($row['note'])->toBe('L’orario è sbagliato.')
            ->and($row['abilities'])->toBe(['resolve' => true, 'destroy' => true])
            // Il pannello non mostra contatto e indirizzo di chi segnala: nemmeno l'app.
            ->and($row)->not->toHaveKey('reporter_email')
            ->and($row)->not->toHaveKey('ip_address');
    }
});

it('filtra le segnalazioni per stato, motivo e «aperte», ordina per arrivo e tronca le righe per pagina', function (): void {
    $pending = Report::factory()->about($this->s->publishedEventA)->ofReason(ReportReason::Spam)->create(['created_at' => now()->subDays(3)]);
    $reviewing = Report::factory()->about($this->s->publishedEventA)->ofReason(ReportReason::Duplicate)->reviewing()->create(['created_at' => now()->subDays(2)]);
    $resolved = Report::factory()->about($this->s->venueA)->ofReason(ReportReason::Spam)->resolved()->create(['created_at' => now()->subDay()]);
    $client = EditorialSession::as($this, EditorialSession::token($this->s->moderator));

    expect($client->getJson('/api/v1/editorial/reports')->json('data.*.id'))->toBe([$resolved->id, $reviewing->id, $pending->id])
        ->and($client->getJson('/api/v1/editorial/reports?sort=created_at')->json('data.*.id'))->toBe([$pending->id, $reviewing->id, $resolved->id])
        ->and($client->getJson('/api/v1/editorial/reports?filter[status]=resolved')->json('data.*.id'))->toBe([$resolved->id])
        ->and($client->getJson('/api/v1/editorial/reports?filter[reason]=spam')->json('data.*.id'))->toBe([$resolved->id, $pending->id])
        ->and($client->getJson('/api/v1/editorial/reports?filter[open]=1')->json('data.*.id'))->toBe([$reviewing->id, $pending->id])
        ->and($client->getJson('/api/v1/editorial/reports?per_page=2&page=2')->json('data.*.id'))->toBe([$pending->id]);

    $client->getJson('/api/v1/editorial/reports?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    $client->getJson('/api/v1/editorial/reports?filter[status]=chiusa')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $client->getJson('/api/v1/editorial/reports?sort=note')->assertUnprocessable();
});

it('dice quando il soggetto è della community, per «Modera il soggetto»', function (): void {
    $report = Report::factory()->create(['reportable_type' => 'community_profile', 'reportable_id' => 987654]);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/reports/'.$report->id)->assertOk()
        ->assertJsonPath('data.subject.community', true)
        ->assertJsonPath('data.subject.label', __('community.subject_profile').' · 987654');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/reports/99999999')->assertNotFound();
});

it('chiude una segnalazione firmandola con chi decide, senza toccare quello che è stato segnalato', function (): void {
    $report = Report::factory()->about($this->s->publishedEventA)->ofReason(ReportReason::WrongInfo)->create([
        'note' => 'L’orario è sbagliato.', 'reporter_email' => 'lettore@example.test',
    ]);
    $url = '/api/v1/editorial/reports/'.$report->id;
    r6Gate($this, 'PATCH', $url, ['status' => 'resolved']);

    // Non è un'azione pericolosa: basta lo sblocco, anche con la password di 20 minuti fa.
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 20))
        ->patchJson($url, ['status' => 'resolved', 'resolution_note' => 'Orario corretto con il locale.', 'reviewed_by' => $this->s->admin->id, 'note' => 'riscritta'])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolution_note', 'Orario corretto con il locale.')
        ->assertJsonPath('data.reviewed_by', $this->s->moderator->name)
        ->assertJsonPath('meta.message', __('pannelli-redazione.segnalazioni.saved'));

    $fresh = $report->fresh();
    expect($fresh->status)->toBe(ReportStatus::Resolved)
        ->and($fresh->reviewed_by)->toBe($this->s->moderator->id)
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->note)->toBe('L’orario è sbagliato.')
        ->and($fresh->reporter_email)->toBe('lettore@example.test');

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, ['status' => 'chiusa'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status'], 'error.fields');
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, [])
        ->assertUnprocessable()->assertJsonValidationErrors(['status'], 'error.fields');
});

it('elimina una segnalazione solo con la password recente, per tutta la redazione', function (): void {
    $reports = Report::factory()->about($this->s->publishedEventA)->count(3)->create();
    $url = fn (int $i): string => '/api/v1/editorial/reports/'.$reports[$i]->id;
    r6Gate($this, 'DELETE', $url(0));

    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->deleteJson($url(0))
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect(Report::query()->whereKey($reports[0]->id)->exists())->toBeTrue();

    foreach ($this->staff as $i => $person) {
        EditorialSession::as($this, EditorialSession::token($person))->deleteJson($url($i))->assertOk()->assertJsonPath('data.deleted', true);
    }
    expect(Report::query()->count())->toBe(0);
});

it('non fa una query per riga nell’elenco delle segnalazioni', function (): void {
    $client = EditorialSession::as($this, EditorialSession::token($this->s->moderator));
    $count = function () use ($client): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $client->getJson('/api/v1/editorial/reports')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Report::factory()->about($this->s->publishedEventA)->reviewing()->create();
    Report::factory()->about($this->s->venueA)->create();
    // La prima richiesta riempie le cache (città, permessi): si conta dalla seconda.
    $count();
    $few = $count();
    Report::factory()->about($this->s->publishedEventB)->reviewing()->count(3)->create();
    Report::factory()->about($this->s->venueB)->count(3)->create();

    expect($count())->toBe($few);
});

// -------------------------------------------------------------- recensioni

it('apre le recensioni ad admin e super admin, non al moderatore, come il pannello', function (): void {
    $review = r6Review($this->s->venueA);
    r6Gate($this, 'GET', '/api/v1/editorial/reviews');

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/reviews')
        ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->postJson('/api/v1/editorial/reviews/'.$review->id.'/moderate', ['status' => 'approved', 'revision' => $review->revision])->assertForbidden();

    foreach ([$this->s->admin, $this->superAdmin] as $person) {
        $row = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/reviews')->assertOk()->json('data.0');
        expect($row['id'])->toBe($review->id)
            ->and($row['subject'])->toBe(['type' => 'venue', 'type_label' => __('reviews.types.venue'), 'id' => $this->s->venueA->id, 'name' => $this->s->venueA->name])
            ->and($row['rating'])->toBe(4)
            ->and($row['body'])->toBe('Cena ottima, servizio un po’ lento.')
            ->and($row['status'])->toBe('pending')
            ->and($row['revision'])->toBe($review->revision)
            ->and($row['reply'])->toBeNull()
            ->and($row['abilities'])->toBe(['approve' => true, 'reject' => true, 'remove_reply' => false]);
    }
});

it('filtra le recensioni per tipo e stato e cerca sul nome dell’autore', function (): void {
    $mario = r6Review($this->s->venueA, 'Ottimo locale, torneremo.', tap(carpoolPerson(), fn (User $u) => $u->forceFill(['name' => 'Mario Verdi'])->save()));
    $other = r6Review($this->s->venueB);
    app(CatalogReviews::class)->moderate($other, $this->s->admin, 'rejected', $other->revision, 'Contiene dati personali.');
    $client = EditorialSession::as($this, EditorialSession::token($this->s->admin));

    expect($client->getJson('/api/v1/editorial/reviews?filter[status]=rejected')->json('data.*.id'))->toBe([$other->id])
        ->and($client->getJson('/api/v1/editorial/reviews?filter[status]=pending')->json('data.*.id'))->toBe([$mario->id])
        ->and($client->getJson('/api/v1/editorial/reviews?filter[type]=organizer')->json('data'))->toBe([])
        ->and($client->getJson('/api/v1/editorial/reviews?q=verdi')->json('data.*.id'))->toBe([$mario->id]);
    $client->getJson('/api/v1/editorial/reviews?filter[type]=event')->assertUnprocessable();
});

it('approva e rifiuta con nota e revisione, e rifiuta di sovrascrivere una decisione presa nel frattempo', function (): void {
    $review = r6Review($this->s->venueA);
    $url = '/api/v1/editorial/reviews/'.$review->id.'/moderate';
    r6Gate($this, 'POST', $url, ['status' => 'approved', 'revision' => $review->revision]);
    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));

    // Rifiutare chiede il motivo; una nota, se c'è, va da 5 a 1000 caratteri.
    $admin->postJson($url, ['status' => 'rejected', 'revision' => $review->revision])->assertUnprocessable()->assertJsonValidationErrors(['note'], 'error.fields');
    $admin->postJson($url, ['status' => 'approved', 'revision' => $review->revision, 'note' => 'ok'])->assertUnprocessable()->assertJsonValidationErrors(['note'], 'error.fields');
    $admin->postJson($url, ['status' => 'pending', 'revision' => $review->revision])->assertUnprocessable()->assertJsonValidationErrors(['status'], 'error.fields');

    $admin->postJson($url, ['status' => 'approved', 'revision' => $review->revision])->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.moderated_by', $this->s->admin->name)
        ->assertJsonPath('data.revision', $review->revision + 1);
    expect($review->fresh()->approved)->toBeTrue()
        ->and(CarpoolAudit::query()->where('action', 'catalog_review_moderated')->where('actor_id', $this->s->admin->id)->count())->toBe(1);

    // Il super admin ha ancora la revisione vecchia: il server non sovrascrive.
    EditorialSession::as($this, EditorialSession::token($this->superAdmin))
        ->postJson($url, ['status' => 'rejected', 'revision' => $review->revision, 'note' => 'Contiene un insulto.'])
        ->assertUnprocessable()->assertJsonValidationErrors(['revision'], 'error.fields');
    expect($review->fresh()->status)->toBe(CatalogReviewStatus::Approved);

    EditorialSession::as($this, EditorialSession::token($this->superAdmin))
        ->postJson($url, ['status' => 'rejected', 'revision' => $review->revision + 1, 'note' => 'Contiene un insulto.'])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.moderation_note', 'Contiene un insulto.');
});

it('toglie la risposta del gestore, e la toglie solo dove c’è', function (): void {
    $review = r6Review($this->s->venueA);
    $reviews = app(CatalogReviews::class);
    $reviews->moderate($review, $this->s->admin, 'approved', $review->revision, null);
    $reviews->reply($review->fresh(), $this->s->ownerA, 'Grazie, ci vediamo presto!');
    $url = '/api/v1/editorial/reviews/'.$review->id.'/reply';
    r6Gate($this, 'DELETE', $url);
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->deleteJson($url)->assertForbidden();

    $client = EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 30));
    $client->getJson('/api/v1/editorial/reviews')->assertJsonPath('data.0.abilities.remove_reply', true)
        ->assertJsonPath('data.0.reply.body', 'Grazie, ci vediamo presto!');
    // Non è pericolosa: niente password recente, come la sola conferma del pannello.
    $client->deleteJson($url)->assertOk()->assertJsonPath('data.reply', null)->assertJsonPath('data.abilities.remove_reply', false);

    expect($review->fresh()->owner_reply)->toBeNull()
        ->and(CarpoolAudit::query()->where('action', 'catalog_review_reply_removed')->count())->toBe(1);
    $client->deleteJson($url)->assertOk();
    expect(CarpoolAudit::query()->where('action', 'catalog_review_reply_removed')->count())->toBe(1);
});

// ---------------------------------------------------------------- commenti

it('elenca i commenti a tutta la redazione; eliminare è di admin e super admin', function (): void {
    $comment = r6Comment($this->s->publishedEventA, 'Domanda sul parcheggio del circolo');
    r6Gate($this, 'GET', '/api/v1/editorial/comments');

    $expected = [
        $this->s->moderator->id => ['show' => true, 'hide' => true, 'restore' => false, 'destroy' => false],
        $this->s->admin->id => ['show' => true, 'hide' => true, 'restore' => false, 'destroy' => true],
        $this->superAdmin->id => ['show' => true, 'hide' => true, 'restore' => false, 'destroy' => true],
    ];
    foreach ($this->staff as $person) {
        $row = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/comments')->assertOk()->json('data.0');
        expect($row['id'])->toBe($comment->id)
            ->and($row['event']['title'])->toBe('Concerto di musica popolare')
            ->and($row['event'])->toHaveKey('poster')
            ->and($row['author'])->toBe('Giulia Ferrari')
            ->and($row['body'])->toBe('Domanda sul parcheggio del circolo')
            ->and($row['status'])->toBe('published')
            ->and($row['reactions_count'])->toBe(0)
            ->and($row['revision'])->toBe(1)
            ->and($row['abilities'])->toBe($expected[$person->id]);
    }
});

it('filtra i commenti per stato, cerca su evento, autore e testo, e mostra i dettagli', function (): void {
    $parking = r6Comment($this->s->publishedEventA, 'Domanda sul parcheggio');
    $hidden = r6Comment($this->s->publishedEventB, 'Messaggio fuori tema', User::factory()->create(['name' => 'Luca Bianchi']));
    app(ModerateComment::class)->nascondi($hidden, $this->s->admin, 1, 'Fuori tema');
    $client = EditorialSession::as($this, EditorialSession::token($this->s->moderator));

    expect($client->getJson('/api/v1/editorial/comments?filter[status]=hidden')->json('data.*.id'))->toBe([$hidden->id])
        ->and($client->getJson('/api/v1/editorial/comments?filter[status]=published')->json('data.*.id'))->toBe([$parking->id])
        ->and($client->getJson('/api/v1/editorial/comments?q=parcheggio')->json('data.*.id'))->toBe([$parking->id])
        ->and($client->getJson('/api/v1/editorial/comments?q=bianchi')->json('data.*.id'))->toBe([$hidden->id])
        ->and($client->getJson('/api/v1/editorial/comments?q=popolare')->json('data.*.id'))->toBe([$parking->id]);

    $client->getJson('/api/v1/editorial/comments/'.$hidden->id)->assertOk()
        ->assertJsonPath('data.moderation_note', 'Fuori tema')
        ->assertJsonPath('data.moderated_by', $this->s->admin->name)
        ->assertJsonPath('data.abilities.restore', true)
        ->assertJsonPath('data.abilities.hide', false);
    r6Gate($this, 'GET', '/api/v1/editorial/comments/'.$hidden->id);
});

it('nasconde e ripristina con la revisione letta, e non sovrascrive la decisione di un altro', function (): void {
    $comment = r6Comment($this->s->publishedEventA);
    $hide = '/api/v1/editorial/comments/'.$comment->id.'/hide';
    $restore = '/api/v1/editorial/comments/'.$comment->id.'/restore';
    r6Gate($this, 'POST', $hide, ['revision' => 1]);

    $moderator = EditorialSession::as($this, EditorialSession::token($this->s->moderator, passwordMinutesAgo: 30));
    $moderator->postJson($hide, ['revision' => 1, 'note' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['note'], 'error.fields');
    $moderator->postJson($hide, ['revision' => 1, 'note' => ' Insulti a chi suona '])->assertOk()
        ->assertJsonPath('data.status', 'hidden')
        ->assertJsonPath('data.moderation_note', 'Insulti a chi suona')
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('meta.message', __('comments.moderation.hidden'));

    // Un secondo moderatore con l'elenco vecchio: già nascosto, va riletto.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($hide, ['revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['revision'], 'error.fields');
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($restore, ['revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['revision'], 'error.fields');

    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->postJson($restore, ['revision' => 2])->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.moderated_by', $this->superAdmin->name);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);
});

it('elimina un commento e le sue risposte solo per admin e super admin, con la password recente', function (): void {
    $comment = r6Comment($this->s->publishedEventA);
    $url = '/api/v1/editorial/comments/'.$comment->id;
    r6Gate($this, 'DELETE', $url);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->deleteJson($url)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->deleteJson($url)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect(EventComment::query()->whereKey($comment->id)->exists())->toBeTrue();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->deleteJson($url)->assertOk()->assertJsonPath('meta.message', __('comments.deleted'));
    expect(EventComment::query()->whereKey($comment->id)->exists())->toBeFalse();

    $another = r6Comment($this->s->publishedEventB);
    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->deleteJson('/api/v1/editorial/comments/'.$another->id)->assertOk();
});

it('non fa una query per riga nell’elenco dei commenti e delle recensioni', function (): void {
    $count = function (string $url, User $user): int {
        $client = EditorialSession::as($this, EditorialSession::token($user));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $client->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Una riga di ogni forma (pubblicato, nascosto da qualcuno, recensione decisa) prima di contare.
    r6Comment($this->s->publishedEventA);
    app(ModerateComment::class)->nascondi(r6Comment($this->s->publishedEventA, 'Primo nascosto'), $this->s->admin, 1, 'Fuori tema');
    $first = r6Review($this->s->venueA);
    app(CatalogReviews::class)->moderate($first, $this->s->admin, 'approved', $first->revision, null);
    // La prima richiesta riempie le cache (città, permessi): si conta dalla seconda.
    $count('/api/v1/editorial/comments', $this->s->moderator);
    $count('/api/v1/editorial/reviews', $this->s->admin);
    $comments = $count('/api/v1/editorial/comments', $this->s->moderator);
    $reviews = $count('/api/v1/editorial/reviews', $this->s->admin);

    foreach (range(1, 3) as $i) {
        $hidden = r6Comment($this->s->publishedEventB, 'Commento numero '.$i);
        app(ModerateComment::class)->nascondi($hidden, $this->s->moderator, 1, 'Fuori tema');
        $review = r6Review($this->s->venueB, 'Recensione numero '.$i.' con testo abbastanza lungo.');
        app(CatalogReviews::class)->moderate($review, $this->superAdmin, 'rejected', $review->revision, 'Contiene dati personali.');
    }

    expect($count('/api/v1/editorial/comments', $this->s->moderator))->toBe($comments)
        ->and($count('/api/v1/editorial/reviews', $this->s->admin))->toBe($reviews);
});
