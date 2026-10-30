<?php

declare(strict_types=1);

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\PostComment;
use App\Enums\EventCommentStatus;
use App\Enums\NotificationType;
use App\Enums\ProfileVisibility;
use App\Filament\Venue\Resources\EventComments\Pages\ListEventComments;
use App\Http\Controllers\Web\ImpersonationController;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventCommentReaction;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Services\Notifications\MessageFactory;
use App\Services\Reviews\CatalogReviews;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Commenti agli eventi: chi compare con quale nome, chi può ancora scrivere
 * (sospesi, bloccati, impersonificazione) e chi rimette in vista ciò che la
 * redazione ha nascosto.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $this->city = testCity();
    $this->event = Event::factory()->for($this->city)->published()->create();
    $this->webUrl = route('events.comments.store', ['slug' => $this->event->slug]);
    $this->apiUrl = '/api/v1/events/'.$this->event->slug.'/comments';
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

function commentsPerson(string $first = 'Mario', string $last = 'Rossi'): User
{
    return User::factory()->create(['first_name' => $first, 'last_name' => $last, 'name' => $first.' '.$last]);
}

// ------------------------------------------------------------ nome pubblico

it('mostra nell\'API dei commenti solo il nome proprio, mai il cognome', function (): void {
    $author = commentsPerson();
    app(PostComment::class)->handle($this->event, $author, 'Domanda sul parcheggio');

    $this->getJson($this->apiUrl)->assertOk()
        ->assertJsonPath('data.comments.0.author', 'Mario')
        ->assertDontSee('Rossi');
    $this->get(route('events.show', ['slug' => $this->event->slug]))->assertOk()->assertDontSee('Rossi');
});

it('non mette il cognome nel titolo degli avvisi di risposta', function (): void {
    $author = commentsPerson('Anna', 'Bianchi');
    $replier = commentsPerson('Mario', 'Rossi');
    $comment = app(PostComment::class)->handle($this->event, $author, 'Commento di partenza');
    app(PostComment::class)->handle($this->event, $replier, 'Una risposta qualunque', $comment);

    $row = ScheduledNotification::query()->where('type', NotificationType::CommentReply->value)->sole();
    $message = app(MessageFactory::class)->build($row, $author);

    expect($message->subject)->toContain('Mario')->not->toContain('Rossi');
});

it('non pubblica il nome completo di chi recensisce senza un profilo community', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $reviewer = carpoolPerson(false);
    $reviewer->forceFill(['first_name' => 'Mario', 'last_name' => 'Rossi', 'name' => 'Mario Rossi'])->save();
    $reviews = app(CatalogReviews::class);
    $review = $reviews->submit($venue, $reviewer, 4, 'Cena ottima, servizio un po\' lento.');
    $reviews->moderate($review, cpStaff(), 'approved', $review->revision, null);

    $this->getJson('/api/v1/venues/'.$venue->slug.'/reviews')->assertOk()
        ->assertJsonPath('data.reviews.0.author', 'Mario')->assertDontSee('Rossi');
    $this->get(route('venues.show', ['slug' => $venue->slug]))->assertOk()->assertDontSee('Rossi');
});

it('usa il nome del profilo solo se chi legge può vedere il profilo', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $reviewer = carpoolPerson(false);
    $reviewer->forceFill(['first_name' => 'Mario', 'last_name' => 'Rossi', 'name' => 'Mario Rossi'])->save();
    $reviewer->communityProfile()->create(['handle' => 'pseudo_'.$reviewer->id, 'display_name' => 'Pseudonimo', 'visibility' => ProfileVisibility::Members]);
    $reviews = app(CatalogReviews::class);
    $review = $reviews->submit($venue, $reviewer, 4, 'Cena ottima, servizio un po\' lento.');
    $reviews->moderate($review, cpStaff(), 'approved', $review->revision, null);
    $url = '/api/v1/venues/'.$venue->slug.'/reviews';

    $this->getJson($url)->assertOk()->assertJsonPath('data.reviews.0.author', 'Mario')->assertDontSee('Pseudonimo');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson($url)->assertOk()->assertJsonPath('data.reviews.0.author', 'Pseudonimo');

    $reviewer->communityProfile->forceFill(['visibility' => ProfileVisibility::Private])->save();
    $this->getJson($url)->assertOk()->assertJsonPath('data.reviews.0.author', 'Mario');
});

// ------------------------------------------------------------- sospensione

