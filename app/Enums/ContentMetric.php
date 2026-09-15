<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentMetric: string
{
    case Views = 'views';
    case Directions = 'direction_clicks';
    case Tickets = 'ticket_clicks';
    case Shares = 'shares';
    case Website = 'website_clicks';
    case Phone = 'phone_clicks';
    case Email = 'email_clicks';
    case Calendar = 'calendar_clicks';
    case Poster = 'poster_clicks';
    case Booking = 'booking_clicks';

    public function label(): string
    {
        return __('analytics.metrics.'.$this->value);
    }
}
