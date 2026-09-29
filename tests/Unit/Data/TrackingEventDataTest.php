<?php

declare(strict_types=1);

use App\Data\TrackingEventData;

describe('TrackingEventData::normalizeSourceUrl', function (): void {
    it('keeps https origin and path and drops query, fragment and credentials', function (string $input, ?string $expected): void {
        expect(TrackingEventData::normalizeSourceUrl($input))->toBe($expected);
    })->with([
        ['https://store.test/products/x?a=1#f', 'https://store.test/products/x'],
        ['https://store.test', 'https://store.test/'],
        ['https://store.test:8443/a', 'https://store.test:8443/a'],
        ['http://store.test/a', null],
        ['https://user:pass@store.test/a', null],
        ['javascript:alert(1)', null],
        ['not a url', null],
        ['https://store.test/checkouts/cn/TOKEN/thank-you', 'https://store.test/checkouts'],
        ['https://store.test/fr-ca/orders/TOKEN', 'https://store.test/fr-ca/orders'],
        ['https://store.test/account/orders/TOKEN', 'https://store.test/account'],
        ['https://store.test/collections/checkouts-sale', 'https://store.test/collections/checkouts-sale'],
    ]);

    it('normalizes at construction and returns null for null', function (): void {
        expect(TrackingEventData::normalizeSourceUrl(null))->toBeNull();
    });
});
