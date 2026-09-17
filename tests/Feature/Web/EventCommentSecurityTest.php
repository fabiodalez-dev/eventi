<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Enums\EventCommentStatus;
use App\Filament\Admin\Resources\EventComments\Pages\ListEventComments;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $city = testCity();
    $this->event = Event::factory()->for($city)->published()->create();
    $this->author = User::factory()->create();
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
    Filament::setTenant(null);
});

it('escapes stored XSS in comments and replies submitted through the HTTP form', function (string $payload): void {
    $this->actingAs($this->author);
    $url = route('events.comments.store', ['slug' => $this->event->slug]);
    $this->post($url, ['body' => $payload])->assertSessionHasNoErrors()->assertRedirect();
    $comment = EventComment::query()->firstOrFail();
    $this->post($url, ['body' => 'Risposta: '.$payload, 'parent_id' => $comment->id])->assertSessionHasNoErrors();
    $this->get($comment->permalink())->assertOk()->assertSee($payload)->assertDontSee($payload, escape: false);
    expect($comment->body)->toBe($payload);
})->with([
    'script' => '<script>window.commentXss=1</script>',
    'image handler' => '<img src=x onerror="window.commentXss=1">',
    'svg handler' => '<svg onload="window.commentXss=1"></svg>',
    'textarea breakout' => '</textarea><img src=x onerror="window.commentXss=1">',
    'attribute breakout' => '"><input autofocus onfocus="window.commentXss=1">',
    'javascript link' => '<a href="javascript:window.commentXss=1">clicca</a>',
    'iframe document' => '<iframe srcdoc="<script>parent.commentXss=1</script>"></iframe>',
]);

it('escapes rejected input when redisplaying the form', function (): void {
    $payload = '</textarea><img src=x onerror="window.commentXss=1">'.str_repeat('a', 2000);
    $this->actingAs($this->author)->post(route('events.comments.store', ['slug' => $this->event->slug]), ['body' => $payload])
        ->assertSessionHasErrors('body');
    $this->get(route('events.show', ['slug' => $this->event->slug]))->assertOk()
        ->assertSee($payload)->assertDontSee('<img src=x', escape: false);
    expect(EventComment::query()->count())->toBe(0);
});

it('lets administrators read, hide, restore and delete a conversation in the panel', function (string $role): void {
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $comment = app(PostComment::class)->handle($this->event, $this->author, 'Testo da moderare');
    $reply = app(PostComment::class)->handle($this->event, $this->author, 'Risposta alla conversazione', $comment);
    $this->actingAs($admin)->get('/admin/event-comments')->assertOk();
    $table = Livewire::test(ListEventComments::class)->assertCanSeeTableRecords([$comment, $reply]);
    $table->mountTableAction('dettagli', $comment)->assertMountedActionModalSee('Testo da moderare')->unmountTableAction();
    $table->callTableAction('nascondi', $comment, ['revision' => 1, 'note' => 'Contenuto fuori tema'])->assertHasNoTableActionErrors();
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Hidden)
        ->and($comment->fresh()->moderated_by)->toBe($admin->id)
        ->and($comment->fresh()->moderation_note)->toBe('Contenuto fuori tema');
    $table->mountTableAction('dettagli', $comment)->assertMountedActionModalSee('Contenuto fuori tema')->unmountTableAction();
    $table->callTableAction('ripristina', $comment, ['revision' => 2])->assertHasNoTableActionErrors();
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Published);
    $table->callTableAction('delete', $comment)->assertHasNoTableActionErrors();
    expect($comment->fresh())->toBeNull()->and($reply->fresh())->toBeNull();
})->with(['admin', 'super_admin']);

it('prevents ordinary users from accessing the panel or deleting someone else comment', function (): void {
    $comment = app(PostComment::class)->handle($this->event, $this->author, 'Commento di un altro autore');
    $this->actingAs(User::factory()->create())->get('/admin/event-comments')->assertForbidden();
    $this->delete(route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $comment]))->assertForbidden();
    expect($comment->fresh())->not->toBeNull();
});

it('allows moderators to hide but not permanently delete other users comments', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $comment = app(PostComment::class)->handle($this->event, $this->author, 'Commento da moderare');
    $this->actingAs($moderator)->get('/admin/event-comments')->assertOk();
    Livewire::test(ListEventComments::class)->assertTableActionHidden('delete', $comment)
        ->callTableAction('nascondi', $comment, ['revision' => 1, 'note' => 'Fuori tema'])->assertHasNoTableActionErrors();
    $this->delete(route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $comment]))->assertForbidden();
    expect($comment->fresh()->status)->toBe(EventCommentStatus::Hidden);
});
