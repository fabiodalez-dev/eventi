<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PosterResource;
use App\Http\Resources\V1\PriceResource;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Settings\SponsorshipBannerSettings;
use App\Support\DateFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;

final class SponsorshipBannerController extends Controller
{
    use InteractsWithApi;

    public function __invoke(Request $request, SponsorshipSelector $selector, SponsorshipBannerSettings $settings): JsonResponse
    {
        $input = $request->validate(['platform' => 'required|in:web,android', 'exclude_event' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255', 'venue' => 'nullable|string|max:255', 'tag' => 'nullable|string|max:255']);
        $enabled = $input['platform'] === 'web' ? $settings->web_enabled : $settings->android_enabled;
        $city = $this->city();
        $user = $request->routeIs('api.*') ? $this->currentUser($request) : $request->user();
        $campaign = $enabled ? $selector->banner($city, $input['exclude_event'] ?? null, $user instanceof User ? $user : null, $input) : null;
        $data = null;
        if ($campaign !== null && $campaign->event !== null) {
            $event = $campaign->event;
            $today = now($city->timezone)->startOfDay()->utc();
            $date = $event->occurrences()->whereIn('status', [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut])
                ->where('effective_ends_at', '>', $today)->orderBy('starts_at')->first();
            if ($date !== null) {
                $format = DateFormatter::for($city);
                $price = PriceResource::toArray($event, $date);
                $money = fn ($value): string => (string) Number::currency((float) $value, in: $price['currency'] ?? 'EUR', locale: app()->getLocale());
                $priceLabel = match ($price['type']) {
                    PriceType::Free->value => __('events.price.free'),
                    PriceType::Donation->value => __('events.price.donation'),
                    PriceType::Membership->value => PriceType::Membership->label(),
                    default => $price['min'] !== null ? $money($price['min']).($price['max'] !== null && $price['max'] > $price['min'] ? ' – '.$money($price['max']) : '') : ($price['max'] !== null ? $money($price['max']) : ''),
                };
                // Short lease: never persist an ad offline or beyond campaign expiry/midnight.
                $expiry = now('UTC')->addMinute()->min(now($city->timezone)->addDay()->startOfDay())->min($campaign->ends_at);
                if ($campaign->grant !== null) {
                    $expiry = $expiry->min($campaign->grant->ends_at);
                }
                $data = [
                    'id' => (int) $campaign->id,
                    'event_slug' => $event->slug,
                    'title' => $event->title,
                    'when' => $date->is_all_day ? $format->day($date->business_date).' · '.__('events.badge.all_day') : $format->dayAndTime($date->business_date, $date->starts_at),
                    'place' => $date->effectiveVenue()->name ?? ($event->custom_location['name'] ?? ''),
                    'category' => $event->category->name ?? '',
                    'price' => $priceLabel,
                    'advertiser' => $campaign->advertiser_name,
                    'image' => PosterResource::toArray($event)['thumb'] ?? null,
                    'url' => route('city.events.show', ['city' => $city->slug, 'slug' => $event->slug]),
                    'expires_at' => $expiry->utc()->toIso8601String(),
                    'metric_token' => hash_hmac('sha256', $campaign->id.'|'.$campaign->placement->value.'|'.now('UTC')->format('Y-m-d'), (string) config('app.key')),
                ];
            }
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
