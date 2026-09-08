<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordSponsorshipMetric
{
    public function record(Sponsorship $campaign, string $metric, Request $request, string $channel): void
    {
        $input = $request->validate([
            'click_id' => 'nullable|uuid',
            'placement' => 'nullable|in:banner,home_hero,card',
            'page' => 'nullable|in:home,feed,event,venues,profile,search,other',
        ]);
        DB::transaction(function () use ($campaign, $metric, $input, $channel): void {
            // Serialize counter/log writes per campaign, including duplicate delivery.
            $locked = Sponsorship::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($metric === 'clicks') {
                $key = hash('sha256', $channel.'|'.$campaign->id.'|'.($input['click_id'] ?? Str::uuid()));
                if (SponsorshipClick::where('request_key', $key)->exists()) {
                    return;
                }
                SponsorshipClick::create([
                    'sponsorship_id' => $campaign->id, 'request_key' => $key,
                    'channel' => $channel, 'placement' => $input['placement'] ?? $campaign->placement->value,
                    'page' => $input['page'] ?? 'other', 'clicked_at' => now('UTC'),
                ]);
            }
            $locked->increment($metric);
            SponsorshipDailyStat::registra($campaign->id, $metric, CarbonImmutable::now($campaign->city->timezone));
        });
    }
}
