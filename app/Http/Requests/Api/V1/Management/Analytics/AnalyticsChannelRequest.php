<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Analytics;

use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * I canali propri del locale e i loro QR (W-ST-06): vederli e crearne uno.
 *
 * Solo il referente, come la sezione del pannello
 * (`EventAnalyticsPage::canManageChannels` chiede `VenuePolicy::update`): un
 * collaboratore pubblica le date, non apre canali che poi restano nei
 * conteggi. Le rotte esistono solo per il locale: nel pannello
 * dell'organizzazione la sezione non c'è.
 */
final class AnalyticsChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $venue = ManagementSubject::current()?->venue();

        return $venue instanceof Venue && $this->user()?->can('update', $venue) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            // Lo stesso campo del pannello: al massimo 38 caratteri.
            'label' => ['required', 'string', 'max:38'],
            'event' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
