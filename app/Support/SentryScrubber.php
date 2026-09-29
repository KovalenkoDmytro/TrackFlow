<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Removes credentials and click identifiers from Sentry payloads before they
 * leave the server. Shopify/Google/Meta tokens all flow through this app.
 */
final class SentryScrubber
{
    private const FILTERED = '[Filtered]';

    private const SENSITIVE_KEY = '/token|secret|password|credential|gclid|authorization|cookie|api[_-]?key/i';

    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'];

    public static function scrub(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            if (isset($request['headers']) && is_array($request['headers'])) {
                foreach (array_keys($request['headers']) as $name) {
                    if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                        unset($request['headers'][$name]);
                    }
                }
            }

            unset($request['cookies']);

            $url = (string) ($request['url'] ?? '');
            if (str_contains($url, '/api/conversions')) {
                unset($request['data']);
            }

            if (isset($request['query_string']) && is_string($request['query_string'])
                && preg_match(self::SENSITIVE_KEY, $request['query_string']) === 1) {
                $request['query_string'] = self::FILTERED;
            }

            $event->setRequest(self::scrubArray($request));
        }

        $event->setExtra(self::scrubArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrubArray($context));
        }

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $crumb): Breadcrumb => self::scrubBreadcrumb($crumb),
            $event->getBreadcrumbs(),
        ));

        return $event;
    }

    public static function scrubBreadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        foreach (self::scrubArray($crumb->getMetadata()) as $key => $value) {
            $crumb = $crumb->withMetadata((string) $key, $value);
        }

        return $crumb;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $data[$key] = self::FILTERED;
            } elseif (is_array($value)) {
                $data[$key] = self::scrubArray($value);
            }
        }

        return $data;
    }
}
