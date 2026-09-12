<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\SecurityLog;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

final class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function request(): void
    {
        $key = 'panel-reset-email:'.hash('sha256', request()->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, config()->integer('api.rate_limit.outbound_emails_per_hour'))) {
            SecurityLog::blocco();
            $this->getSentNotification(Password::RESET_LINK_SENT)?->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        parent::request();
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        return $this->getSentNotification(Password::RESET_LINK_SENT);
    }
}
