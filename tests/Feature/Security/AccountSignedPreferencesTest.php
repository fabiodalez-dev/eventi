<?php

declare(strict_types=1);

use App\DTOs\NotificationPreferences;
use App\Models\User;
use App\Support\Features;
use App\Support\Notifications\PreferenceLinks;
use Carbon\Carbon;
use Laravel\Pennant\Feature;

/**
 * Il collegamento firmato delle preferenze arriva in fondo a ogni email e può
 * essere inoltrato: senza una sessione sullo stesso account permette solo di
 * ridurre ciò che si riceve. Accendere, togliere le ore di silenzio e dare il
 * consenso al marketing vogliono l'interessato.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
    Feature::for(Features::globalScope())->activate(Features::NEWSLETTER);

    $this->io = User::factory()->create([
        'marketing_opt_in_at' => null,
        'notification_preferences' => NotificationPreferences::defaults()->with(['reminders' => false, 'daily_digest' => false, 'tonight' => false])->toArray(),
        'quiet_hours' => ['from' => '22:00', 'to' => '07:00'],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('non registra il consenso marketing né riaccende nulla dal solo collegamento firmato', function (): void {
    $url = PreferenceLinks::preferences($this->io);

    $this->patch($url, [
        'marketing_opt_in' => '1',
        'reminders' => '1',
        'daily_digest' => '1',
        'tonight' => '1',
        'tonight_days' => [1, 2, 3, 4, 5, 6, 7],
        'quiet_off' => '1',
        'delivery' => 'both',
    ])->assertRedirect($url);

    $user = $this->io->fresh();
    $preferences = NotificationPreferences::fromUser($user);

    expect($user->marketing_opt_in_at)->toBeNull()
        ->and($preferences->reminders)->toBeFalse()
        ->and($preferences->dailyDigest)->toBeFalse()
        ->and($preferences->tonight)->toBeFalse()
        ->and($preferences->delivery->value)->toBe('auto')
        ->and($user->quiet_hours)->toBe(['from' => '22:00', 'to' => '07:00']);
});

it('non accorcia le ore di silenzio dal solo collegamento ma le lascia allargare', function (): void {
    $url = PreferenceLinks::preferences($this->io);

    $this->patch($url, ['quiet_from' => '03:00', 'quiet_to' => '03:01'])->assertRedirect();
    expect($this->io->fresh()->quiet_hours)->toBe(['from' => '22:00', 'to' => '07:00']);

    $this->patch($url, ['quiet_from' => '21:00', 'quiet_to' => '08:00'])->assertRedirect();
    expect($this->io->fresh()->quiet_hours)->toBe(['from' => '21:00', 'to' => '08:00']);
});

it('lascia spegnere e revocare il consenso dal solo collegamento', function (): void {
    $this->io->forceFill([
        'marketing_opt_in_at' => now()->subMonth(),
        'notification_preferences' => NotificationPreferences::defaults()->with(['reminders' => true])->toArray(),
    ])->save();

    $this->patch(PreferenceLinks::preferences($this->io), [])->assertRedirect();

    $user = $this->io->fresh();
    expect($user->marketing_opt_in_at)->toBeNull()
        ->and(NotificationPreferences::fromUser($user)->reminders)->toBeFalse()
        ->and(NotificationPreferences::fromUser($user)->soldOut)->toBeFalse();
});

it('tratta chi ha la sessione di un altro account come chi ha solo il collegamento', function (): void {
    $altro = User::factory()->create();

    $this->actingAs($altro)->patch(PreferenceLinks::preferences($this->io), ['marketing_opt_in' => '1', 'reminders' => '1'])->assertRedirect();

    expect($this->io->fresh()->marketing_opt_in_at)->toBeNull()
        ->and(NotificationPreferences::fromUser($this->io->fresh())->reminders)->toBeFalse();
});

it('con la sessione sullo stesso account accende e registra il consenso', function (): void {
    $this->actingAs($this->io)->patch(PreferenceLinks::preferences($this->io), [
        'marketing_opt_in' => '1',
        'reminders' => '1',
        'quiet_off' => '1',
    ])->assertRedirect();

    $user = $this->io->fresh();
    expect($user->marketing_opt_in_at)->not->toBeNull()
        ->and(NotificationPreferences::fromUser($user)->reminders)->toBeTrue()
        ->and($user->quiet_hours)->toBe([]);
});

it('non rinnova la scadenza di un collegamento aperto senza sessione', function (): void {
    $url = PreferenceLinks::preferences($this->io);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Carbon::setTestNow(Carbon::now()->addDays(10));

    $html = $this->get($url)->assertOk()->assertDontSee(__('account.profile.marketing'))->getContent();

    preg_match('/action="([^"]*notifiche\/preferenze[^"]*)"/', (string) $html, $match);
    parse_str((string) parse_url(html_entity_decode($match[1] ?? ''), PHP_URL_QUERY), $action);

    expect($action['expires'] ?? null)->toBe($query['expires']);
});
