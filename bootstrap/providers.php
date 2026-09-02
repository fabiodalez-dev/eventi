<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\VenuePanelProvider;
use App\Providers\MailConfigurationProvider;
use App\Providers\OperationsServiceProvider;

return [
    AppServiceProvider::class,
    MailConfigurationProvider::class,
    OperationsServiceProvider::class,
    AdminPanelProvider::class,
    VenuePanelProvider::class,
];
