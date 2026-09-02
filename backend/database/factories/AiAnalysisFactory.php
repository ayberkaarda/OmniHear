<?php

namespace Database\Factories;

use App\Models\AiAnalysis;
use App\Models\Company;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAnalysis>
 */
class AiAnalysisFactory extends Factory
{
    protected $model = AiAnalysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'feedback_id' => fn (array $attributes) => Feedback::factory()->state([
                'company_id' => $attributes['company_id'],
            ]),
            'sentiment_score' => fake()->randomFloat(3, -1, 1),
            'sentiment_label' => fake()->randomElement(AiAnalysis::SENTIMENT_LABELS),
            'category' => fake()->randomElement(AiAnalysis::CATEGORIES),
            'confidence' => fake()->randomFloat(3, 0, 1),
            'keywords' => ['latency', 'crash'],
            'model_version' => 'stub-0.1.0',
            'analyzed_at' => now(),
        ];
    }
}
