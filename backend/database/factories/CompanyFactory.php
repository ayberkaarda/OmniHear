<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'plan' => 'free',
            'analyzed_feedback_count' => 0,
            'quota_limit' => (int) config('quota.plans.free.quota_limit'),
        ];
    }

    public function pro(): static
    {
        return $this->state(fn () => ['plan' => 'pro']);
    }

    public function quotaExhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'analyzed_feedback_count' => $attributes['quota_limit'],
        ]);
    }
}
