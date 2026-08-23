<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'platform',
        'push_token',
        'endpoint',
        'keys',
        'app_version',
        'locale',
        'last_seen_at',
        'revoked_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Device>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @param  Builder<Device>  $query
     */
    public function scopeRevoked(Builder $query): void
    {
        $query->whereNotNull('revoked_at');
    }

    /**
     * @param  Builder<Device>  $query
     */
    public function scopeOfPlatform(Builder $query, DevicePlatform $platform): void
    {
        $query->where('platform', $platform);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'keys' => 'array',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
