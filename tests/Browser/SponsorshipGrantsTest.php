<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('switches all payment controls with complimentary authorization without restoring stale payment data', function (int $width): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $page = visit(SponsorshipGrantResource::getUrl('create'))->resize($width, 900)
        ->fill('Metodo di pagamento', 'Bonifico')
        ->fill('Riferimento ricevuta / bonifico', 'RICEVUTA-PROVA')
        ->fill('input[id$=".amount_cents"]', '19.99');
    $page->click('Autorizzazione gratuita')
        ->assertDontSee('Metodo di pagamento')
        ->assertDontSee('Riferimento ricevuta / bonifico')
        ->assertDontSee('Importo del periodo (€)')
        ->assertDontSee('Pagamento ricevuto il')
        ->assertSee('Note / motivazione della concessione gratuita');
    $page->screenshot(fullPage: false, filename: 'grant-complimentary-'.$width);
    $page->click('Autorizzazione gratuita')->assertSee('Metodo di pagamento')->assertSee('Pagamento ricevuto il');
    expect($page->script('document.querySelector("input[id$=\\".payment_reference\\"]").value'))->toBe('');
    expect($page->script('document.querySelector("input[id$=\\".amount_cents\\"]").value'))->toBe('');
    $page->assertNoJavascriptErrors();
})->with([390, 1440]);
