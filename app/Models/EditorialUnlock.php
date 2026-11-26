<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lo sblocco della redazione su un token dell'app: quando la persona ha
 * scritto la password l'ultima volta (`unlocked_at`, valido 12 ore) e fino a
 * quando valgono le azioni pericolose senza chiederla di nuovo
 * (`confirmed_until`, 15 minuti). Lo scrive solo `POST editorial/unlock`;
 * la riga sparisce con il token.
 *
 * @property int $id
 * @property int $token_id
 * @property int $user_id
 * @property CarbonImmutable $unlocked_at
 * @property CarbonImmutable $confirmed_until
 */
class EditorialUnlock extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'token_id',
        'user_id',
        'unlocked_at',
        'confirmed_until',
    ];

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unlocked_at' => 'immutable_datetime',
            'confirmed_until' => 'immutable_datetime',
        ];
    }
}
