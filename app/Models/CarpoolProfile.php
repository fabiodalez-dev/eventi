<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarpoolProfile extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adult_declared_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'driver_declared_at' => 'immutable_datetime',
            'push_enabled' => 'boolean',
        ];
    }
}
