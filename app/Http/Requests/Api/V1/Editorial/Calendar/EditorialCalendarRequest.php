<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Calendar;

use App\Services\Editorial\EditorialCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/editorial/calendar?from=&to=`: i giorni da mostrare,
 * `AAAA-MM-GG` nel fuso della città di lavoro, al massimo
 * `EditorialCalendar::MAX_DAYS` giorni. Chi entra lo decide la porta della
 * redazione: la pagina «Calendario» del pannello è di tutta la redazione.
 */
final class EditorialCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail): void {
                $from = $this->input('from');
                /* Un giorno scritto male lo segnala già `date_format`: qui solo la lunghezza. */
                if (! is_string($from) || ! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    return;
                }
                $start = CarbonImmutable::createFromFormat('Y-m-d', $from);
                $end = CarbonImmutable::createFromFormat('Y-m-d', $value);
                if ($start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $start->diffInDays($end) >= EditorialCalendar::MAX_DAYS) {
                    $fail(__('editorial-calendar.too_long', ['days' => EditorialCalendar::MAX_DAYS]));
                }
            }],
        ];
    }

    public function fromDay(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('from'), $timezone)?->startOfDay() ?? CarbonImmutable::now($timezone)->startOfDay();
    }

    public function toDay(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('to'), $timezone)?->startOfDay() ?? CarbonImmutable::now($timezone)->startOfDay();
    }
}
