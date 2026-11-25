<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Models\Event;
use App\Models\User;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Importa da Facebook»: chi può creare eventi per il soggetto incolla il
 * link di un evento pubblico (`EventPolicy::create`, come i due pannelli).
 */
final class FacebookImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Event::class, ManagementSubject::require()->model]) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'max:2048'],
            'city_id' => ['nullable', 'integer'],
        ];
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
