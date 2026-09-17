<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

final class AuthEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['intended' => ['nullable', 'string', 'max:2048']];
    }

    public function rememberDestination(): void
    {
        $destination = $this->validated('intended');

        // Only absolute URLs on this origin can become a post-login redirect.
        if (is_string($destination)
            && str_starts_with($destination, $this->getSchemeAndHttpHost().'/')
            && ! preg_match('/[\\\\\x00-\x20\x7f]/', $destination)) {
            $this->session()->put('url.intended', $destination);
        }
    }
}
