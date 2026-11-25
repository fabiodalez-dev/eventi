<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use Carbon\CarbonImmutable;

/**
 * «Programma pubblicazione»: un istante futuro, con il suo scarto
 * (`2026-12-05T18:00:00+01:00`). Le altre condizioni (bozza o in attesa, con
 * almeno una data) le controlla `ScheduleEventPublication`, come nel
 * pannello.
 */
final class EventScheduleRequest extends ManagedEventRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'publish_at' => ['required', 'date', 'after:now'],
        ];
    }

    public function publishAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('publish_at')->toString())->utc();
    }
}
