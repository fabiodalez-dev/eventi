<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\System;

use App\Models\ImportSource;
use App\Support\Editorial\EditorialDesk;
use App\Support\Import\ImportSourceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Il modulo di una sorgente dall'app: `POST /import-sources` con tutti i
 * campi, `PATCH /import-sources/{source}` con quelli che cambiano (per
 * esempio solo `is_active`, «attiva/disattiva»). Le regole sono quelle del
 * modulo del pannello (`ImportSourceRules`); la categoria predefinita è
 * obbligatoria se, dopo il salvataggio, la sorgente non avrà un locale.
 *
 * `credentials` è rifiutato (422): le credenziali si scrivono dal sito.
 * Prima dei campi la policy (`ImportSourcePolicy::create` o `update`): chi
 * non può scrivere riceve 403 senza sapere quali campi sarebbero sbagliati.
 */
final class SaveImportSourceRequest extends FormRequest
{
    private ?ImportSource $existing = null;

    public function authorize(): bool
    {
        $gate = Gate::forUser(EditorialDesk::require()->user);
        $existing = $this->existing();

        return $existing !== null ? $gate->allows('update', $existing) : $gate->allows('create', ImportSource::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $existing = $this->existing();
        $venueId = $existing !== null && ! $this->exists('venue_id') ? $existing->venue_id : $this->input('venue_id');
        $rules = ImportSourceRules::rules(partial: $existing !== null, venueId: $venueId);

        if ($existing !== null && ! $this->exists('default_category_id') && ImportSourceRules::needsDefaultCategory($venueId)) {
            /* Una modifica parziale che non tocca la categoria: basta quella già salvata. */
            $rules['default_category_id'] = $existing->default_category_id === null
                ? ['required']
                : ['nullable', 'integer'];
        }

        return $rules;
    }

    /** La sorgente della rotta, se è una modifica; una che non esiste è un 404. */
    private function existing(): ?ImportSource
    {
        $id = $this->route('source');
        if ($id === null) {
            return null;
        }

        return $this->existing ??= ImportSource::query()->findOrFail((int) $id);
    }
}
