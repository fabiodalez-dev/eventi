<?php

declare(strict_types=1);

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\PostComment;
use App\Actions\Comments\ToggleReaction;
use App\Enums\EventCommentReactionType;
use App\Enums\EventCommentStatus;
use App\Enums\NotificationType;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Notifications\CommentActivity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * I commenti agli eventi.
 *
 * Il modello è `tests/Feature/Web/VenueReviewsTest.php`: stesso ordine —
 * validazione, visibilità, permessi, blocco ottimistico, pannelli.
 */
beforeEach(function (): void {
    /* I ruoli non esistono nel database di test finché il seeder non gira. */
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->event = Event::factory()->for($this->city)->published()->create();
    $this->autore = User::factory()->create();
    $this->altro = User::factory()->create();
});

it('pubblica il commento subito, senza attendere approvazione', function (): void {
    actingAs($this->autore);

    post(route('events.comments.store', ['slug' => $this->event->slug]), [
        'body' => 'Si parcheggia vicino?',
    ])->assertRedirect();

    $commento = EventComment::query()->firstOrFail();

    expect($commento->status)->toBe(EventCommentStatus::Published)
        ->and($commento->user_id)->toBe($this->autore->id);
});

it('mostra il commento nell’HTML servito, non solo dopo il JavaScript', function (): void {
    app(PostComment::class)->handle($this->event, $this->autore, 'Si parcheggia vicino?');

    /*
     * È la differenza che ha deciso di non adottare il pacchetto: i commenti
     * devono stare nel sorgente della pagina, o per i motori di ricerca non
     * esistono.
     */
    $this->get(route('events.show', ['slug' => $this->event->slug]))
        ->assertOk()
        ->assertSee('Si parcheggia vicino?', escape: false);
});

it('rifiuta un commento troppo corto e uno troppo lungo', function (string $body): void {
    actingAs($this->autore);

    post(route('events.comments.store', ['slug' => $this->event->slug]), ['body' => $body])
        ->assertSessionHasErrors('body');
})->with(['ab', fn () => str_repeat('a', 2001)]);

it('non lascia commentare chi non ha un account', function (): void {
    post(route('events.comments.store', ['slug' => $this->event->slug]), ['body' => 'Ciao a tutti'])
        ->assertRedirect(route('login'));

    expect(EventComment::query()->count())->toBe(0);
});

it('attacca la risposta a una risposta allo stesso capostipite', function (): void {
    $azione = app(PostComment::class);

    $primo = $azione->handle($this->event, $this->autore, 'La domanda');
    $risposta = $azione->handle($this->event, $this->altro, 'La risposta', $primo);
    $terzo = $azione->handle($this->event, $this->autore, 'La contro-risposta', $risposta);

    /* Un livello solo: il terzo messaggio non si annida sotto il secondo. */
    expect($risposta->parent_id)->toBe($primo->id)
        ->and($terzo->parent_id)->toBe($primo->id);
});

it('non accetta una risposta a un commento di un altro evento', function (): void {
    $altroEvento = Event::factory()->for($this->city)->published()->create();
    $estraneo = app(PostComment::class)->handle($altroEvento, $this->altro, 'Di un altro evento');

    actingAs($this->autore);

    post(route('events.comments.store', ['slug' => $this->event->slug]), [
        'body' => 'Rispondo qui',
        'parent_id' => $estraneo->id,
    ])->assertNotFound();
});

describe('reazioni', function (): void {
    it('ne tiene una sola per persona: la nuova scaccia la vecchia', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $azione = app(ToggleReaction::class);

        $azione->handle($commento, $this->altro, EventCommentReactionType::Love);
        $esito = $azione->handle($commento, $this->altro, EventCommentReactionType::Useful);

        expect($esito['stato'])->toBe('cambiata')
            ->and($esito['conteggio'])->toBe(1)
            ->and($commento->reactions()->count())->toBe(1)
            ->and($commento->reactions()->first()->type)->toBe(EventCommentReactionType::Useful);
    });

    it('si ritira premendo di nuovo la stessa', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $azione = app(ToggleReaction::class);

        $azione->handle($commento, $this->altro, EventCommentReactionType::Like);
        $esito = $azione->handle($commento, $this->altro, EventCommentReactionType::Like);

        expect($esito['stato'])->toBe('ritirata')
            ->and($esito['conteggio'])->toBe(0)
            ->and($commento->reactions()->count())->toBe(0);
    });

    it('risponde in JSON col conteggio aggiornato, per il contatore in tempo reale', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');

        actingAs($this->altro)
            ->postJson(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $commento->id]), [
                'type' => EventCommentReactionType::Love->value,
            ])
            ->assertOk()
            ->assertJson(['stato' => 'aggiunta', 'tipo' => 'love', 'conteggio' => 1]);
    });
});

