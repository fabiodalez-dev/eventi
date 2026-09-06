<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialPublicationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}
