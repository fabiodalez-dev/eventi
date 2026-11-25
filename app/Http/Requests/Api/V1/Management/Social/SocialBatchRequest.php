<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Social;

use App\Models\SocialBatch;
use Illuminate\Support\Facades\Gate;

/**
 * Un pacchetto già preparato (W-SO-06, W-SO-07): si cerca solo fra quelli
 * del locale (404 altrimenti) e decide `SocialBatchPolicy::view`, come lo
 * ZIP del sito.
 */
final class SocialBatchRequest extends SocialStudioRequest
{
    private ?SocialBatch $batch = null;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return Gate::forUser($this->actor())->allows('view', $this->batch());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function batch(): SocialBatch
    {
        return $this->batch ??= SocialBatch::query()
            ->where('venue_id', $this->venue()->getKey())
            ->whereKey((string) $this->route('batch'))
            ->firstOrFail();
    }
}
