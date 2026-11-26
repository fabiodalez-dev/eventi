<?php

declare(strict_types=1);

use App\Actions\ModerateVenueAction;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\AppPush;
use App\Notifications\Channels\AppPushChannel;
use App\Notifications\OrganizerAccessGranted;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipWeeklyReport;
use App\Notifications\VenueAccessGranted;
use App\Notifications\VenueApplicationApproved;
use App\Notifications\VenueApplicationRejected;
use App\Notifications\VenueModerated;
use App\Notifications\VenueMonthlyReport;
use App\Services\Management\ManagementNoticeTarget;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use NotificationChannels\Fcm\FcmChannel;
use Symfony\Component\Mailer\SentMessage;
use Tests\Support\VenueIsolationScenario;

/*
 * Q3 — Gli avvisi che erano solo email arrivano anche nell'app (W-SP-14,
 * W-AP-07, W-NT-04): l'email resta identica e parte una volta sola; accanto
 * c'è la riga nella casella dell'account (con il bersaglio nativo, ricontrollato
 * con `canAccessTenant`) e la push, che segue `ChannelSelector`, le ore di
 * silenzio e l'interruttore del rapporto mensile.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    config(['notifications.quiet_hours.enabled' => false]);
});

/** Le email partite davvero (mailer `array` dei test) verso un indirizzo. */
function q3Mails(string $email): int
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn (SentMessage $sent): bool => collect($sent->getEnvelope()->getRecipients())->contains(fn ($address): bool => $address->getAddress() === $email))
        ->count();
}

function q3Order(Venue $venue, User $buyer, SponsorshipOrderStatus $status = SponsorshipOrderStatus::Approved): SponsorshipOrder
{
    return SponsorshipOrder::create(['venue_id' => $venue->id, 'user_id' => $buyer->id, 'placement' => 'list_top', 'mode' => 'automatic',
        'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'status' => $status, 'decision_note' => "Fuori dal periodo\nnota interna"]);
}

function q3Campaign(VenueIsolationScenario $s, ?string $advertiser = 'committente@prova.test'): Sponsorship
{
    $grant = SponsorshipGrant::factory()->create(['venue_id' => $s->venueA->id,
        'starts_at' => CarbonImmutable::now('UTC')->subDays(10), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10)]);

    return Sponsorship::factory()->create(['event_id' => $s->publishedEventA->id, 'sponsorship_grant_id' => $grant->id,
        'advertiser_name' => 'Circolo Aurora', 'advertiser_email' => $advertiser,
        'starts_at' => CarbonImmutable::now('UTC')->subDays(10), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10)]);
}

function q3Report(Sponsorship $campaign): SponsorshipWeeklyReport
{
    return new SponsorshipWeeklyReport($campaign, CarbonImmutable::now()->subDays(7), CarbonImmutable::now()->subDay(), 120, 4);
}

function q3Organizer(VenueIsolationScenario $s, User $owner): Organizer
{
    return Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
}

// ------------------------------------------------------------------ canali

it('dichiara archivio e push solo per chi ha un account, e lascia l email sola a un indirizzo', function (): void {
    $campaign = q3Campaign($this->s);
    $notifications = [
        new SponsorshipOrderDecided(q3Order($this->s->venueA, $this->s->ownerA)),
        q3Report($campaign),
        new VenueApplicationApproved($this->s->venueA, ApplicationKind::Venue),
        new VenueApplicationRejected(VenueApplication::factory()->create()),
        new VenueMonthlyReport($this->s->venueA, 'ottobre 2026', [], []),
        new VenueAccessGranted($this->s->venueA, VenueRole::Editor, false),
        new OrganizerAccessGranted(q3Organizer($this->s, $this->s->ownerA), false),
        new VenueModerated($this->s->venueA, VenueStatus::Approved),
    ];

    foreach ($notifications as $notification) {
        expect($notification->via($this->s->ownerA))->toBe(['mail', 'database', AppPushChannel::class])
            ->and($notification->via((new AnonymousNotifiable)->route('mail', 'qualcuno@prova.test')))->toBe(['mail']);
    }
});

