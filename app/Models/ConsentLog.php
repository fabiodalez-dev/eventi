<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use Database\Factories\ConsentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una scelta registrata sul consenso (§16: «log del consenso»).
 *
 * Le righe non si aggiornano mai: cambiare idea scrive una riga nuova. Un
 * registro che sovrascrive la scelta precedente non è un registro — non
 * saprebbe dire da quando valeva il rifiuto che è stato appena revocato.
 */
class ConsentLog extends Model
{
    /** @use HasFactory<ConsentLogFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'consent_id',
        'user_id',
        'action',
        'choices',
        'policy_version',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(ConsentCategory $category): bool
    {
        return ($this->choices[$category->value] ?? false) === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ConsentAction::class,
            'choices' => 'array',
        ];
    }
}
