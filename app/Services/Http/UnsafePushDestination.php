<?php

declare(strict_types=1);

namespace App\Services\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class UnsafePushDestination extends RuntimeException implements ClientExceptionInterface {}
