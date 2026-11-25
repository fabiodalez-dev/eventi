<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Logo, copertina e galleria del locale. Le immagini del locale le cambia chi
 * può cambiarne il profilo (`VenuePolicy::update`, cioè il referente), come
 * nella pagina «Profilo del locale».
 */
final class VenueMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->venue()) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('put')) {
            return [
                'ids' => ['required', 'array', 'list', 'max:100'],
                'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            ];
        }

        return $this->isMethod('post') ? ImageRules::rules() : [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ImageRules::attributes();
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }

    /** `logo` o `cover`: la rotta non accetta altro. */
    public function collection(): string
    {
        $collection = $this->route('collection');
        abort_unless(in_array($collection, ['logo', 'cover'], true), 404);

        return $collection;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids')));
    }
}