it('manda l email una volta sola e scrive una riga con il bersaglio del locale moderato', function (): void {
    $venue = Venue::factory()->pending()->create(['city_id' => $this->s->city->id, 'name' => 'Cortile Nuovo']);
    $owner = User::factory()->create();
    $venue->membersIncludingInvited()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);

    app(ModerateVenueAction::class)->approve($venue, $this->s->moderator);

    $row = $owner->notifications()->sole();
    expect(q3Mails($owner->email))->toBe(1)
        ->and($row->type)->toBe('venue_moderated')
        ->and($row->data['title'])->toBe(__('notifications.venue_approved.subject', ['venue' => 'Cortile Nuovo', 'product' => config('app.name')]))
        ->and($row->data['management'])->toBe(['subject_type' => 'venue', 'subject_id' => $venue->id, 'area' => 'home', 'id' => null]);
});

it('non porta dentro un locale rifiutato o sospeso, ma scrive comunque l avviso col motivo', function (): void {
    app(ModerateVenueAction::class)->suspend($this->s->venueA, 'in verifica');

    $row = $this->s->ownerA->notifications()->where('type', 'venue_moderated')->sole();
    expect(q3Mails($this->s->ownerA->email))->toBe(1)
        ->and($row->data)->not->toHaveKey('management')
        ->and($row->data['body'])->toContain('in verifica');
});

it('porta chi ha ordinato alla pagina delle sponsorizzazioni con l ordine, e non chi non entra nel locale', function (): void {
    $order = q3Order($this->s->venueA, $this->s->ownerA, SponsorshipOrderStatus::Rejected);

    $this->s->ownerA->notifyNow(new SponsorshipOrderDecided($order));

    $row = $this->s->ownerA->notifications()->sole();
    expect(q3Mails($this->s->ownerA->email))->toBe(1)
        ->and($row->type)->toBe('sponsorship_order_decided')
        ->and($row->data['management'])->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'sponsorships', 'id' => $order->id])
        /* Il motivo della redazione sì, le note interne sotto no: come nell'email. */
        ->and($row->data['body'])->toContain('Fuori dal periodo')->not->toContain('nota interna');

    expect((new SponsorshipOrderDecided($order))->toArray($this->s->ownerB))->not->toHaveKey('management');
});

it('porta i gestori alle statistiche della campagna del riepilogo, e al committente senza account manda solo l email', function (): void {
    $campaign = q3Campaign($this->s);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    foreach ([$this->s->ownerA, $this->s->editorA] as $manager) {
        $row = $manager->notifications()->sole();
        expect(q3Mails($manager->email))->toBe(1)
            ->and($row->type)->toBe('sponsorship_weekly_report')
            ->and($row->data['event_id'])->toBe($this->s->publishedEventA->id)
            ->and($row->data['management'])->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'sponsorship_stats', 'id' => $campaign->id]);
    }
    expect(q3Mails('committente@prova.test'))->toBe(1)
        ->and($this->s->ownerB->notifications()->count())->toBe(0);
});

it('porta alla pagina del nuovo locale chi ha la richiesta accolta, e a «Le mie richieste» chi l ha rifiutata', function (): void {
    $this->s->ownerA->notifyNow(new VenueApplicationApproved($this->s->venueA, ApplicationKind::Claim));
    $application = VenueApplication::factory()->create(['venue_name' => 'Bar Sotto', 'user_id' => $this->s->plainUser->id, 'status' => ApplicationStatus::Rejected]);
    $this->s->plainUser->notifyNow(new VenueApplicationRejected($application, 'Manca l indirizzo'));

    expect($this->s->ownerA->notifications()->sole()->data['management'])
        ->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'home', 'id' => null]);

    $rejected = $this->s->plainUser->notifications()->sole();
    expect($rejected->type)->toBe('venue_application_rejected')
        ->and($rejected->data['url'])->toBe(route('account.applications'))
        ->and($rejected->data['body'])->toContain('Manca l indirizzo')
        ->and($rejected->data['management'])->toBe(['subject_type' => 'account', 'subject_id' => $this->s->plainUser->id, 'area' => 'applications', 'id' => null])
        ->and(q3Mails($this->s->plainUser->email))->toBe(1);
});

