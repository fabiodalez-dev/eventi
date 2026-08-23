<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FollowableType;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ciò che la persona collegata segue, per la richiesta in corso.
 *
 * Stessa ragione di `CurrentSaves`: il pulsante «segui» compare sulla scheda
 * di un locale, su quella di un evento ricorrente e su ogni pagina di
 * categoria, e deve nascere già nello stato giusto. Una interrogazione per
 * richiesta, e solo se qualcuno chiede.
 */
final class CurrentFollows
{
    /** @var array<string, true>|null */
    private ?array $keys = null;

    /**
     * La richiesta a cui appartiene la lettura conservata: stessa ragione di
     * `CurrentSaves`, cioè non far sopravvivere una risposta oltre la domanda
     * a cui rispondeva.
     */
    private ?Request $request = null;

    public function has(FollowableType $type, int $id): bool
    {
        return isset($this->all()[$type->value.':'.$id]);
    }

    /**
     * @return array<string, true>
     */
    public function all(): array
    {
        $request = request();

        if ($this->keys !== null && $this->request === $request) {
            return $this->keys;
        }

        $this->request = $request;
        $user = Auth::user();

        if (! $user instanceof User) {
            return $this->keys = [];
        }

        $keys = [];

        foreach (Follow::query()->where('user_id', $user->getKey())->get(['followable_type', 'followable_id']) as $follow) {
            $keys[$follow->followable_type.':'.(int) $follow->followable_id] = true;
        }

        return $this->keys = $keys;
    }
}
