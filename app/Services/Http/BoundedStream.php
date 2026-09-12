<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Exceptions\ImportException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

final class BoundedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $written = 0;

    public function __construct(private readonly int $limit)
    {
        $this->stream = Utils::streamFor(fopen('php://temp/maxmemory:1048576', 'w+'));
    }

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > $this->limit) {
            throw ImportException::tooLarge($this->limit);
        }
        $count = $this->stream->write($string);
        $this->written += $count;

        return $count;
    }
}
