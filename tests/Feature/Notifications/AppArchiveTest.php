<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Actions\InviteVenueMemberAction;
use App\Enums\ApplicationKind;
use App\Enums\NotificationType;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Models\EventViewDaily;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\AppPush;
use App\Notifications\VenueAccessGranted;
use App\Notifications\VenueModerated;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\SentMessage;

/*
 * L'archivio e la push degli avvisi che erano solo email (Q3), dopo la
 * revisione di sicurezza:
 *
 * - un guasto nella scrittura della casella non fa fallire l'email già
 *   partita né annulla la transazione che l'ha mandata (approvazione,
 *   invito): il collegamento per la password resta valido, il rapporto
 *   mensile non riparte;
 * - nell'archivio non finisce il collegamento firmato per accettare un invito;
 * - una push rimandata dalle ore di silenzio ricontrolla l'accesso al soggetto.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    config(['notifications.quiet_hours.enabled' => false]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Le email partite davvero (mailer `array` dei test) verso un indirizzo. */
function archivioMails(string $email): int
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn (SentMessage $sent): bool => collect($sent->getEnvelope()->getRecipients())->contains(fn ($address): bool => $address->getAddress() === $email))
        ->count();
}

/** Il gettone per scegliere la password dentro l'ultima email partita verso un indirizzo. */
function archivioToken(string $email): ?string
{
    $sent = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn (SentMessage $sent): bool => collect($sent->getEnvelope()->getRecipients())->contains(fn ($address): bool => $address->getAddress() === $email))
        ->last();
    preg_match('~token=([A-Za-z0-9]+)~', (string) $sent?->getOriginalMessage()->getHtmlBody(), $match);

    return $match[1] ?? null;
}

/** La casella degli avvisi non si scrive: il guasto del database che la revisione ipotizza. */
function archivioGuasto(): void
{
    DatabaseNotification::creating(static function (): never {
        throw new RuntimeException('casella non scrivibile');
    });
}

it('approva una richiesta di organizzazione anche se la casella non si scrive, e il collegamento per la password resta valido', function (): void {
    archivioGuasto();
    $application = VenueApplication::factory()->create([
        'kind' => ApplicationKind::Organizer, 'user_id' => null, 'venue_name' => 'Collettivo Portici',
        'contact_email' => 'luca@esempio.test', 'contact_name' => 'Luca Rossi',
    ]);

    $organizer = app(ApproveVenueApplication::class)->handle($application, User::factory()->create());

    $owner = User::query()->where('email', 'luca@esempio.test')->sole();
    $token = archivioToken('luca@esempio.test');
    expect($organizer)->toBeInstanceOf(Organizer::class)
        ->and($organizer->exists)->toBeTrue()
        ->and($application->fresh()->organizer_id)->toBe($organizer->id)
        ->and(archivioMails('luca@esempio.test'))->toBe(1)
        ->and($token)->toBeString()
        ->and(Password::broker()->tokenExists($owner, (string) $token))->toBeTrue()
        ->and($owner->notifications()->count())->toBe(0);
});

it('invita in un locale anche se la casella non si scrive, e il collegamento per la password resta valido', function (): void {
    archivioGuasto();
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);

    $invited = app(InviteVenueMemberAction::class)->execute($venue, 'nuova@esempio.test', 'Nuova Collaboratrice', VenueRole::Editor);

    $token = archivioToken('nuova@esempio.test');
    expect($invited->exists)->toBeTrue()
        ->and($invited->venuesIncludingInvited()->whereKey($venue->id)->exists())->toBeTrue()
        ->and(archivioMails('nuova@esempio.test'))->toBe(1)
        ->and($token)->toBeString()
        ->and(Password::broker()->tokenExists($invited, (string) $token))->toBeTrue()
        ->and($invited->notifications()->count())->toBe(0);
});

