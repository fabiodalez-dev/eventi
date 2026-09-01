<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Il passo 4 del wizard: la città (D42, punto 6).
 *
 * L'applicazione non esiste senza una città attiva (§7.1), quindi non è un
 * dato facoltativo da rimandare al pannello.
 *
 * Le coordinate si **incollano**, non si cercano: nessuna chiamata a un
 * servizio di geocodifica. Un campo che interroga Nominatim sarebbe più comodo
 * e introdurrebbe una dipendenza esterna — più un trasferimento di dati verso
 * terzi — proprio nel momento in cui il sistema è più fragile.
 */
class CityStepRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9-]*$/'],
            'province_code' => ['required', 'string', 'size:2', 'alpha'],
            'province_name' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'center_lat' => ['required', 'numeric', 'between:-90,90'],
            'center_lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'integer', 'between:1,500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('installer.city.fields');

        return $fields;
    }

    /**
     * Lo slug lasciato vuoto si ricava dal nome. Derivarlo qui e non con un
     * po' di JavaScript nel modulo è ciò che lo fa funzionare anche su un
     * browser che non ne esegue: durante un'installazione non è il momento di
     * scoprire che un campo obbligatorio si riempiva da solo e non l'ha fatto.
     */
    private function slug(): string
    {
        $slug = $this->string('slug')->trim()->value();

        return $slug === '' ? Str::slug($this->string('name')->trim()->value()) : $slug;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'slug' => $this->slug(),
            'province_code' => $this->string('province_code')->trim()->upper()->value(),
            'province_name' => $this->string('province_name')->trim()->value(),
            'region' => $this->string('region')->trim()->value(),
            'timezone' => $this->string('timezone')->value(),
            'center_lat' => $this->string('center_lat')->trim()->value(),
            'center_lng' => $this->string('center_lng')->trim()->value(),
            'radius_km' => (string) $this->integer('radius_km'),
        ];
    }
}
