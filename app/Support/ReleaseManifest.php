<?php

declare(strict_types=1);

namespace App\Support;

final class ReleaseManifest
{
    public static function matches(mixed $manifest, mixed $sha): bool
    {
        return is_string($sha)
            && preg_match('/^[a-f0-9]{40}$/D', $sha) === 1
            && is_array($manifest)
            && ($manifest['sha'] ?? null) === $sha
            && ($manifest['checks'] ?? null) === 'passed'
            && isset($manifest['run_id'])
            && ctype_digit((string) $manifest['run_id']);
    }
}
