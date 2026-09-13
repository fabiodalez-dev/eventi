<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Mail\PublicContact;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

it('shows weather icons family details and submits a member contact form', function (string $device): void {
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-15 18:00');
    $venue = $date->event->venue;
    $venue->update(['content_details' => ['age_groups' => ['3-5'], 'stroller' => 'yes'], 'contact_mode' => ContactMode::Members, 'contact_email' => 'contact@example.test']);
    Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-09-15'], 'weather_code' => [0], 'temperature_2m_min' => [15], 'temperature_2m_max' => [25], 'precipitation_probability_max' => [5], 'wind_speed_10m_max' => [10]]])]);
    $page = visit('/eventi/'.$date->event->slug.'/'.$date->url_number)->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')->assertVisible('[data-event-weather]')->assertSee('Sereno')->assertSee('3–5 anni');
    expect($page->script('document.querySelector("[data-weather-icon] circle") !== null'))->toBeTrue();
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'family-weather-'.$device);
    Mail::fake();
    $this->actingAs(User::factory()->create());
    $page = visit('/locali/'.$venue->slug)->on()->{$device}()->click('[data-consent-banner] button[value="reject_all"]');
    $page->fill('message', 'Vorrei sapere come accedere con un passeggino.')
        ->click('#contatta button[type="submit"]')->assertSee(__('contact.sent'));
    Mail::assertSent(PublicContact::class);
})->with(['desktop', 'mobile']);

it('keeps the whole weather section hidden when the provider fails', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-15 18:00');
    Http::fake(['*' => Http::response([], 503)]);
    $page = visit('/eventi/'.$date->event->slug.'/'.$date->url_number)->on()->mobile()
        ->click('[data-consent-banner] button[value="reject_all"]')->assertNotVisible('[data-event-weather]');
    $page->assertDontSee(__('weather.failure'))->assertNoJavascriptErrors();
});
