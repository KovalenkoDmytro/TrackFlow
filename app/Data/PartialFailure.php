<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Sanitised reason a platform accepted an upload (HTTP 2xx) but rejected the conversion.
 *
 * Holds only error codes, a trimmed message and the HTTP status. It is persisted on the
 * delivery row, so it must never contain click ids, tokens or other PII.
 */
final readonly class PartialFailure
{
    private const int MAX_MESSAGE_LENGTH = 500;

    /**
     * @param  list<string>  $codes  Error code names, first one is the primary reason.
     */
    public function __construct(
        public array $codes,
        public string $message,
        public int $httpStatus = 200,
    ) {}

    /**
     * Parse a Google Ads `partialFailureError` object (google.rpc.Status shape).
     *
     * Error code keys vary (conversionUploadError, conversionAdjustmentUploadError, ...),
     * so the first (key, value) pair of every `errorCode` is used.
     *
     * @param  array<string, mixed>  $error
     * @param  list<string>  $secrets  Literal values (e.g. the gclid) to strip from the message.
     */
    public static function fromGoogleAds(array $error, int $httpStatus = 200, array $secrets = []): self
    {
        $codes = [];
        $message = '';

        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        foreach ($details as $detail) {
            $errors = is_array($detail) && is_array($detail['errors'] ?? null) ? $detail['errors'] : [];
            foreach ($errors as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $errorCode = is_array($item['errorCode'] ?? null) ? $item['errorCode'] : [];
                $value = $errorCode === [] ? null : reset($errorCode);
                if (is_string($value) && $value !== '') {
                    $codes[] = $value;
                }
                // The per-error message is the specific one; the top-level message is generic.
                if ($message === '' && is_string($item['message'] ?? null)) {
                    $message = $item['message'];
                }
            }
        }

        if ($message === '' && is_string($error['message'] ?? null)) {
            $message = $error['message'];
        }

        return new self(array_values(array_unique($codes)), self::sanitize($message, $secrets), $httpStatus);
    }

    public function primaryCode(): ?string
    {
        return $this->codes[0] ?? null;
    }

    /** Short value for platform_integrations.last_error. */
    public function lastError(): string
    {
        $code = $this->primaryCode();

        return $code === null ? 'partial_failure' : 'partial_failure: '.$code;
    }

    /** JSON for platform_deliveries.response_body (kept under 1 KB). */
    public function toJson(): string
    {
        $message = $this->message;
        do {
            $json = (string) json_encode(['codes' => array_slice($this->codes, 0, 10), 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $message = mb_substr($message, 0, max(0, mb_strlen($message) - 50));
        } while (strlen($json) > 1024 && $message !== '');

        return $json;
    }

    /** @param  list<string>  $secrets */
    private static function sanitize(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        $message = (string) preg_replace('/\b(gclid|gbraid|wbraid)\b[\s:=\'"]*[A-Za-z0-9_-]{10,}/i', '$1 [redacted]', $message);
        $message = (string) preg_replace('/[A-Za-z0-9_-]{40,}/', '[redacted]', $message);

        return mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH);
    }
}
