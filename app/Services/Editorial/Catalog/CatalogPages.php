<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Le pagine informative (R-CT-07), come `PageResource`: solo chi ha
 * `pages.manage` (admin e super admin). Colonne titolo, indirizzo,
 * pubblicata, ordine, ultima modifica; filtro pubblicata; ordine predefinito
 * `sort_order`. Il corpo è Markdown, convertito dal modello scartando ogni
 * marcatura grezza (`Page::renderedBody`): l'anteprima dell'app è la stessa
 * conversione, fatta dal server.
 *
 * Dal telefono si corregge un refuso mentre qualcun altro può avere la
 * stessa pagina aperta sul sito: il salvataggio porta la `revision` letta e,
 * se nel frattempo la pagina è cambiata, risponde 409 invece di
 * sovrascrivere (decisione 7). Il pannello non ha questo controllo.
 */
final class CatalogPages extends CatalogSection
{
    public function model(): string
    {
        return Page::class;
    }

    public function filters(): array
    {
        return ['is_published'];
    }

    protected function listQuery(): Builder
    {
        return Page::query()->select(['id', 'slug', 'title', 'is_published', 'sort_order', 'updated_at']);
    }

    protected function searchColumns(): array
    {
        return ['title', 'slug'];
    }

    protected function sorts(): array
    {
        return ['title' => 'title', 'sort_order' => 'sort_order', 'updated_at' => 'updated_at'];
    }

    protected function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }

    public function row(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            'id' => $record->id,
            'title' => (string) $record->title,
            'slug' => (string) $record->slug,
            'is_published' => (bool) $record->is_published,
            'sort_order' => (int) $record->sort_order,
            'updated_at' => self::updatedAt($record)?->toIso8601String(),
            'web_path' => $this->webPath($record),
            'abilities' => $this->abilities($record, $user),
        ];
    }

    public function form(Model $record, User $user): array
    {
        $record = self::record($record);

        return [
            ...$this->row($record, $user),
            'excerpt' => (string) ($record->excerpt ?? ''),
            'body' => (string) $record->body,
            'seo_title' => (string) ($record->seo_title ?? ''),
            'seo_description' => (string) ($record->seo_description ?? ''),
            'url' => route('pages.show', $record->slug),
            'revision' => self::revision($record),
            ...CatalogContent::form($record, taxonomy: false),
        ];
    }

    /**
     * Il salvataggio con il controllo della revisione: 409 `CONFLICT` se la
     * pagina non è più quella che l'app ha letto.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateRevised(Page $page, array $input, User $actor): Page
    {
        $revision = $input['revision'] ?? null;
        if (! is_string($revision) || $revision === '') {
            throw new ApiException(ApiErrorCode::ValidationFailed, null, ['revision' => [(string) __('validation.required', ['attribute' => 'revision'])]]);
        }
        if (! hash_equals(self::revision($page), $revision)) {
            throw new ApiException(ApiErrorCode::Conflict, (string) __('editorial-catalog.page_changed'));
        }

        return self::record($this->update($page, $input, $actor));
    }

    /** Il corpo come lo vede chi legge la pagina sul sito. */
    public static function preview(string $body): string
    {
        return (new Page(['body' => $body]))->renderedBody()->toHtml();
    }

    /**
     * La firma della pagina com'è salvata: cambia a ogni modifica di un
     * campo del modulo, anche nello stesso secondo.
     */
    public static function revision(Page $page): string
    {
        $fresh = Page::query()->whereKey($page->getKey())->first() ?? $page;

        return substr(hash('sha256', (string) json_encode([
            self::updatedAt($fresh)?->format('Y-m-d H:i:s.u'),
            $fresh->title, $fresh->slug, $fresh->excerpt, $fresh->body, $fresh->is_published, $fresh->sort_order,
            $fresh->seo_title, $fresh->seo_description, $fresh->getAttribute('content_details'), $fresh->getAttribute('seo'), $fresh->getAttribute('is_demo'),
        ])), 0, 32);
    }

    protected function rules(?Model $record, bool $creating): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:191', Rule::unique('pages', 'slug')->ignore($record?->getKey()), 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:'.Page::BODY_MAX],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            ...CatalogContent::rules(taxonomy: false),
        ];
    }

    protected function attributes(): array
    {
        return [
            'title' => (string) __('admin.fields.title'),
            'slug' => (string) __('admin.fields.slug'),
            'excerpt' => (string) __('admin.fields.excerpt'),
            'body' => (string) __('admin.fields.body'),
            'is_published' => (string) __('admin.fields.is_published'),
            'sort_order' => (string) __('admin.fields.sort_order'),
            'seo_title' => (string) __('admin.fields.seo_title'),
            'seo_description' => (string) __('admin.fields.seo_description'),
            ...CatalogContent::attributes(),
        ];
    }

    protected function fill(Model $record, array $state, User $actor): void
    {
        $record = self::record($record);
        foreach (['title', 'slug', 'excerpt', 'body', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $state)) {
                $record->setAttribute($field, self::blankToNull($state[$field]));
            }
        }
        if (array_key_exists('sort_order', $state)) {
            $record->setAttribute('sort_order', (int) ($state['sort_order'] ?? 0));
        }
        if (array_key_exists('is_published', $state)) {
            $record->setAttribute('is_published', (bool) $state['is_published']);
        } elseif (! $record->exists) {
            $record->setAttribute('is_published', false);
        }
        CatalogContent::fill($record, $state, $actor);
    }

    private static function updatedAt(Page $page): ?CarbonInterface
    {
        $value = $page->getAttribute('updated_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    /** La voce di questa sezione: un altro modello qui è un errore di chi chiama. */
    private static function record(Model $record): Page
    {
        if (! $record instanceof Page) {
            throw new \InvalidArgumentException('Atteso '.Page::class.', ricevuto '.$record::class.'.');
        }

        return $record;
    }
}
