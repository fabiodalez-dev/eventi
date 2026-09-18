<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarpoolAudit extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'metadata' => 'array',
            'context_expires_at' => 'immutable_datetime',
        ];
    }
}
