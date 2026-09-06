<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Filament\Admin\Resources\Cities\CityResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Pages\PageResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Resources\Tags\TagResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Support\ExternalLinksField;
use App\Filament\Support\FactsField;
use App\Filament\Support\TicketTiersField;
use App\Filament\Support\TransitField;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

it('keeps event status outside tabs and defaults new events to draft', function (): void {
    $components = EventResource::form(Schema::make())->getComponents();
    $status = array_values($components)[0];
    expect($status)->toBeInstanceOf(ToggleButtons::class)
        ->and($status->getName())->toBe('status')
        ->and($status->getDefaultState())->toBe('draft');
});

it('keeps section-based resource forms in a single outer column', function (string $resource): void {
    $schema = $resource::form(Schema::make());
    expect($schema->getColumns('lg'))->toBe(1);
})->with([
    CategoryResource::class,
    CityResource::class,
    EventResource::class,
    VenueResource::class,
    UserResource::class,
    TagResource::class,
    ReportResource::class,
    ImportSourceResource::class,
    PageResource::class,
    SponsorshipResource::class,
    VenueApplicationResource::class,
    App\Filament\Venue\Resources\Events\EventResource::class,
]);

it('gives shared repeaters full width without forcing multiple columns on mobile', function (): void {
    foreach ([
        TransitField::make('admin'),
        TicketTiersField::make('admin'),
        ExternalLinksField::make('admin'),
        FactsField::make('admin', 'facts'),
    ] as $field) {
        expect($field->getColumnSpan('default'))->toBe('full');
        expect($field->getColumns('default') ?? 1)->toBe(1);
        expect($field->getColumns('lg'))->toBeLessThanOrEqual(2);
    }
});
