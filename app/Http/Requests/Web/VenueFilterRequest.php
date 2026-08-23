<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\VenueType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtri della lista dei locali (§11.9): tipo, comune, ricerca per nome.
 *
 * Come per gli eventi, un valore non riconosciuto viene scartato invece di
 * produrre un errore: un link vecchio deve continuare a mostrare una pagina.
 */
class VenueFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(VenueType::values())],
            'municipality' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = $this->query('type');

        if (is_string($type) && VenueType::tryFrom($type) === null) {
            $this->query->remove('type');
        }
    }

    public function type(): ?VenueType
    {
        $type = $this->query('type');

        return is_string($type) ? VenueType::tryFrom($type) : null;
    }

    public function municipality(): ?string
    {
        $municipality = $this->validated('municipality');

        return is_string($municipality) && $municipality !== '' ? $municipality : null;
    }

    public function term(): string
    {
        $term = $this->validated('q');

        return is_string($term) ? trim($term) : '';
    }
}
