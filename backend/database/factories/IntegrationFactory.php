<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'platform' => 'fixture',
            'credentials' => ['api_key' => 'test-'.fake()->unique()->lexify('????????')],
            'settings' => ['locale' => 'en'],
            'status' => 'active',
            'last_synced_at' => null,
            'sync_cursor' => null,
            'sync_error' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'paused']);
    }

    public function errored(string $message = 'Upstream returned 500.'): static
    {
        return $this->state(fn () => [
            'status' => 'error',
            'sync_error' => $message,
        ]);
    }
}
