<?php

declare(strict_types=1);

namespace App\Services\Carpool;

final class CarpoolTerms
{
    public function text(): string
    {
        return (string) file_get_contents(resource_path('legal/carpool/'.config('carpool.terms_version').'.it.md'));
    }

    public function hash(): string
    {
        return hash('sha256', $this->text());
    }
}
