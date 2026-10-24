<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-10-30 18:00:00', '2026-10-30 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 10]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->owner = User::factory()->create();
    $this->date->event->venue->members()->attach($this->owner, ['role' => 'owner']);
    $this->user = User::factory()->create();
    $this->service = app(TicketingService::class);
});

function configureQuestions(object $test, array $rows): TestResponse
{
    return $test->actingAs($test->owner)->post(route('ticketing.manage.configure', $test->date), [
        'booking_enabled' => 1, 'booking_limit' => 4, 'booking_waitlist' => 0, 'booking_questions' => $rows,
    ]);
}

function shirtQuestions(object $test): array
{
    configureQuestions($test, [
        ['label' => 'Taglia della maglietta', 'type' => 'choice', 'options' => "S\nM\nL\n\nM", 'required' => 1],
        ['label' => 'Allergie o intolleranze', 'type' => 'text', 'required' => 0],
        ['label' => 'Vieni in bicicletta?', 'type' => 'yes_no', 'required' => 1],
        ['label' => '', 'type' => 'text'],
    ])->assertRedirect(route('ticketing.manage.show', $test->date));

    return $test->service->availability($test->date->fresh())['questions'];
}

it('lets the manager set up to five questions and shows them on the site and the API', function (): void {
    $questions = shirtQuestions($this);

    expect($questions)->toHaveCount(3)
        ->and($questions[0])->toMatchArray(['label' => 'Taglia della maglietta', 'type' => 'choice', 'options' => ['S', 'M', 'L'], 'required' => true])
        ->and($questions[0]['id'])->toMatch('/^q[a-z0-9]{8}$/')
        ->and($questions[2]['type'])->toBe('yes_no');
    $this->getJson('/api/v1/occurrences/'.$this->date->id.'/booking')->assertOk()->assertJsonPath('data.questions.1.label', 'Allergie o intolleranze');
    $this->actingAs($this->user)->get(route('tickets.create', $this->date))->assertOk()->assertSee('Taglia della maglietta')->assertSee('name="answers['.$questions[0]['id'].']"', false);

    // Saving again keeps the ids, so earlier answers stay attached to their question.
    configureQuestions($this, array_map(fn (array $q): array => ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'options' => implode("\n", $q['options']), 'required' => (int) $q['required']], $questions));
    expect(array_column($this->service->availability($this->date->fresh())['questions'], 'id'))->toBe(array_column($questions, 'id'));
});

it('rejects a choice question with fewer than two options', function (): void {
    configureQuestions($this, [['label' => 'Menu', 'type' => 'choice', 'options' => 'Solo vegano']])->assertSessionHasErrors('booking_questions.0.options');
    expect($this->date->fresh()->booking_questions)->toBeNull();
});

it('stores validated answers with the question text, shows them to the manager and the booker, and exports them', function (): void {
    [$size, $allergies, $bike] = shirtQuestions($this);

    $booking = $this->service->reserve($this->user, $this->date->fresh(), ['Ada'], (string) Str::uuid(), false, [], [$size['id'] => 'M', $allergies['id'] => ' noci ', $bike['id'] => '0', 'qzzzzzzzz' => 'ignorata']);

    expect($booking->fresh()->booking_answers)->toBe([
        ['id' => $size['id'], 'question' => 'Taglia della maglietta', 'answer' => 'M'],
        ['id' => $allergies['id'], 'question' => 'Allergie o intolleranze', 'answer' => 'noci'],
        ['id' => $bike['id'], 'question' => 'Vieni in bicicletta?', 'answer' => 'No'],
    ])->and(DB::table('bookings')->where('id', $booking->id)->value('booking_answers'))->not->toContain('noci');
    $this->actingAs($this->owner)->get(route('ticketing.manage.show', $this->date))->assertOk()->assertSee('Allergie o intolleranze')->assertSee('noci');
    $this->actingAs($this->user)->getJson('/api/v1/me/bookings')->assertOk()->assertJsonPath('data.0.answers.0', ['question' => 'Taglia della maglietta', 'answer' => 'M']);
    $csv = $this->actingAs($this->owner)->get(route('ticketing.manage.export', $this->date))->assertOk()->streamedContent();
    expect($csv)->toContain('Taglia della maglietta')->toContain(';M;noci;No');
});

it('refuses missing required answers and choices outside the list', function (array $answers): void {
    [$size, , $bike] = shirtQuestions($this);
    $answers = array_combine(array_map(fn (string $key) => $key === 'size' ? $size['id'] : $bike['id'], array_keys($answers)), $answers);

    expect(fn () => $this->service->reserve($this->user, $this->date->fresh(), ['Ada'], (string) Str::uuid(), false, [], $answers))->toThrow(ValidationException::class)
        ->and(Booking::count())->toBe(0);
})->with([
    'manca la taglia' => [['bike' => '1']],
    'taglia inventata' => [['size' => 'XXL', 'bike' => '1']],
    'manca il sì o no' => [['size' => 'S']],
]);

it('takes answers through the API booking endpoint', function (): void {
    [$size, , $bike] = shirtQuestions($this);
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', [
        'attendees' => ['Ada Rossi'], 'request_key' => (string) Str::uuid(), 'accept_terms' => true, 'answers' => [$size['id'] => 'L', $bike['id'] => true],
    ])->assertCreated()->assertJsonPath('data.answers.1', ['question' => 'Vieni in bicicletta?', 'answer' => 'Sì']);
});

it('erases the answers thirty days after the date, and only then', function (): void {
    [$size, , $bike] = shirtQuestions($this);
    $booking = $this->service->reserve($this->user, $this->date->fresh(), ['Ada'], (string) Str::uuid(), false, [], [$size['id'] => 'S', $bike['id'] => '1']);

    $this->travelTo(now()->setDate(2026, 11, 28));
    expect($this->service->pruneAnswers())->toBe(0);
    $this->travelTo(now()->setDate(2026, 12, 1));
    $this->artisan('ticketing:prune-answers')->assertSuccessful();
    expect($booking->fresh()->booking_answers)->toBeNull();
});
