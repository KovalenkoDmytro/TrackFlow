<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical set of storefront event types that TrackFlow tracks and reports on.
 *
 * These string values are stored in tracking_events.event and must never change
 * once records exist in production — rename the label() only, not the case value.
 */
enum TrackingEventType: string
{
    case Purchase = 'purchase';
    case AddToCart = 'add_to_cart';
    case BeginCheckout = 'begin_checkout';
    case AddPaymentInfo = 'add_payment_info';
    case AddShippingInfo = 'add_shipping_info';
    case ViewItem = 'view_item';
    case ViewCart = 'view_cart';
    case Search = 'search';
    case RemoveFromCart = 'remove_from_cart';

    /** Human-readable label for display in the analytics table. */
    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::AddToCart => 'Add to Cart',
            self::BeginCheckout => 'Begin Checkout',
            self::AddPaymentInfo => 'Add Payment Info',
            self::AddShippingInfo => 'Add Shipping Info',
            self::ViewItem => 'View Item',
            self::ViewCart => 'View Cart',
            self::Search => 'Search',
            self::RemoveFromCart => 'Remove from Cart',
        };
    }
}
