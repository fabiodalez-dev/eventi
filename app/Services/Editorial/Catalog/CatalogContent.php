<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Enums\SeoIndexing;
use App\Models\User;
use App\Support\Description;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Le due sezioni che categorie, tag, città e pagine hanno in comune nel
 * pannello: «Informazioni utili e domande frequenti»
 * (`EditorialFields::content`) e «Anteprima su Google»
 * (`EditorialFields::seo`), con le stesse regole e gli stessi tetti.
 *
 * - `content_details`: presentazione (testo formattato, 5000), per categorie
 *   e tag anche le presentazioni per città (una per città, testo obbligatorio),
 *   e fino a 30 domande frequenti (domanda 250, risposta formattata 5000);
 * - `seo`: titolo (180), descrizione (400), immagine (indirizzo, 2048) e,
 *   solo per admin e super admin come nel pannello, visibilità sui motori;
 * - `is_demo`: contenuto dimostrativo, anche lui solo per admin e super admin.
 *
 * I testi formattati passano dallo stesso filtro dell'editor del pannello
 * (`DescriptionEditor` → `Description::sanitize`). Le chiavi ricevute
 * sostituiscono quelle salvate e le altre restano, come per il profilo del
 * locale (`UpdateVenueProfile`): una colonna JSON può portare chiavi che il
 * modulo non mostra. `HasEditorialContent` protegge comunque visibilità e
 * contenuto dimostrativo quando chi salva non è admin.
 */
final class CatalogContent
{
    public const MAX_TEXT = 5000;

    public const MAX_FAQS = 30;

