<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use App\Services\Settings\NewsletterConfiguration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il modulo della newsletter, con le regole dei campi del pannello: giorno
 * da 1 a 7, ora `HH:MM`, da 1 a 30 eventi, testi fino a 200, 200, 2000 e
 * 120 caratteri (vuoti tornano all'originale).
 */
final class UpdateNewsletterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'weekday' => ['required', 'integer', 'in:'.implode(',', array_keys(NewsletterConfiguration::WEEKDAYS))],
            'time' => ['required', 'string', 'regex:'.NewsletterConfiguration::TIME_PATTERN],
            'max_items' => ['required', 'integer', 'min:'.NewsletterConfiguration::MAX_ITEMS_MIN, 'max:'.NewsletterConfiguration::MAX_ITEMS_MAX],
        ];
        foreach (NewsletterConfiguration::TEXT_MAX as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'weekday' => 'giorno di invio',
            'time' => 'ora di invio',
            'max_items' => 'numero massimo di eventi',
            'subject' => 'oggetto',
            'heading' => 'titolo',
            'line' => 'testo introduttivo',
            'action' => 'testo del pulsante',
        ];
    }
}
