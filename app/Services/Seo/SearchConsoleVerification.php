<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\City;
use App\Support\CurrentCity;
use Illuminate\Support\Facades\Validator;

/**
 * Il codice di verifica di Google Search Console di una città (R-ST-05): il
 * valore `content` del tag `google-site-verification`, che il sito pubblica
 * nelle pagine di quella città (`layouts/app.blade.php`).
 *
 * Una regola sola per la pagina «SEO e qualità del catalogo» del pannello e
 * per l'app della redazione: stessi limiti del campo (al massimo 255
 * caratteri, solo lettere, cifre, `_` e `-`, vuoto per toglierlo) e lo
 * stesso salvataggio, che tocca solo `seo.google_verification` e lascia
 * com'erano le altre impostazioni SEO della città.
 */
final class SearchConsoleVerification
{
    public const MAX = 255;

    /** Niente tag HTML, niente spazi: il solo valore del codice. */
    public const PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly CurrentCity $currentCity) {}

    /** Il codice salvato per la città, `null` se non c'è. */
    public static function code(City $city): ?string
    {
        $code = $city->getAttribute('seo')['google_verification'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Le regole del modulo (`city_id`, `verification`), le stesse dei campi
     * del pannello.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'verification' => ['nullable', 'string', 'max:'.self::MAX, 'regex:'.self::PATTERN],
        ];
    }

    /**
     * Controlla e salva. Un errore di validazione esce come
     * `ValidationException` sui nomi dei campi del modulo.
     *
     * @param  array<string, mixed>  $input
     */
    public function validateAndSave(array $input): City
    {
        $data = Validator::make($input, self::rules(), [], [
            'city_id' => __('seo.search_console.city'),
            'verification' => __('seo.search_console.code'),
        ])->validate();

        return $this->save(City::query()->findOrFail((int) $data['city_id']), $data['verification'] ?? null);
    }

    /** Salva il codice (vuoto: lo toglie) senza toccare il resto di `seo`. */
    public function save(City $city, ?string $code): City
    {
        $code = is_string($code) && trim($code) !== '' ? trim($code) : null;
        $city->update(['seo' => array_replace($city->seo ?? [], ['google_verification' => $code])]);
        /* La città di questa richiesta, se è la stessa, legge subito il codice nuovo. */
        if ($this->currentCity->get()?->id === $city->id) {
            $this->currentCity->set($city);
        }

        return $city;
    }
}
