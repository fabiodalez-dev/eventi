<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le finalità su cui il banner chiede una scelta (§16: «granulare»).
 *
 * Sono due perché due sono le cose che questo sito fa davvero, e un elenco più
 * lungo sarebbe un elenco inventato:
 *
 * - **Necessari** — il cookie di sessione, il token che protegge i moduli, il
 *   cookie che ricorda questa stessa scelta, e le date messe in agenda da chi
 *   non ha un account, conservate nel browser e in nessun altro posto (§15.1).
 *   Non si rifiutano perché senza di essi il sito non fa ciò che gli è stato
 *   chiesto: sono l'eccezione che l'articolo 5(3) della direttiva ePrivacy
 *   prevede per ciò che serve a fornire il servizio richiesto dall'utente.
 * - **Statistiche** — il conteggio anonimo delle pagine viste, che qui non usa
 *   cookie e non profila nessuno, ma resta una richiesta verso un altro server
 *   e quindi una scelta che spetta a chi legge.
 *
 * Non esiste una categoria «marketing»: non ci sono pubblicità, pixel, né
 * strumenti di terze parti che ne abbiano bisogno. Il giorno in cui esistesse,
 * sarebbe un caso in più qui e una riga in più nella Cookie Policy.
 */
enum ConsentCategory: string
{
    case Necessary = 'necessary';
    case Statistics = 'statistics';

    public function label(): string
    {
        return __('enums.consent_category.'.$this->value);
    }

    public function description(): string
    {
        return __('enums.consent_category_description.'.$this->value);
    }

    /**
     * Le categorie che si possono rifiutare. La necessaria non è fra queste, e
     * l'interruttore che la rappresenta nel pannello è bloccato: offrire una
     * casella che non ha effetto è peggio che non offrirla.
     *
     * @return list<self>
     */
    public static function optional(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case !== self::Necessary,
        ));
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
