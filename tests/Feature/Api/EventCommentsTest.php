<?php

use App\Actions\Comments\PostComment;
use App\Enums\EventCommentStatus;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    $city = testCity();
    $this->event = Event::factory()->for($city)->published()->create();
    $this->user = User::factory()->create();
    $this->url = '/api/v1/events/'.$this->event->slug.'/comments';
});

it('lists comments publicly without leaking private fields and disables anonymous posting', function () {
    $comment = app(PostComment::class)->handle($this->event, $this->user, '<svg onload=alert(1)>');
    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_comment', false)
        ->assertJsonPath('data.comments.0.body', '<svg onload=alert(1)>')->assertJsonPath('data.comments.0.can_delete', false);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->json('data.comments.0'))->not->toHaveKeys(['email', 'moderation_note', 'user_id']);
    $this->postJson($this->url, ['body' => 'Anonimo'])->assertUnauthorized();
});

it('supports verified posting replies reactions and deleting only owned comments', function () {
    Sanctum::actingAs($this->user);
    $id = $this->postJson($this->url, ['body' => 'Domanda sul concerto'])->assertCreated()->json('data.id');
    $reply = $this->postJson($this->url, ['body' => 'Risposta alla domanda', 'parent_id' => $id])->assertCreated()->json('data.id');
    $this->postJson($this->url.'/'.$reply.'/reaction', ['type' => 'useful'])->assertOk()->assertJsonPath('data.count', 1);
    $this->getJson($this->url.'?commento='.$reply)->assertOk()->assertJsonPath('data.thread', $id)
        ->assertJsonPath('data.comments.0.replies.0.my_reaction', 'useful');
    Sanctum::actingAs(User::factory()->create());
    $this->deleteJson($this->url.'/'.$id)->assertForbidden();
    Sanctum::actingAs($this->user);
    $this->deleteJson($this->url.'/'.$id)->assertOk();
    expect(EventComment::query()->count())->toBe(0);
});

it('rejects unverified writes and malformed inputs', function () {
    $comment = app(PostComment::class)->handle($this->event, $this->user, 'Esistente');
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson($this->url, ['body' => 'Spam'])->assertForbidden();
    $this->postJson($this->url.'/'.$comment->id.'/reaction', ['type' => 'like'])->assertForbidden();
    Sanctum::actingAs($this->user);
    $this->postJson($this->url, ['body' => 'x'])->assertUnprocessable();
    $this->postJson($this->url, ['body' => str_repeat('a', 2001)])->assertUnprocessable();
    $this->postJson($this->url.'/'.$comment->id.'/reaction', ['type' => 'bad'])->assertUnprocessable();
    $this->getJson($this->url.'?commenti=-1')->assertUnprocessable();
});

it('never returns hidden text or private moderation reasons even to the author', function () {
    $comment = app(PostComment::class)->handle($this->event, $this->user, 'Testo privato');
    $comment->forceFill(['status' => EventCommentStatus::Hidden, 'moderation_note' => 'Motivo privato'])->save();
    $this->getJson($this->url)->assertJsonCount(0, 'data.comments');
    Sanctum::actingAs($this->user);
    $this->getJson($this->url)->assertJsonPath('data.comments.0.body', null)
        ->assertJsonPath('data.comments.0.hidden', true)->assertDontSee('Testo privato')->assertDontSee('Motivo privato');
});

it('rejects parent or reaction records belonging to other events', function () {
    $other = Event::factory()->for($this->event->city)->published()->create();
    $comment = app(PostComment::class)->handle($other, $this->user, 'Altra conversazione');
    Sanctum::actingAs($this->user);
    $this->postJson($this->url, ['body' => 'Risposta', 'parent_id' => $comment->id])->assertNotFound();
    $this->postJson($this->url.'/'.$comment->id.'/reaction', ['type' => 'like'])->assertNotFound();
    $this->deleteJson($this->url.'/'.$comment->id)->assertNotFound();
});
