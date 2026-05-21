<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use stdClass;

final class AppUninstalledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected readonly string $domain,
        protected readonly stdClass $data,
    ) {}

    public function handle(): void
    {
        $shopDomain = ShopDomain::fromNative($this->domain);

        $shop = User::query()
            ->where('name', $shopDomain->toNative())
            ->first();

        if (! $shop instanceof User) {
            return;
        }

        $shop->uninstalled_at = now();
        $shop->save();

        $shop->delete();
    }
}