it('porta l invitato a «I tuoi inviti» finché non accetta, e alla pagina del locale quando ci entra', function (): void {
    $invited = User::factory()->create();
    $invited->venuesIncludingInvited()->attach($this->s->venueB, ['role' => 'editor', 'invited_at' => now(), 'accepted_at' => null]);
    $accept = 'https://example.test/inviti-locali/1/2/3?signature=abc';

    $invited->notifyNow(new VenueAccessGranted($this->s->venueB, VenueRole::Editor, needsPassword: false, acceptUrl: $accept));
    $this->s->editorA->notifyNow(new VenueAccessGranted($this->s->venueA, VenueRole::Editor, needsPassword: false));

    $pending = $invited->notifications()->sole();
    expect($pending->type)->toBe('venue_access_granted')
        ->and($pending->data['url'])->toBe($accept)
        ->and($pending->data['management'])->toBe(['subject_type' => 'account', 'subject_id' => $invited->id, 'area' => 'invitations', 'id' => null])
        ->and($this->s->editorA->notifications()->sole()->data['management'])
        ->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'home', 'id' => null]);
});

it('non scrive nell archivio il collegamento per la password, e quello dell email resta valido', function (): void {
    $invited = User::factory()->create();

    $invited->notifyNow(new VenueAccessGranted($this->s->venueA, VenueRole::Editor, needsPassword: true, pending: true));

    $sent = collect(app('mailer')->getSymfonyTransport()->messages())->sole();
    preg_match('~token=([A-Za-z0-9]+)~', (string) $sent->getOriginalMessage()->getHtmlBody(), $match);
    $token = $match[1] ?? null;

    $row = $invited->notifications()->sole();
    expect($token)->toBeString()
        ->and(Password::broker()->tokenExists($invited, $token))->toBeTrue()
        ->and($row->data['url'])->toBe(route('login'))
        ->and(json_encode($row->data))->not->toContain($token);
});

it('porta alla pagina dell organizzazione chi ne riceve le chiavi', function (): void {
    $owner = User::factory()->create();
    $organizer = q3Organizer($this->s, $owner);

    $owner->notifyNow(new OrganizerAccessGranted($organizer, false));

    $row = $owner->notifications()->sole();
    expect($row->type)->toBe('organizer_access_granted')
        ->and($row->data['management'])->toBe(['subject_type' => 'organizer', 'subject_id' => $organizer->id, 'area' => 'home', 'id' => null])
        ->and(q3Mails($owner->email))->toBe(1);
});

it('porta il titolare alle statistiche del locale dal rapporto mensile', function (): void {
    $this->s->ownerA->notifyNow(new VenueMonthlyReport($this->s->venueA, 'ottobre 2026', ['views' => 1200, 'profile_views' => 80], []));

    $row = $this->s->ownerA->notifications()->sole();
    expect($row->type)->toBe('venue_monthly_report')
        ->and($row->data['body'])->toContain('1.200')
        ->and($row->data['management'])->toBe(['subject_type' => 'venue', 'subject_id' => $this->s->venueA->id, 'area' => 'statistics', 'id' => null]);
});

it('rilegge l accesso dal database anche se la memoria della richiesta è vecchia', function (): void {
    $user = User::factory()->create();
    expect($user->isActiveMemberOfVenue($this->s->venueA->id))->toBeFalse();
    $user->venuesIncludingInvited()->attach($this->s->venueA, ['role' => 'editor', 'accepted_at' => now()]);

    expect(app(ManagementNoticeTarget::class)->subject($user, $this->s->venueA, 'home'))->not->toBeNull()
        ->and(app(ManagementNoticeTarget::class)->subject($this->s->plainUser, $this->s->venueA, 'home'))->toBeNull()
        ->and(app(ManagementNoticeTarget::class)->subject($this->s->ownerA, null, 'home'))->toBeNull();
});

// ------------------------------------------------------------------ caselle

it('mostra le righe del soggetto nell area «Notifiche» e quelle dell account solo nella casella dell account', function (): void {
    $this->s->ownerA->notifyNow(new SponsorshipOrderDecided(q3Order($this->s->venueA, $this->s->ownerA)));
    $this->s->ownerA->notifyNow(new VenueMonthlyReport($this->s->venueA, 'ottobre 2026', ['views' => 10], []));
    $this->s->ownerA->notifyNow(new VenueApplicationRejected(VenueApplication::factory()->create()));
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson('/api/v1/management/venues/'.$this->s->venueA->id.'/notifications')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.unread_count', 2);

    $all = $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(3, 'data')->json('data');
    expect(collect($all)->pluck('data.management.area')->sort()->values()->all())->toBe(['applications', 'sponsorships', 'statistics']);

    $this->getJson('/api/v1/management/venues/'.$this->s->venueB->id.'/notifications')->assertNotFound();
});

// ------------------------------------------------------------------ push