it('ferma commenti, risposte e reazioni di chi è sospeso dalla community', function (string $field): void {
    $other = User::factory()->create();
    $comment = app(PostComment::class)->handle($this->event, $other, 'Commento di un altro');
    $suspended = User::factory()->create([$field => now()]);

    $this->actingAs($suspended)->post($this->webUrl, ['body' => 'Testo dopo la sospensione'])->assertForbidden();
    $this->post($this->webUrl, ['body' => 'Risposta dopo la sospensione', 'parent_id' => $comment->id])->assertForbidden();
    $this->post(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $comment->id]), ['type' => 'like'])->assertForbidden();

    Sanctum::actingAs($suspended);
    $this->postJson($this->apiUrl, ['body' => 'Testo dopo la sospensione'])->assertForbidden();
    $this->postJson($this->apiUrl.'/'.$comment->id.'/reaction', ['type' => 'like'])->assertForbidden();
    $this->getJson($this->apiUrl)->assertOk()->assertJsonPath('data.can_comment', false);

    expect(EventComment::query()->where('user_id', $suspended->id)->exists())->toBeFalse()
        ->and(EventCommentReaction::query()->where('user_id', $suspended->id)->exists())->toBeFalse()
        ->and(ScheduledNotification::query()->where('payload->actor_id', $suspended->id)->exists())->toBeFalse();
})->with(['community_suspended_at', 'social_suspended_at']);

it('toglie dalla vista pubblica i commenti di chi viene sospeso e li rimette quando la sospensione cade', function (): void {
    $author = commentsPerson();
    app(PostComment::class)->handle($this->event, $author, 'Testo prima della sospensione');
    $this->getJson($this->apiUrl)->assertJsonCount(1, 'data.comments');

    $author->forceFill(['community_suspended_at' => now()])->save();
    $this->getJson($this->apiUrl)->assertJsonCount(0, 'data.comments');
    $this->get(route('events.show', ['slug' => $this->event->slug]))->assertOk()->assertDontSee('Testo prima della sospensione');

    $author->forceFill(['community_suspended_at' => null])->save();
    $this->getJson($this->apiUrl)->assertJsonCount(1, 'data.comments');
});

it('lascia commentare e reagire chi non è sospeso', function (): void {
    $other = User::factory()->create();
    $comment = app(PostComment::class)->handle($this->event, $other, 'Commento di un altro');
    Sanctum::actingAs(User::factory()->create());

    $this->getJson($this->apiUrl)->assertJsonPath('data.can_comment', true);
    $this->postJson($this->apiUrl, ['body' => 'Un commento normale'])->assertCreated();
    $this->postJson($this->apiUrl.'/'.$comment->id.'/reaction', ['type' => 'like'])->assertOk();
});

// ------------------------------------------------------------------ blocchi

it('rifiuta risposte e reazioni fra persone che si sono bloccate', function (bool $victimBlocks): void {
    $victim = User::factory()->create();
    $harasser = User::factory()->create();
    UserBlock::create($victimBlocks ? ['user_id' => $victim->id, 'blocked_user_id' => $harasser->id] : ['user_id' => $harasser->id, 'blocked_user_id' => $victim->id]);
    $comment = app(PostComment::class)->handle($this->event, $victim, 'Commento della vittima');

    $this->actingAs($harasser)->post($this->webUrl, ['body' => 'Risposta indesiderata', 'parent_id' => $comment->id])->assertForbidden();
    $this->post(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $comment->id]), ['type' => 'like'])->assertForbidden();
    Sanctum::actingAs($harasser);
    $this->postJson($this->apiUrl, ['body' => 'Risposta indesiderata', 'parent_id' => $comment->id])->assertForbidden();

    expect(EventComment::query()->where('user_id', $harasser->id)->exists())->toBeFalse()
        ->and(EventCommentReaction::query()->where('user_id', $harasser->id)->exists())->toBeFalse()
        ->and(ScheduledNotification::query()->where('user_id', $victim->id)->exists())->toBeFalse();
})->with(['la vittima blocca' => true, 'il molestatore blocca' => false]);

it('rifiuta la risposta sotto la conversazione di chi ha bloccato, anche rispondendo a un terzo', function (): void {
    $victim = User::factory()->create();
    $harasser = User::factory()->create();
    UserBlock::create(['user_id' => $victim->id, 'blocked_user_id' => $harasser->id]);
    $root = app(PostComment::class)->handle($this->event, $victim, 'Commento della vittima');
    $third = app(PostComment::class)->handle($this->event, User::factory()->create(), 'Risposta di un terzo', $root);

    $this->actingAs($harasser)->post($this->webUrl, ['body' => 'Risposta indesiderata', 'parent_id' => $third->id])->assertForbidden();
    expect(EventComment::query()->where('user_id', $harasser->id)->exists())->toBeFalse();
});

it('nasconde a chi blocca i commenti della persona bloccata, non agli anonimi', function (): void {
    $victim = User::factory()->create();
    $harasser = User::factory()->create();
    app(PostComment::class)->handle($this->event, $harasser, 'Testo del molestatore');
    UserBlock::create(['user_id' => $victim->id, 'blocked_user_id' => $harasser->id]);

    $this->getJson($this->apiUrl)->assertSee('Testo del molestatore');
    Sanctum::actingAs($victim);
    $this->getJson($this->apiUrl)->assertOk()->assertJsonCount(0, 'data.comments')->assertDontSee('Testo del molestatore');
});

