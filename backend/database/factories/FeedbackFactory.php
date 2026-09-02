<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // Resolved after company_id, so the integration lands in the same tenant.
            'integration_id' => fn (array $attributes) => Integration::factory()->state([
                'company_id' => $attributes['company_id'],
            ]),
            'external_id' => fake()->unique()->uuid(),
            'author' => fake()->name(),
            'body' => fake()->paragraph(),
            'source_url' => fake()->url(),
            'published_at' => now()->subHours(2),
            'raw_payload' => ['source' => 'factory'],
            'analysis_status' => Feedback::STATUS_PENDING,
        ];
    }

    public function analyzed(): static
    {
        return $this->state(fn () => ['analysis_status' => Feedback::STATUS_ANALYZED]);
    }
}