it('accoda la push con gli stessi campi della casella quando la persona riceve le push', function (): void {
    config(['api.features.push' => true, 'firebase.projects.app.credentials' => 'credenziali-di-prova']);
    $this->s->ownerA->forceFill(['notification_preferences' => ['delivery' => 'push']])->save();
    Notification::fake();
    $order = q3Order($this->s->venueA, $this->s->ownerA);

    app(AppPushChannel::class)->send($this->s->ownerA, new SponsorshipOrderDecided($order));

    Notification::assertSentTo($this->s->ownerA, AppPush::class, function (AppPush $push) use ($order): bool {
        $data = $push->toFcm($this->s->ownerA)->toArray()['data'];

        return $push->via($this->s->ownerA) === [FcmChannel::class]
            && $push->delay === null
            && $data['type'] === 'sponsorship_order_decided'
            && $data['user_id'] === (string) $this->s->ownerA->id
            && $data['management_subject_type'] === 'venue'
            && $data['management_subject_id'] === (string) $this->s->venueA->id
            && $data['management_area'] === 'sponsorships'
            && $data['management_id'] === (string) $order->id
            && $data['title'] !== '';
    });
});

it('non accoda la push a chi ha scelto solo l email o non ha canali push, e la sposta fuori dalle ore di silenzio', function (): void {
    Notification::fake();
    $notice = new VenueModerated($this->s->venueA, VenueStatus::Approved);

    /* Push spente sul server: si resta all'email e all'archivio. */
    app(AppPushChannel::class)->send($this->s->ownerA, $notice);
    Notification::assertNotSentTo($this->s->ownerA, AppPush::class);

    config(['api.features.push' => true, 'firebase.projects.app.credentials' => 'credenziali-di-prova', 'notifications.quiet_hours.enabled' => true]);
    $this->s->ownerA->forceFill(['notification_preferences' => ['delivery' => 'mail']])->save();
    app(AppPushChannel::class)->send($this->s->ownerA, $notice);
    Notification::assertNotSentTo($this->s->ownerA, AppPush::class);

    $now = CarbonImmutable::now($this->s->ownerA->timezone ?: config('app.timezone'));
    $this->s->ownerA->forceFill([
        'notification_preferences' => ['delivery' => 'both'],
        'quiet_hours' => ['from' => $now->subHour()->format('H:i'), 'to' => $now->addHours(2)->format('H:i')],
    ])->save();
    app(AppPushChannel::class)->send($this->s->ownerA, $notice);
    Notification::assertSentTo($this->s->ownerA, AppPush::class, fn (AppPush $push): bool => $push->delay !== null);

    /* Un destinatario senza account non ha push. */
    app(AppPushChannel::class)->send((new AnonymousNotifiable)->route('mail', 'x@prova.test'), $notice);
    Notification::assertSentTimes(AppPush::class, 1);
});

it('alla consegna rispetta l interruttore del rapporto mensile e un account non più raggiungibile', function (): void {
    $payload = ['type' => NotificationType::VenueMonthlyReport->value, 'title' => 'T', 'body' => 'B', 'url' => 'https://example.test', 'event_id' => null, 'management' => null];
    $push = new AppPush($payload);

    expect($push->shouldSend($this->s->ownerA, 'fcm'))->toBeTrue();

    $this->s->ownerA->forceFill(['notification_preferences' => ['venue_report' => false]])->save();
    expect($push->shouldSend($this->s->ownerA, 'fcm'))->toBeFalse()
        ->and((new AppPush([...$payload, 'type' => 'venue_moderated']))->shouldSend($this->s->ownerA, 'fcm'))->toBeTrue();

    $this->s->ownerB->delete();
    expect((new AppPush([...$payload, 'type' => 'venue_moderated']))->shouldSend($this->s->ownerB, 'fcm'))->toBeFalse();
});

it('non lascia che un guasto della push faccia credere fallita l email', function (): void {
    config(['api.features.push' => true, 'firebase.projects.app.credentials' => 'credenziali-di-prova']);
    $this->s->ownerA->forceFill(['notification_preferences' => ['delivery' => 'push']])->save();
    $broken = new class extends BaseNotification
    {
        /** @return array<string, mixed> */
        public function appPush(User $user): array
        {
            throw new RuntimeException('guasto');
        }
    };

    app(AppPushChannel::class)->send($this->s->ownerA, $broken);

    expect(true)->toBeTrue();
});
