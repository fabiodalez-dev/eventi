<?php

use App\Providers\AppServiceProvider;
use App\Providers\CarpoolServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OrganizerPanelProvider;
use App\Providers\Filament\VenuePanelProvider;
use App\Providers\FilamentDefaultsServiceProvider;
use App\Providers\MailConfigurationProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\SponsorshipServiceProvider;

return [
    CarpoolServiceProvider::class,
    AppServiceProvider::class,
    MailConfigurationProvider::class,
    OperationsServiceProvider::class,
    SponsorshipServiceProvider::class,
    FilamentDefaultsServiceProvider::class,
    AdminPanelProvider::class,
    VenuePanelProvider::class,
    OrganizerPanelProvider::class,
];
