<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Masks credentials that travel in URL query strings (GA4 api_secret, access_token, ...)
 * and therefore end up in HTTP client exception messages.
 */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    private const QUERY_SECRET = '/([?&][A-Za-z0-9_.\-]*(?:token|secret|key|password|signature)[A-Za-z0-9_.\-]*=)[^&\s"\'<>)]*/i';

    public static function redact(string $text): string
    {
        return (string) preg_replace(self::QUERY_SECRET, '$1'.self::REDACTED, $text);
    }

    /** Redacted and bounded so it always fits a varchar column of $limit characters. */
    public static function bounded(string $text, int $limit = 255): string
    {
        return Str::limit(self::redact($text), $limit - 3, '...', true);
    }
}
