<?php

declare(strict_types=1);

namespace App\Enums;

enum SessionTokenFailure
{
    case Missing;
    case Invalid;
    case Expired;
    case ShopNotInstalled;

    public function code(): string
    {
        return match ($this) {
            self::Missing => 'session_token_missing',
            self::Invalid => 'session_token_invalid',
            self::Expired => 'session_token_expired',
            self::ShopNotInstalled => 'shop_not_installed',
        };
    }

    public function shouldRetry(): bool
    {
        return match ($this) {
            self::Missing, self::Invalid, self::Expired => true,
            self::ShopNotInstalled => false,
        };
    }
}
