<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Ci vado», o il passo indietro.
 *
 * `going` è obbligatorio di proposito. Letto con `Request::boolean()`, un
 * campo assente o scritto male vale `false`, e `false` qui non è un valore
 * neutro: ritira la partecipazione. Una
 * richiesta malformata non deve poter fare in silenzio la cosa distruttiva
 * delle due.
 */
final class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['going' => ['required', 'boolean']];
    }

    public function going(): bool
    {
        return $this->boolean('going');
    }
}