    /**
     * Le regole delle due sezioni; `taxonomy` aggiunge le presentazioni per
     * città (categorie e tag, `EditorialFields::content(false, false, true)`).
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $taxonomy): array
    {
        $keys = $taxonomy ? 'introduction,city_introductions,faqs' : 'introduction,faqs';
        $rules = [
            'content_details' => ['sometimes', 'nullable', 'array:'.$keys],
            'content_details.introduction' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'content_details.faqs' => ['nullable', 'array', 'list', 'max:'.self::MAX_FAQS],
            'content_details.faqs.*' => ['array:question,answer'],
            'content_details.faqs.*.question' => ['required', 'string', 'max:250'],
            'content_details.faqs.*.answer' => ['required', 'string', 'max:'.self::MAX_TEXT],
            // La visibilità di chi non è admin si scarta in `fill()`, come il campo nascosto del pannello.
            'seo' => ['sometimes', 'nullable', 'array:title,description,image,indexing'],
            'seo.title' => ['nullable', 'string', 'max:180'],
            'seo.description' => ['nullable', 'string', 'max:400'],
            'seo.image' => ['nullable', 'string', 'url', 'max:2048'],
            'seo.indexing' => ['nullable', Rule::enum(SeoIndexing::class)],
            'is_demo' => ['sometimes', 'boolean'],
        ];
        if ($taxonomy) {
            $rules += [
                'content_details.city_introductions' => ['nullable', 'array', 'list'],
                'content_details.city_introductions.*' => ['array:city_id,text'],
                'content_details.city_introductions.*.city_id' => ['required', 'integer', 'distinct', Rule::exists('cities', 'id')],
                'content_details.city_introductions.*.text' => ['required', 'string', 'max:'.self::MAX_TEXT],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'content_details.introduction' => (string) __('seo.fields.introduction'),
            'content_details.city_introductions' => (string) __('seo.city_introductions'),
            'content_details.city_introductions.*.city_id' => (string) __('seo.search_console.city'),
            'content_details.city_introductions.*.text' => (string) __('seo.fields.introduction'),
            'content_details.faqs' => (string) __('seo.faqs'),
            'content_details.faqs.*.question' => (string) __('seo.question'),
            'content_details.faqs.*.answer' => (string) __('seo.answer'),
            'seo.title' => (string) __('seo.fields.title'),
            'seo.description' => (string) __('seo.fields.description'),
            'seo.image' => (string) __('seo.fields.image'),
            'seo.indexing' => (string) __('seo.indexing'),
            'is_demo' => (string) __('seo.demo'),
        ];
    }

    /**
     * Le chiavi convalidate sul modello, senza salvarlo.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fill(Model $model, array $state, User $actor): void
    {
        if (array_key_exists('content_details', $state)) {
            $saved = $model->getAttribute('content_details');
            $model->setAttribute('content_details', array_replace(
                is_array($saved) ? $saved : [],
                self::cleanDetails(is_array($state['content_details']) ? $state['content_details'] : []),
            ));
        }

        if (array_key_exists('seo', $state)) {
            $saved = $model->getAttribute('seo');
            $seo = is_array($state['seo']) ? $state['seo'] : [];
            $clean = [];
            foreach (['title', 'description', 'image'] as $key) {
                if (array_key_exists($key, $seo)) {
                    $clean[$key] = self::blankToNull($seo[$key]);
                }
            }
            if (self::admin($actor) && array_key_exists('indexing', $seo)) {
                $clean['indexing'] = $seo['indexing'] ?? SeoIndexing::Automatic->value;
            }
            $model->setAttribute('seo', array_replace(is_array($saved) ? $saved : [], $clean));
        }

        if (self::admin($actor) && array_key_exists('is_demo', $state)) {
            $model->setAttribute('is_demo', (bool) $state['is_demo']);
        }
    }

    /**
     * Le due sezioni per il modulo dell'app.
     *
     * @return array<string, mixed>
     */
    public static function form(Model $model, bool $taxonomy): array
    {
        $details = is_array($model->getAttribute('content_details')) ? $model->getAttribute('content_details') : [];
        $seo = is_array($model->getAttribute('seo')) ? $model->getAttribute('seo') : [];
        $content = [
            'introduction' => self::text($details['introduction'] ?? null),
            'faqs' => array_values(array_map(static fn (mixed $row): array => [
                'question' => is_array($row) ? (string) ($row['question'] ?? '') : '',
                'answer' => is_array($row) ? self::text($row['answer'] ?? null) : '',
            ], is_array($details['faqs'] ?? null) ? $details['faqs'] : [])),
        ];
        if ($taxonomy) {
            $content['city_introductions'] = array_values(array_map(static fn (mixed $row): array => [
                'city_id' => is_array($row) && is_numeric($row['city_id'] ?? null) ? (int) $row['city_id'] : null,
                'text' => is_array($row) ? self::text($row['text'] ?? null) : '',
            ], is_array($details['city_introductions'] ?? null) ? $details['city_introductions'] : []));
        }

        return [
            'content_details' => $content,
            'seo' => [
                'title' => self::text($seo['title'] ?? null),
                'description' => self::text($seo['description'] ?? null),
                'image' => self::text($seo['image'] ?? null),
                'indexing' => is_string($seo['indexing'] ?? null) ? $seo['indexing'] : SeoIndexing::Automatic->value,
            ],
            'is_demo' => (bool) $model->getAttribute('is_demo'),
        ];
    }

    /** Visibilità sui motori e contenuto dimostrativo: solo admin e super admin, come `EditorialFields::admin()`. */
    public static function admin(User $actor): bool
    {
        return $actor->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function cleanDetails(array $details): array
    {
        $clean = [];
        if (array_key_exists('introduction', $details)) {
            $clean['introduction'] = self::rich($details['introduction']);
        }
        if (array_key_exists('faqs', $details)) {
            $clean['faqs'] = array_values(array_map(static fn (array $row): array => [
                'question' => (string) $row['question'],
                'answer' => (string) self::rich($row['answer']),
            ], is_array($details['faqs']) ? $details['faqs'] : []));
        }
        if (array_key_exists('city_introductions', $details)) {
            $clean['city_introductions'] = array_values(array_map(static fn (array $row): array => [
                'city_id' => (int) $row['city_id'],
                'text' => (string) self::rich($row['text']),
            ], is_array($details['city_introductions']) ? $details['city_introductions'] : []));
        }

        return $clean;
    }

    /** Il filtro dell'editor del pannello; un testo vuoto resta vuoto. */
    private static function rich(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Description::sanitize($value);
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
