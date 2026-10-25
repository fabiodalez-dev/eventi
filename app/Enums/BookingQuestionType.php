<?php

declare(strict_types=1);

namespace App\Enums;

/** Come si risponde a una domanda del gestore al momento della prenotazione. */
enum BookingQuestionType: string
{
    case Text = 'text';
    case Choice = 'choice';
    case YesNo = 'yes_no';
}
