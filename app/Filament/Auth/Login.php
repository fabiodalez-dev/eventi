<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\SecurityLog;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        $email = mb_strtolower(trim((string) ($this->data['email'] ?? '')));
        $key = 'panel-login-account:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, config()->integer('api.rate_limit.attempts_per_account'))) {
            SecurityLog::scrivi('accesso.limitato');
            throw ValidationException::withMessages(['data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }
        RateLimiter::hit($key, 900);

        return parent::authenticate();
    }
}
