<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Social;

use App\Enums\SocialFormat;
use App\Services\Social\SocialStudioDesk;
use Illuminate\Validation\Rule;

/**
 * «Prepara le grafiche selezionate» (W-SO-04, W-SO-05): un giorno, da 1 a
 * 100 serate di quel giorno, il formato e le opzioni della pagina. I valori
 * predefiniti sono quelli con cui si apre la pagina.
 */
final class SocialBatchStoreRequest extends SocialStudioRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'occurrences' => ['required', 'array', 'min:1', 'max:'.SocialStudioDesk::MAX_SELECTION],
            'occurrences.*' => ['integer', 'min:1'],
            'format' => ['nullable', 'string', Rule::enum(SocialFormat::class)],
            'monochrome' => ['nullable', 'boolean'],
            'show_address' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
            'image_fit' => ['nullable', 'string', Rule::in(['cover', 'contain'])],
            'title' => ['nullable', 'string', 'max:'.SocialStudioDesk::TITLE_MAX],
            'event' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function occurrenceIds(): array
    {
        $ids = $this->validated('occurrences');

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    public function socialFormat(): SocialFormat
    {
        return SocialFormat::tryFrom($this->string('format')->toString()) ?? SocialFormat::Portrait;
    }

    /** @return array{monochrome: bool, show_address: bool, show_price: bool, image_fit: string, title: string} */
    public function options(): array
    {
        return [
            'monochrome' => $this->has('monochrome') ? $this->boolean('monochrome') : true,
            'show_address' => $this->has('show_address') ? $this->boolean('show_address') : true,
            'show_price' => $this->has('show_price') ? $this->boolean('show_price') : true,
            'image_fit' => $this->string('image_fit')->toString() ?: 'contain',
            'title' => trim($this->string('title')->toString()),
        ];
    }
}
