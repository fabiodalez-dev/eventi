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
    $this->event = Event::factory()->for(testCity())->published()->create();
    $this->user = User::factory()->create();
});

it('rejects personal data and obvious spam through the native API without saving it', function (string $body) {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/events/'.$this->event->slug.'/comments', ['body' => $body])
        ->assertUnprocessable()->assertJsonValidationErrors('body', 'error.fields');
    expect(EventComment::query()->count())->toBe(0);
})->with([
    'email' => 'Scrivimi a test@example.com',
    'obfuscated email' => 'test [at] example [dot] com',
    'invisible email' => "test\u{200B}@example.com",
    'phone' => 'Chiamami al +39 333 123 4567',
    'mobile' => 'Il mio numero è 3331234567',
    'tax code' => 'RSSMRA80A01H501U',
    'iban' => 'IT60X0542811101000000123456',
    'grouped iban' => 'IT60 X054 2811 1010 0000 0123 456',
    'card' => 'Carta di prova 4111 1111 1111 1111',
    'private address' => 'Abito in via Esempio 14',
    'credential' => 'password: secret123',
    'link flood' => 'https://example.com/a https://example.com/b https://example.com/c',
    'promotion' => 'Compra follower subito',
]);

it('publishes ordinary event information immediately', function (string $body) {
    $comment = app(PostComment::class)->handle($this->event, $this->user, $body);
    expect($comment->status)->toBe(EventCommentStatus::Published);
})->with([
    'Ci vediamo il 20 settembre 2026 alle 20:30, biglietto 15 euro.',
    'Il concerto si tiene in via Roma 14, ingresso dal cortile.',
    'Programma ufficiale: https://example.com/programma',
    'Date 17/09/2026 e 18/09/2026. Orari 19:30 - 23:00.',
    'Dal 20 settembre al 21 settembre ci sono 3000 posti.',
]);

it('preserves rejected web input and blocks normalized duplicates across events', function () {
    $url = route('events.comments.store', ['slug' => $this->event->slug]);
    $this->actingAs($this->user)->from('/')->post($url, ['body' => 'test@example.com'])
        ->assertSessionHasErrors('body')->assertSessionHasInput('body', 'test@example.com');
    app(PostComment::class)->handle($this->event, $this->user, 'Ci sarò al concerto!');
    $other = Event::factory()->for($this->event->city)->published()->create();
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/events/'.$other->slug.'/comments', ['body' => "CI  SARÒ al con\u{200B}certo!"])
        ->assertUnprocessable()->assertJsonValidationErrors('body', 'error.fields');
    expect(EventComment::query()->count())->toBe(1);
});
