<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;

/**
 * Che cosa si può seguire.
 *
 * I primi tre casi sono i «segui» di §15.3: alimentano il feed personalizzato
 * e i digest, **non** i promemoria. Il quarto è un'altra cosa e sta qui solo
 * perché il magazzino è lo stesso: seguire un *evento* ricorrente significa
 * «salvami ogni nuova data che nasce», e produce quindi salvataggi — cioè
 * promemoria puntuali. Le due famiglie non vanno mai mescolate in lettura:
 * `feedSources()` è l'elenco di ciò che il feed guarda.
 */
enum FollowableType: string
{
    case Venue = 'venue';
    case Organizer = 'organizer';
    case Tag = 'tag';
    case Category = 'category';
    case Event = 'event';

    public function label(): string
    {
        return __('enums.followable_type.'.$this->value);
    }

    /**
     * Il modello dietro l'alias. L'alias è ciò che sta nel database (D12 vale
     * anche qui: nessun nome di classe PHP fra i dati), e questa è l'unica
     * traduzione da alias a classe di tutto il progetto.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Venue => Venue::class,
            self::Organizer => Organizer::class,
            self::Tag => Tag::class,
            self::Category => Category::class,
            self::Event => Event::class,
        };
    }

    /**
     * I tre tipi che alimentano feed e digest (§15.7). L'evento seguito non è
     * fra loro: le sue date arrivano nel feed perché vengono salvate, non
     * perché la sorgente sia seguita.
     *
     * @return list<self>
     */
    public static function feedSources(): array
    {
        return [self::Venue, self::Organizer, self::Tag, self::Category];
    }

    public function isFeedSource(): bool
    {
        return in_array($this, self::feedSources(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
