<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $attributes) => User::factory()->state([
                'company_id' => $attributes['company_id'],
            ]),
            'action' => 'auth.login',
            'subject_type' => null,
            'subject_id' => null,
            'ip' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }
}
