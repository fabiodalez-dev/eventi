<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ScheduledNotifications\Pages;

use App\Filament\Admin\Resources\ScheduledNotifications\ScheduledNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListScheduledNotifications extends ListRecords
{
    protected static string $resource = ScheduledNotificationResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
