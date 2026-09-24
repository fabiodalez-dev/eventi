<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class RememberedLocation
{
    public const COOKIE = 'incitta_location';

    /** @return array{lat: float, lng: float, expires_at: int, saved_at: int}|null */
    public function resolve(Request $request, ?User $user = null): ?array
    {
        $cookie = json_decode((string) $request->cookie(self::COOKIE, ''), true);
        $user ??= $request->user();
        $stored = $user?->remembered_location;
        $positions = array_filter([$cookie, $stored], fn ($value) => $this->valid($value));
        usort($positions, fn ($a, $b) => $b['saved_at'] <=> $a['saved_at']);

        return $positions[0] ?? null;
    }

    public function valid(mixed $value): bool
    {
        return is_array($value) && isset($value['lat'], $value['lng'], $value['expires_at'], $value['saved_at'])
            && is_numeric($value['lat']) && is_numeric($value['lng'])
            && abs((float) $value['lat']) <= 90 && abs((float) $value['lng']) <= 180
            && is_int($value['saved_at']) && $value['saved_at'] <= now()->timestamp
            && is_int($value['expires_at']) && $value['expires_at'] > now()->timestamp;
    }

    /** @return array{lat: float, lng: float, expires_at: int, saved_at: int} */
    public function save(float $lat, float $lng, ?User $user, ?int $observedAt = null): array
    {
        $observed = $observedAt === null ? now() : CarbonImmutable::createFromTimestamp($observedAt);
        $position = ['lat' => round($lat, 2), 'lng' => round($lng, 2),
            'saved_at' => $observed->timestamp, 'expires_at' => $observed->addMonthsNoOverflow(6)->timestamp];
        if ($this->valid($user?->remembered_location) && $user->remembered_location['saved_at'] > $position['saved_at']) {
            return $user->remembered_location;
        }
        // Le due colonne in chiaro tengono lo stesso valore arrotondato: servono a
        // filtrare per distanza in SQL, cosa che la colonna cifrata non permette.
        $user?->forceFill(['remembered_location' => $position, 'location_expires_at' => CarbonImmutable::createFromTimestamp($position['expires_at']),
            'location_lat' => $position['lat'], 'location_lng' => $position['lng']])->save();

        return $position;
    }

    public function forget(?User $user): void
    {
        $user?->forceFill(['remembered_location' => null, 'location_expires_at' => null, 'location_lat' => null, 'location_lng' => null])->save();
    }
}
