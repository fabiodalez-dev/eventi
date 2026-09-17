<?php

declare(strict_types=1);

namespace App\Http\Requests\Comments;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            /*
             * Il commento a cui si risponde. `exists` senza vincolo di evento
             * qui; che appartenga a *questo* evento lo verifica il controller,
             * che l'evento ce l'ha già in mano.
             */
            'parent_id' => ['nullable', 'integer', 'exists:event_comments,id'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('events.show', ['slug' => $this->route('slug')]).'#commenti';
    }
}
