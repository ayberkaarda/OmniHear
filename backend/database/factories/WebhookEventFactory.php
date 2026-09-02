<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'event_id' => 'evt_'.fake()->unique()->lexify('??????????'),
            'payload' => ['type' => 'checkout.session.completed'],
            'processed_at' => null,
        ];
    }
}
