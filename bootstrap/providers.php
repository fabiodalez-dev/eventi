<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\VenuePanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    VenuePanelProvider::class,
];
