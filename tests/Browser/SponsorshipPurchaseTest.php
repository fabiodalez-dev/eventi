<?php

declare(strict_types=1);

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Filament\Venue\Pages\Sponsorships;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

/*
 * La pagina delle sponsorizzazioni di un locale nel browser: l'elenco degli
 * ordini con lo stato, l'annullamento di un ordine non pagato, e la scelta
 * di un evento. Il pagamento vero sta nel test di funzionalita', dove Stripe
 * e' finto e il webhook e' firmato davvero.
 */
it('shows orders, cancels an unpaid one and keeps the page usable on narrow screens', function (int $width): void {
    $scenario = VenueIsolationScenario::make();
    SponsorshipPrice::create(['placement' => 'list_top', 'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'is_active' => true]);
    $order = SponsorshipOrder::create(['venue_id' => $scenario->venueA->id, 'user_id' => $scenario->ownerA->id, 'placement' => 'list_top',
        'mode' => PromotionMode::Selected, 'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'status' => SponsorshipOrderStatus::PendingPayment]);
    $foreign = SponsorshipOrder::create(['venue_id' => $scenario->venueB->id, 'user_id' => $scenario->ownerB->id, 'placement' => 'home_card',
        'mode' => PromotionMode::Automatic, 'months' => 1, 'amount_cents' => 9000, 'currency' => 'EUR', 'status' => SponsorshipOrderStatus::Paid]);

    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);

    $page = visit(Sponsorships::getUrl(panel: 'venue', tenant: $scenario->venueA))->resize($width, 900)
        ->assertSee(__('promotions.orders.yours'))
        ->assertSee(__('promotions.orders.status.pending_payment'))
        ->assertPresent('[data-sponsorship-order="'.$order->id.'"]')
        ->assertMissing('[data-sponsorship-order="'.$foreign->id.'"]')
        ->assertDontSee(__('promotions.orders.status.paid'));
    $page->screenshot(filename: 'sponsorship-orders-'.$width);
    // `wire:confirm` chiama `confirm()`: in Playwright i dialoghi nativi vengono chiusi con «annulla» se nessuno risponde.
    $page->script('window.confirm = () => true');
    $page->page()->locator('[data-sponsorship-order="'.$order->id.'"] button:has-text("'.__('promotions.orders.cancel').'")')->click(['timeout' => 5000]);
    $page->assertSee(__('promotions.orders.status.cancelled'))->assertNoJavascriptErrors();
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled);
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
})->with([390, 1440]);
