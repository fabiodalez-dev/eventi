<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WhatsappChallengeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class WhatsappChallenge extends Model
{
    use Prunable;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(30));
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'attempts' => 'integer', 'status' => WhatsappChallengeStatus::class];
    }
}
