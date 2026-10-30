<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
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
     * I soggetti che il pubblico può vedere, e quindi seguire: le stesse regole
     * delle letture pubbliche (evento pubblicato o archiviato, locale
     * approvato o sospeso, tag approvato, categoria e organizzatore attivi).
     *
     * È l'unico punto di verità per «si può seguire?» e «se ne può mostrare il
     * nome?»: senza, chiunque avesse un account poteva seguire per id una
     * bozza, una proposta in moderazione o un locale rifiutato e leggerne
     * titolo e slug nella risposta.
     *
     * @return Builder<Model>
     */
    public function publicQuery(): Builder
    {
        /** @var Builder<Model> $query */
        $query = $this->modelClass()::query();
        $this->constrainPublic($query);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function constrainPublic(Builder $query): void
    {
        match ($this) {
            self::Venue => $query->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended]),
            self::Organizer, self::Category => $query->where('is_active', true),
            self::Tag => $query->where('is_approved', true),
            self::Event => $query->whereIn('status', [EventStatus::Published, EventStatus::Archived]),
        };
    }

    /**
     * Le stesse regole, nella forma che `MorphTo::constrain()` vuole: chi
     * carica i soggetti dei «segui» con questi vincoli trova `null` al posto
     * di un evento tornato in bozza o di un locale rifiutato dopo il follow,
     * e non ne mostra né il nome né lo slug.
     *
     * @return array<class-string<Model>, \Closure(Builder<Model>): void>
     */
    public static function publicConstraints(): array
    {
        $constraints = [];

        foreach (self::cases() as $case) {
            $constraints[$case->modelClass()] = static function (Builder $query) use ($case): void {
                $case->constrainPublic($query);
            };
        }

        return $constraints;
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
