<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il modulo del banner (§16). Ha tre uscite e una sola rotta: accetta tutto,
 * rifiuta tutto, salva le preferenze scelte una per una.
 *
 * Le prime due non guardano nemmeno le caselle: chi preme «Rifiuta» mentre una
 * casella è rimasta spuntata ha rifiutato, e leggere lo stato del modulo invece
 * del pulsante premuto sarebbe il modo esatto in cui un banner finisce per
 * registrare il contrario di ciò che gli è stato detto.
 */
class StoreConsentRequest extends FormRequest
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
            'action' => ['required', Rule::in(ConsentAction::values())],
            'categories' => ['array'],
            'categories.*' => [Rule::in(ConsentCategory::values())],
        ];
    }

    public function consentAction(): ConsentAction
    {
        return ConsentAction::from((string) $this->validated('action'));
    }

    /**
     * Le finalità accettate. La categoria necessaria non compare fra le
     * facoltative e non si legge dal modulo: è vera sempre, e accettarla o
     * rifiutarla non è una scelta che esista.
     *
     * @return list<ConsentCategory>
     */
    public function acceptedCategories(): array
    {
        $action = $this->consentAction();

        if ($action === ConsentAction::AcceptAll) {
            return ConsentCategory::optional();
        }

        if ($action === ConsentAction::RejectAll) {
            return [];
        }

        $selected = $this->validated('categories');
        $selected = is_array($selected) ? $selected : [];

        return array_values(array_filter(
            ConsentCategory::optional(),
            static fn (ConsentCategory $category): bool => in_array($category->value, $selected, true),
        ));
    }
}