it('non rimanda il rapporto mensile quando dopo l email la casella non si scrive', function (): void {
    freezeLocal($this->city, '2026-10-01 08:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $owner = User::factory()->create();
    $venue->members()->attach($owner, ['role' => 'owner']);
    $occurrence = occurrenceAtLocal($this->city, testCategory(), '2026-09-12 21:00', '2026-09-12 23:00', venue: $venue);
    EventViewDaily::query()->create(['event_id' => $occurrence->event_id, 'date' => '2026-09-12', 'views' => 40]);
    archivioGuasto();

    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();
    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();

    expect(archivioMails($owner->email))->toBe(1)
        ->and($owner->notifications()->count())->toBe(0);
});

it('scrive la riga dopo il commit, e non la scrive se la transazione si annulla', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $owner = User::factory()->create();
    $venue->members()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);

    DB::transaction(function () use ($owner, $venue): void {
        $owner->notifyNow(new VenueModerated($venue, VenueStatus::Approved));
        expect($owner->notifications()->count())->toBe(0);
    });
    expect($owner->notifications()->count())->toBe(1);

    try {
        DB::transaction(function () use ($owner, $venue): void {
            $owner->notifyNow(new VenueModerated($venue, VenueStatus::Approved));
            throw new RuntimeException('annullata');
        });
    } catch (RuntimeException) {
    }
    expect($owner->notifications()->count())->toBe(1);
});

it('non mette nell archivio, nella coda né nella push il collegamento firmato per accettare l invito', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $invited = User::factory()->create();
    $invited->venuesIncludingInvited()->attach($venue, ['role' => 'editor', 'invited_at' => now(), 'accepted_at' => null]);
    $accept = 'https://example.test/inviti-locali/1/2/3?expires=9999999999&signature=firma-segreta';
    $notification = new VenueAccessGranted($venue, VenueRole::Editor, needsPassword: false, acceptUrl: $accept);

    $invited->notifyNow($notification);

    $row = $invited->notifications()->sole();
    $push = $notification->appPush($invited);
    $fcm = (new AppPush($push))->toFcm($invited)->toArray();
    expect($row->data['url'])->toBe(route('login'))
        ->and(json_encode($row->data))->not->toContain('firma-segreta')
        ->and($row->data['body'])->toContain(__('manage.invitation.app_answer_hint'))
        ->and($row->data['management']['area'])->toBe('invitations')
        ->and(json_encode($push))->not->toContain('firma-segreta')
        ->and(serialize(new AppPush($push)))->not->toContain('firma-segreta')
        ->and(json_encode($fcm))->not->toContain('firma-segreta')
        /* L'email resta quella di prima, con il collegamento firmato. */
        ->and($notification->toMail($invited)->actionUrl)->toBe($accept);
});

it('alla consegna non manda la push di un locale o di un organizzazione a chi non ci entra più', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $owner = User::factory()->create();
    $venue->members()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    $payload = static fn (string $type, int $id): array => ['type' => NotificationType::VenueModerated->value, 'title' => 'T', 'body' => 'B', 'url' => 'https://example.test',
        'event_id' => null, 'management' => ['subject_type' => $type, 'subject_id' => $id, 'area' => 'home', 'id' => null]];

    $toVenue = new AppPush($payload('venue', $venue->id));
    $toOrganizer = new AppPush($payload('organizer', $organizer->id));
    expect($toVenue->shouldSend($owner, 'fcm'))->toBeTrue()
        ->and($toOrganizer->shouldSend($owner, 'fcm'))->toBeTrue();

    /* Nel frattempo è stato tolto dal locale e l'organizzazione è passata a un altro. */
    $venue->membersIncludingInvited()->detach($owner);
    $organizer->forceFill(['owner_id' => User::factory()->create()->id])->save();

    expect($toVenue->shouldSend($owner, 'fcm'))->toBeFalse()
        ->and($toOrganizer->shouldSend($owner, 'fcm'))->toBeFalse()
        /* Un locale che non c'è più, un soggetto sconosciuto, l'account di un altro. */
        ->and((new AppPush($payload('venue', 999999)))->shouldSend($owner, 'fcm'))->toBeFalse()
        ->and((new AppPush($payload('boh', $venue->id)))->shouldSend($owner, 'fcm'))->toBeFalse()
        ->and((new AppPush($payload('account', $owner->id)))->shouldSend($owner, 'fcm'))->toBeTrue()
        ->and((new AppPush($payload('account', $owner->id + 1)))->shouldSend($owner, 'fcm'))->toBeFalse();
});
