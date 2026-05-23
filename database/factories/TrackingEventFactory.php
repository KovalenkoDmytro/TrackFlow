<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingEvent>
 */
class TrackingEventFactory extends Factory
{
    protected $model = TrackingEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => fake()->randomElement(TrackingEventType::cases())->value,
            'value' => fake()->randomFloat(2, 0, 500),
            'currency' => 'CAD',
            'transaction_id' => null,
            'gclid' => null,
            'fbp' => null,
            'fbc' => null,
            'ttclid' => null,
            'ga_client_id' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'idempotency_key' => fake()->uuid(),
            'occurred_at' => now(),
        ];
    }

    public function forEvent(TrackingEventType $type): static
    {
        return $this->state(['event' => $type->value]);
    }

    public function occurredAt(\DateTimeInterface|string $at): static
    {
        return $this->state(['occurred_at' => $at]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }
}
