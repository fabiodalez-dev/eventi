<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\BookingFieldMode;
use App\Models\EventOccurrence;
use Illuminate\Support\Facades\Validator;

final class BookingForm
{
    public const FIELDS = ['phone', 'address', 'city', 'postal_code', 'country'];

    public const PRIVACY_VERSION = 'ticketing-2026-09-05';

    /** @return list<array{key: string, label: string, required: bool}> */
    public function fields(EventOccurrence $date): array
    {
        $fields = [];
        foreach (self::FIELDS as $key) {
            $mode = BookingFieldMode::tryFrom($date->booking_fields[$key] ?? '') ?? BookingFieldMode::Hidden;
            if ($mode !== BookingFieldMode::Hidden) {
                $fields[] = ['key' => $key, 'label' => __('ticketing.fields.'.$key), 'required' => $mode === BookingFieldMode::Required];
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    public function validate(EventOccurrence $date, array $input): array
    {
        // Kept compatible with the old APK only when no extra data is required.
        // Never guess how an existing full name should be split.
        $rules = [];
        if ($input !== []) {
            $rules = ['first_name' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'last_name' => ['required', 'string', 'max:120', 'regex:/\S/u']];
        }
        $labels = ['first_name' => __('ticketing.fields.first_name'), 'last_name' => __('ticketing.fields.last_name')];
        foreach ($this->fields($date) as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:255', 'regex:/\S/u'];
            $labels[$field['key']] = $field['label'];
        }
        $validated = Validator::make($input, $rules, [], $labels)->validate();

        return array_map(fn ($value) => is_string($value) ? trim($value) : null, $validated);
    }
}
