<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il passo 3 del wizard: nome del sito, indirizzo e posta in uscita.
 *
 * `APP_ENV=production` e `APP_DEBUG=false` non compaiono: si scrivono da soli.
 * Sono le due variabili che nessuno sceglie male apposta e che, scelte male,
 * mostrano la traccia di stack — con dentro le credenziali — a chiunque passi.
 *
 * `log` come mailer non è un ripiego nascosto: è dichiarato. Un sistema che
 * finge di spedire e non spedisce è peggio di uno che dice di non farlo.
 */
class ApplicationStepRequest extends FormRequest
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
            'app_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'mail_mailer' => ['required', Rule::in(['log', 'smtp'])],
            'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
            'mail_port' => ['nullable', 'required_if:mail_mailer,smtp', 'integer', 'between:1,65535'],
            'mail_scheme' => ['nullable', Rule::in(['auto', 'smtps'])],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email:filter', 'max:255'],
            'ops_alert_email' => ['nullable', 'email:filter', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('installer.application.fields');

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'app_name' => $this->string('app_name')->trim()->value(),
            'app_url' => rtrim($this->string('app_url')->trim()->value(), '/'),
            'mail_mailer' => $this->string('mail_mailer')->value(),
            'mail_host' => $this->string('mail_host')->trim()->value(),
            'mail_port' => $this->string('mail_port')->trim()->value(),
            'mail_scheme' => $this->string('mail_scheme')->value(),
            'mail_username' => $this->string('mail_username')->trim()->value(),
            'mail_password' => (string) $this->input('mail_password', ''),
            'mail_from_address' => $this->string('mail_from_address')->trim()->value(),
            'ops_alert_email' => $this->string('ops_alert_email')->trim()->value(),
        ];
    }
}
