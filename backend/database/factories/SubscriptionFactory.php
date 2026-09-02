<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_'.fake()->unique()->lexify('??????????'),
            'plan' => 'pro',
            'status' => 'active',
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addMonth(),
            'canceled_at' => null,
        ];
    }
}
