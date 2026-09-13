<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\ContactMode;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PublicContactService
{
    /** @return array<string, mixed> */
    public function settings(Venue|Organizer $target): array
    {
        $enabled = $target->contact_mode !== ContactMode::Disabled && filled($target->contact_email);

        return ['enabled' => $enabled, 'guests' => $enabled && $target->contact_mode === ContactMode::Everyone && $this->captchaReady(),
            'url' => route($target instanceof Venue ? 'venues.show' : 'organizers.show', $target).'#contatta'];
    }

    public function captchaReady(): bool
    {
        return filled(config('contact.recaptcha_site_key')) && filled(config('contact.recaptcha_secret_key'));
    }

    /** @param array<string, mixed> $data */
    public function send(Venue|Organizer $target, ?User $user, array $data): void
    {
        abort_unless($target->contact_mode !== ContactMode::Disabled && filled($target->contact_email), 404);
        if ($user === null) {
            abort_unless($target->contact_mode === ContactMode::Everyone && $this->captchaReady(), 403);
            try {
                $result = Http::asForm()->connectTimeout(2)->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', ['secret' => config('contact.recaptcha_secret_key'), 'response' => $data['g-recaptcha-response'] ?? ''])->json();
            } catch (Throwable) {
                $result = [];
            }
            if (($result['success'] ?? false) !== true || ($result['hostname'] ?? '') !== config('contact.recaptcha_hostname')) {
                throw ValidationException::withMessages(['g-recaptcha-response' => __('contact.captcha')]);
            }
        }
        Mail::to($target->contact_email)->send(new PublicContact($target->name, $user->name ?? (string) $data['name'], $user->email ?? (string) $data['email'], (string) $data['message']));
    }
}
