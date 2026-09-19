<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarpoolCaseMessage extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<CarpoolCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CarpoolCase::class, 'carpool_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'internal' => 'boolean',
            'read_at' => 'immutable_datetime',
        ];
    }
}