describe('moderazione', function (): void {
    it('lascia nascondere allo staff del locale, ma non cancellare', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $staff = User::factory()->create();
        $this->event->venue->members()->attach($staff);

        expect($staff->can('hide', $commento))->toBeTrue()
            ->and($staff->can('delete', $commento))->toBeFalse();
    });

    it('lascia cancellare all’autore e all’amministratore', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        expect($this->autore->can('delete', $commento))->toBeTrue()
            ->and($admin->can('delete', $commento))->toBeTrue()
            ->and($this->altro->can('delete', $commento))->toBeFalse();
    });

    it('rifiuta la moderazione se il commento è cambiato nel frattempo', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        /* Revisione stantia: un altro moderatore ha già agito. */
        expect(fn () => app(ModerateComment::class)->nascondi($commento, $admin, 99, null))
            ->toThrow(ValidationException::class);
    });

    it('toglie dalla vista pubblica il commento nascosto', function (): void {
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Testo da nascondere');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        app(ModerateComment::class)->nascondi($commento, $admin, $commento->revision, 'fuori tema');

        $this->get(route('events.show', ['slug' => $this->event->slug]))
            ->assertOk()
            ->assertDontSee('Testo da nascondere', escape: false);
    });
});

describe('notifiche', function (): void {
    it('avvisa chi ha scritto il commento quando arriva una risposta', function (): void {
        Notification::fake();
        $primo = app(PostComment::class)->handle($this->event, $this->autore, 'La domanda');

        app(PostComment::class)->handle($this->event, $this->altro, 'La risposta', $primo);

        Notification::assertSentTo($this->autore, CommentActivity::class);
    });

    it('non avvisa chi risponde a sé stesso', function (): void {
        Notification::fake();
        $primo = app(PostComment::class)->handle($this->event, $this->autore, 'La domanda');

        app(PostComment::class)->handle($this->event, $this->autore, 'Mi rispondo da solo', $primo);

        Notification::assertNotSentTo($this->autore, CommentActivity::class);
    });

    it('avvisa per una reazione nuova, ma non quando viene cambiata o ritirata', function (): void {
        Notification::fake();
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');
        $azione = app(ToggleReaction::class);

        $azione->handle($commento, $this->altro, EventCommentReactionType::Like);
        $azione->handle($commento, $this->altro, EventCommentReactionType::Love);
        $azione->handle($commento, $this->altro, EventCommentReactionType::Love);

        Notification::assertSentToTimes($this->autore, CommentActivity::class, 1);
    });

    it('non avvisa chi reagisce al proprio commento', function (): void {
        Notification::fake();
        $commento = app(PostComment::class)->handle($this->event, $this->autore, 'Un commento');

        app(ToggleReaction::class)->handle($commento, $this->autore, EventCommentReactionType::Like);

        Notification::assertNothingSent();
    });

    it('rispetta la preferenza di chi ha spento le notifiche sui commenti', function (): void {
        $this->autore->notification_preferences = ['comments' => false];
        $this->autore->save();

        expect(NotificationType::CommentReply->isEnabledFor($this->autore->fresh()))->toBeFalse();
    });
});

describe('cache di pagina', function (): void {
    it('non congela la scheda evento, quindi un commento nuovo si vede subito', function (): void {
        config()->set('page_cache.enabled', true);

        /*
         * Questo test fissa un presupposto tacito e fragile: la scheda di un
         * evento è esclusa dalla full-page cache in `CachePage::isCacheable()`.
         * Se un giorno quell'uscita anticipata sparisse, i commenti si
         * congelerebbero per mezz'ora senza che nulla lo segnali — e questo
         * test fallirebbe invece di lasciar passare il guasto.
         */
        $this->get(route('events.show', ['slug' => $this->event->slug]))->assertHeaderMissing('X-Page-Cache');

        app(PostComment::class)->handle($this->event, $this->autore, 'Commento appena scritto');

        $this->get(route('events.show', ['slug' => $this->event->slug]))
            ->assertOk()
            ->assertSee('Commento appena scritto', escape: false);
    });
});
