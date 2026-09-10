<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\EventSubmission;

beforeEach(function (): void {
    testCity();
});

it('usa il tema di sistema e conserva la scelta del visitatore', function (string $device): void {
    $page = visit('/proponi-evento')->inLightMode()->on()->{$device}()
        ->assertAttribute('html[data-theme]', 'data-theme', 'light')
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->click('[data-appearance-toggle]')
        ->assertAttribute('html[data-theme]', 'data-theme', 'dark');

    expect($page->script('document.cookie'))->toContain('incitta_appearance=dark');

    // Elimina il fallback locale: la visita successiva deve usare il cookie.
    $page->script('localStorage.removeItem("incitta:appearance:guest")');
    $page->navigate('/proponi-evento')
        ->assertAttribute('html[data-theme]', 'data-theme', 'dark');
})->with(['desktop', 'mobile']);

it('mostra gli errori del server e permette di correggere una proposta', function (): void {
    $page = visit('/proponi-evento')
        ->click('[data-consent-banner] button[value="reject_all"]');

    // Bypassa solo la validazione HTML per esercitare gli errori Laravel reali.
    $page->script('document.querySelector("form[action$=\'/proponi-evento\']").noValidate = true');
    $page->fill('contact_email', 'email-non-valida')
        ->click('form[action$="/proponi-evento"] button[type="submit"]')
        ->assertVisible('[role="alert"]:not([hidden])');

    expect(EventSubmission::query()->count())->toBe(0);

    $page->fill('title', 'Concerto proposto dal browser')
        ->fill('contact_email', 'browser@example.test')
        ->fill('venue_hint', 'Circolo di prova')
        ->click('form[action$="/proponi-evento"] button[type="submit"]')
        ->assertSee(__('forms.submission.received'));

    $submission = EventSubmission::query()->sole();
    expect($submission->title)->toBe('Concerto proposto dal browser')
        ->and($submission->status)->toBe(SubmissionStatus::Pending);
});
