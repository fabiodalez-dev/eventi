<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-10-30 18:00:00', '2026-10-30 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 3, 'booking_waitlist' => true,
        'booking_questions' => [['id' => 'qabcdefgh', 'label' => 'Allergie', 'type' => 'text', 'options' => [], 'required' => false]]], ['title' => 'Cena sociale']);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->owner = User::factory()->create();
    $this->date->event->venue->members()->attach($this->owner, ['role' => 'owner']);
    $this->service = app(TicketingService::class);
});

it('prints the admitted tickets by surname, with answers and counter notes, and logs it', function (): void {
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, [['first_name' => 'Zeno', 'last_name' => 'Verdi'], ['first_name' => 'Anna', 'last_name' => 'Bianchi']], (string) Str::uuid(), false,
        ['first_name' => 'Zeno', 'last_name' => 'Verdi'], ['qabcdefgh' => 'noci']);
    $this->service->reserveForGuest($this->date, ['Carla Rossi'], ['note' => 'tavolo vicino alla finestra'], $this->owner);
    $this->service->reserve(User::factory()->create(), $this->date, ['In Coda'], (string) Str::uuid(), true);
    $this->travelTo(now()->setDate(2026, 10, 30)->setTime(17, 30));
    $this->service->checkIn($this->date, $booking->tickets->firstWhere('first_name', 'Anna')->code, $this->owner);

    $data = null;
    View::composer('ticketing.print', function ($view) use (&$data): void {
        $data ??= $view->getData();
    });
    $this->actingAs($this->owner)->get(route('ticketing.manage.print', $this->date))->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($data['tickets']->pluck('attendee_name')->all())->toBe(['Anna Bianchi', 'Carla Rossi', 'Zeno Verdi'])
        ->and($data['checkedIn'])->toBe(1)->and($data['waitlisted'])->toBe(1)
        ->and($data['tickets']->first()->status)->toBe(AdmissionStatus::CheckedIn);
    expect(Activity::query()->where('description', 'participants_printed')->count())->toBe(1);
});

it('lets only the people who manage the date print the list', function (): void {
    $this->actingAs(User::factory()->create())->get(route('ticketing.manage.print', $this->date))->assertForbidden();
    $this->actingAs($this->owner)->get(route('ticketing.manage.show', $this->date))->assertOk()->assertSee(__('ticketing.print.action'));
});

it('renders the answers and the counter note next to each name', function (): void {
    $user = User::factory()->create();
    $this->service->reserve($user, $this->date, [['first_name' => 'Zeno', 'last_name' => 'Verdi']], (string) Str::uuid(), false, ['first_name' => 'Zeno', 'last_name' => 'Verdi'], ['qabcdefgh' => 'noci']);
    $this->service->reserveForGuest($this->date, ['Carla Rossi'], ['note' => 'tavolo vicino alla finestra'], $this->owner);
    $data = null;
    View::composer('ticketing.print', function ($view) use (&$data): void {
        $data ??= $view->getData();
    });
    $this->actingAs($this->owner)->get(route('ticketing.manage.print', $this->date))->assertOk();
    $html = view('ticketing.print', $data)->render();

    expect($html)->toContain('Verdi Zeno')->toContain('Allergie: noci')->toContain('tavolo vicino alla finestra')->toContain(__('ticketing.guest.badge'));
});
