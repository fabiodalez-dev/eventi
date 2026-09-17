<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le reazioni possibili a un commento.
 *
 * ## Tre, e tutte positive
 *
 * Niente pollice verso. Un voto negativo su un commento è una decisione
 * editoriale a sé — cambia il tono di uno spazio e chi ci scrive — e nessuno
 * l'ha presa. Chi trova offensivo un commento ha la segnalazione, che porta a
 * una persona; il pollice verso porta solo a un numero.
 *
 * ## Perché «Utile» e non una quarta faccina
 *
 * Sotto un evento la domanda ricorrente è pratica: si parcheggia, si entra
 * col passeggino, a che ora si comincia davvero. «Utile» è l'unica reazione
 * che distingue una risposta che serve da una che piace, ed è quella che
 * conviene poter ordinare per prima.
 */
enum EventCommentReactionType: string
{
    case Like = 'like';
    case Love = 'love';
    case Useful = 'useful';

    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍',
            self::Love => '❤️',
            self::Useful => '💡',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Like => __('comments.reactions.like'),
            self::Love => __('comments.reactions.love'),
            self::Useful => __('comments.reactions.useful'),
        };
    }
}
