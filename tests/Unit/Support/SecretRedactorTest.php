<?php

declare(strict_types=1);

use App\Support\SecretRedactor;

it('masks credential query params but keeps the rest of the url', function (): void {
    $out = SecretRedactor::redact('cURL error 28 for https://www.google-analytics.com/mp/collect?measurement_id=G-ABC&api_secret=S3CR3T&access_token=tok123');

    expect($out)->toContain('measurement_id=G-ABC')
        ->and($out)->toContain('api_secret=[redacted]')
        ->and($out)->toContain('access_token=[redacted]')
        ->and($out)->not->toContain('S3CR3T')
        ->and($out)->not->toContain('tok123');
});

it('bounds output to the column length after redaction', function (): void {
    $out = SecretRedactor::bounded(str_repeat('x', 400).'?api_secret=S3CR3T');

    expect(mb_strlen($out))->toBe(255)->and($out)->not->toContain('S3CR3T');
});