it('non avvisa lo staff che ha bloccato l\'autore di un commento', function (): void {
    $s = VenueIsolationScenario::make();
    $author = User::factory()->create();
    UserBlock::create(['user_id' => $s->ownerA->id, 'blocked_user_id' => $author->id]);

    app(PostComment::class)->handle($s->publishedEventA, $author, 'Commento sul locale');

    expect(ScheduledNotification::query()->where('user_id', $s->ownerA->id)->exists())->toBeFalse()
        ->and(ScheduledNotification::query()->where('user_id', $s->editorA->id)->where('type', NotificationType::EventNewComment->value)->exists())->toBeTrue();
});

it('lascia rispondere chi non è bloccato, e avvisa l\'autore', function (): void {
    $victim = User::factory()->create();
    $comment = app(PostComment::class)->handle($this->event, $victim, 'Commento della vittima');

    $this->actingAs(User::factory()->create())->post($this->webUrl, ['body' => 'Risposta gentile', 'parent_id' => $comment->id])->assertRedirect();
    expect(ScheduledNotification::query()->where('user_id', $victim->id)->where('type', NotificationType::CommentReply->value)->exists())->toBeTrue();
});

// -------------------------------------------------------- impersonificazione

it('non lascia scrivere, reagire o cancellare commenti durante l\'impersonificazione', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    $own = app(PostComment::class)->handle($this->event, $user, 'Commento dell\'utente');
    $session = [ImpersonationController::SESSION_KEY => $admin->id];

    $this->actingAs($user)->withSession($session)->post($this->webUrl, ['body' => 'Testo dell\'amministratore'])->assertForbidden();
    $this->withSession($session)->post(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $own->id]), ['type' => 'like'])->assertForbidden();
    $this->withSession($session)->delete(route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $own->id]))->assertForbidden();

    expect(EventComment::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(EventCommentReaction::query()->count())->toBe(0)
        ->and($own->fresh())->not->toBeNull();
});

it('lascia commentare normalmente fuori dall\'impersonificazione', function (): void {
    $this->actingAs(User::factory()->create())->post($this->webUrl, ['body' => 'Commento normale'])->assertRedirect();
    expect(EventComment::query()->count())->toBe(1);
});

it('blocca durante l\'impersonificazione cancellazione dell\'account, scarico dati e prenotazioni', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    $occurrence = $this->event->occurrences()->first() ?? occurrenceAt($this->city, testCategory(), '2030-01-10 21:00:00', '2030-01-10 23:00:00');
    $this->actingAs($admin)->get(URL::signedRoute('impersonate.start', ['user' => $user->id]))->assertRedirect('/');

    $this->delete(route('account.profile.destroy'), ['conferma' => __('account.profile.delete_keyword')])->assertForbidden();
    $this->get(route('account.profile.export'))->assertForbidden();
    $this->patch(route('account.profile.update'), ['first_name' => 'Altro'])->assertForbidden();
    $this->post(route('tickets.store', ['occurrence' => $occurrence->id]), [])->assertForbidden();
    expect($user->fresh())->not->toBeNull()->and($user->fresh()->first_name)->not->toBe('Altro');

    // L'uscita resta sempre libera.
    $this->post(route('account.logout'))->assertRedirect();
});

it('lascia scaricare i propri dati fuori dall\'impersonificazione', function (): void {
    $this->actingAs(User::factory()->create())->get(route('account.profile.export'))->assertOk();
});

// ------------------------------------------------------ moderazione e locale

it('non lascia al locale rimettere in vista un commento nascosto dalla redazione', function (string $staff): void {
    $s = VenueIsolationScenario::make();
    $comment = app(PostComment::class)->handle($s->publishedEventA, User::factory()->create(), 'Commento con dati di un terzo');
    app(ModerateComment::class)->nascondi($comment, $s->{$staff}, 1, 'Dati personali');
    $comment = $comment->fresh();

    expect($s->editorA->can('restore', $comment))->toBeFalse()
        ->and($s->ownerA->can('restore', $comment))->toBeFalse()
        ->and(fn () => app(ModerateComment::class)->ripristina($comment, $s->editorA, $comment->revision))->toThrow(AuthorizationException::class);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Hidden)
        ->and($comment->fresh()->moderation_note)->toBe('Dati personali');

    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/event-comments')->assertOk();
    Livewire::test(ListEventComments::class)->assertTableActionHidden('ripristina', $comment);
})->with(['moderator', 'admin']);

it('lascia al locale rimettere in vista ciò che ha nascosto, e alla redazione tutto', function (): void {
    $s = VenueIsolationScenario::make();
    $comment = app(PostComment::class)->handle($s->publishedEventA, User::factory()->create(), 'Commento fuori tema');

    app(ModerateComment::class)->nascondi($comment, $s->ownerA, 1, 'Fuori tema');
    app(ModerateComment::class)->ripristina($comment->fresh(), $s->editorA, 2);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);

    app(ModerateComment::class)->nascondi($comment->fresh(), $s->ownerA, 3, 'Ancora fuori tema');
    app(ModerateComment::class)->ripristina($comment->fresh(), $s->moderator, 4);
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);
});
