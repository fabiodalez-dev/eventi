<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SocialFormat;
use App\Models\City;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Queries\EventOccurrenceQuery;
use App\Services\Social\SocialCatalog;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudio;
use Illuminate\Console\Command;

class SocialDaily extends Command
{
    protected $signature = 'social:daily';

    protected $description = 'Prepare and queue the configured daily social carousel';

    public function handle(SocialCatalog $catalog, SocialStudio $studio, SocialPublisher $publisher): int
    {
        foreach (SocialConnection::where('automatic', true)->whereNotNull('verified_at')->get() as $connection) {
            $city = City::findOrFail($connection->city_id);
            $clock = EventOccurrenceQuery::for($city);
            if ($clock->now()->format('H:i') < $connection->publish_time) {
                continue;
            }
            $date = $clock->currentBusinessDate();
            if (SocialPublication::where('social_connection_id', $connection->id)->where('dedupe_key', 'like', $connection->id.':%:'.$date.':%')->exists()) {
                continue;
            }
            $dates = $catalog->events($city, $date);
            if ($dates->isEmpty()) {
                continue;
            }
            $batch = $studio->generate($city, $date, $dates, SocialFormat::Portrait, $connection->graphic_options ?? [], null);
            $publisher->enqueue($batch, true);
        }

        return self::SUCCESS;
    }
}
