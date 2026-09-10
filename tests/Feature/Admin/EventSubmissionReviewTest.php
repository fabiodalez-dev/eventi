<?php

use App\Actions\PublishEventSubmission;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EventSubmissions\Pages\EditEventSubmission;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin);
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->category = testCategory();
    $this->submission = EventSubmission::factory()->create(['city_id' => $this->city->id, 'starts_at_hint' => '2026-09-10 19:00:00']);
    $this->data = ['title' => 'Concerto proposto', 'raw_text' => 'Descrizione controllata', 'venue_id' => $this->venue->id,
        'category_id' => $this->category->id, 'starts_at_hint' => '2026-09-10 19:00:00', 'price_type' => PriceType::Unknown->value];
});

it('publishes a submission once with an occurrence and a private review trail', function (): void {
    $action = app(PublishEventSubmission::class);
    $event = $action->handle($this->submission, $this->admin, $this->data);
    expect($event->status)->toBe(EventStatus::Published)->and($event->source)->toBe(EventSource::Submission);
    expect($event->occurrences()->first()->starts_at->utc()->format('H:i'))->toBe('19:00');
    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Approved)
        ->and($this->submission->fresh()->reviewed_by)->toBe($this->admin->id);
    expect($action->handle($this->submission, $this->admin, $this->data)->id)->toBe($event->id);
    expect(Event::count())->toBe(1);
    $this->get('/eventi/'.$event->slug)->assertOk()->assertSee('Concerto proposto')->assertDontSee($this->submission->contact_email);
});

it('publishes from the prefilled admin form in the city timezone', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Livewire::test(EditEventSubmission::class, ['record' => $this->submission->id])
        ->assertFormSet(['title' => $this->submission->title, 'starts_at_hint' => '2026-09-10 19:00'])
        ->assertSet('data.starts_at_hint', '2026-09-10 21:00')
        ->fillForm(['venue_id' => $this->venue->id, 'category_id' => $this->category->id])
        ->callAction('publish')->assertHasNoFormErrors()->assertHasNoActionErrors();
    expect($this->submission->fresh()->event->occurrences()->first()->starts_at->utc()->format('H:i'))->toBe('19:00');
});

it('rejects invalid venues without creating an event', function (): void {
    $other = Venue::factory()->approved()->create();
    expect(fn () => app(PublishEventSubmission::class)->handle($this->submission, $this->admin, [...$this->data, 'venue_id' => $other->id]))->toThrow(ValidationException::class);
    expect(Event::count())->toBe(0)->and($this->submission->fresh()->status)->toBe(SubmissionStatus::Pending);
});

it('prevents a regular user from reviewing or publishing proposals', function (): void {
    $user = User::factory()->create();
    expect($user->can('viewAny', EventSubmission::class))->toBeFalse();
    expect(fn () => app(PublishEventSubmission::class)->handle($this->submission, $user, $this->data))->toThrow(AuthorizationException::class);
});

it('rejects a proposal without publishing and records the reason', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Livewire::test(EditEventSubmission::class, ['record' => $this->submission->id])
        ->callAction('reject', data: ['notes' => 'Evento fuori ambito'])->assertHasNoActionErrors();
    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Rejected)
        ->and($this->submission->fresh()->notes)->toBe('Evento fuori ambito');
    expect(fn () => app(PublishEventSubmission::class)->handle($this->submission, $this->admin, $this->data))->toThrow(ValidationException::class);
    expect(Event::count())->toBe(0);
});

it('uses the same light-only theme across all back-office panels', function (string $panel): void {
    $config = Filament::getPanel($panel);
    expect($config->hasDarkMode())->toBeFalse()->and($config->getFontFamily())->toBe('Manrope Variable');
})->with(['admin', 'venue', 'organizer']);
