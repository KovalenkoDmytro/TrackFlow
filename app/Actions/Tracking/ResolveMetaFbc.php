<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Data\TrackingEventData;
use App\Models\TrackingEvent;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Recovers the Meta click id (fbc) for events that carry an fbp but lost the fbc.
 *
 * The click id only arrives on the landing page; later events of the same browser
 * often have none. The most recent non-empty fbc stored for the same shop and the
 * same fbp (same browser) within the lookback window is used. The result is applied
 * to the Meta payload only — stored history is never rewritten, and other shops'
 * events are never consulted.
 */
final class ResolveMetaFbc
{
    use AsObject;

    /**
     * Return the event to send to Meta: unchanged when it already has an fbc or no
     * fbp, or when nothing matches; otherwise a copy carrying the recovered fbc.
     */
    public function handle(TrackingEventData $data, int $shopId): TrackingEventData
    {
        if ($data->fbc !== null && $data->fbc !== '') {
            return $data;
        }

        if ($data->fbp === null || $data->fbp === '') {
            return $data;
        }

        $days = max(0, (int) config('tracking.fbc_lookback_days', 7));

        $fbc = TrackingEvent::query()
            ->where('user_id', $shopId)
            ->where('fbp', $data->fbp)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('fbc')
            ->where('fbc', '<>', '')
            ->latest('created_at')
            ->value('fbc');

        return is_string($fbc) && $fbc !== '' ? $data->withFbc($fbc) : $data;
    }
}
