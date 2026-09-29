<?php

declare(strict_types=1);

use App\Support\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;

it('removes sensitive headers, conversion bodies and secret-like keys', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/conversions',
        'headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'a=b', 'Accept' => 'json'],
        'cookies' => ['a' => 'b'],
        'data' => ['gclid' => 'abc'],
    ]);
    $event->setExtra(['shopify_access_token' => 't', 'nested' => ['client_secret' => 's', 'ok' => 1]]);
    $event->setContext('app', ['Password' => 'p', 'gclid' => 'g', 'name' => 'n']);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'http', 'm', ['access_token' => 'x', 'status' => 200])]);

    $event = SentryScrubber::scrub($event);

    expect($event->getRequest())->not->toHaveKeys(['data', 'cookies'])
        ->and($event->getRequest()['headers'])->toBe(['Accept' => 'json'])
        ->and($event->getExtra()['shopify_access_token'])->toBe('[Filtered]')
        ->and($event->getExtra()['nested'])->toBe(['client_secret' => '[Filtered]', 'ok' => 1])
        ->and($event->getContexts()['app'])->toBe(['Password' => '[Filtered]', 'gclid' => '[Filtered]', 'name' => 'n'])
        ->and($event->getBreadcrumbs()[0]->getMetadata())->toBe(['access_token' => '[Filtered]', 'status' => 200]);
});

it('boots with an empty sentry dsn and scrubber wired in', function (): void {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.traces_sample_rate'))->toBe(0.0)
        ->and(config('sentry.before_send'))->toBe([SentryScrubber::class, 'scrub']);
    $this->get('/up')->assertOk();
});
