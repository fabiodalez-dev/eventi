<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La risposta a un invito ricevuto (W-CO-07, W-TK-15): `decision` e `sent`,
 * la data dell'invito come la riporta `GET /me/invitations`. `sent` fa ciò
 * che fa la data nel collegamento firmato: un invito reinviato nel frattempo
 * è un altro invito, e la risposta a quello vecchio non vale.
 *
 * Il permesso è dell'invito stesso (deve essere per questa persona): lo
 * controlla il controller, che risponde 404 per un invito altrui.
 */
final class InvitationResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:accept,decline'],
            'sent' => ['required', 'integer', 'min:0'],
        ];
    }

    public function acceptsInvitation(): bool
    {
        return $this->validated('decision') === 'accept';
    }

    public function sent(): int
    {
        return (int) $this->validated('sent');
    }

    public function invitee(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
