<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\Social;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Services\Social\SocialStudioDesk;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class SocialToday extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.social.today';

    public static function canView(): bool
    {
        return Social::canAccess() && City::exists();
    }

    /** @return Collection<int, EventOccurrence> */
    public function dates(): Collection
    {
        $city = City::where('is_active', true)->first() ?? City::firstOrFail();

        return app(SocialStudioDesk::class)->today($city);
    }

    public function downloadToday(): void
    {
        abort_unless(static::canView(), 403);
        $city = City::where('is_active', true)->first() ?? City::firstOrFail();
        try {
            $batch = app(SocialStudioDesk::class)->prepareToday($city, auth()->user());
            $this->redirect(route('social.zip', $batch));
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
