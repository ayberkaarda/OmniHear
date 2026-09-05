<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The validated shape of one `/v1/analyze` result (spec 6.4).
 *
 * Nothing from the analyzer reaches the database without passing through
 * `fromResponse()` first. The rules below are the Laravel-side half of the
 * contract in contracts/ai-openapi.json; the fixtures in
 * contracts/fixtures/analyze/ are what proves the two halves agree, and
 * tests/Feature/Contract/AnalyzeContractTest.php feeds every one of them
 * through this class.
 *
 * The enums must stay identical to ai-service/app/schemas.py and to
 * App\Models\AiAnalysis. Changing one of the three is a contract change; see
 * docs/playbooks/ai-contract-sync.
 */
final class AnalysisResult
{
    /** @var list<string> */
    public const SENTIMENT_LABELS = ['positive', 'neutral', 'negative'];

    /** @var list<string> */
    public const CATEGORIES = ['complaint', 'praise', 'bug', 'feature_request'];

    /**
     * The analyzer caps keywords at 10 (schemas.py: `Field(max_length=10)`).
     */
    public const MAX_KEYWORDS = 10;

    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly float $sentimentScore,
        public readonly string $sentimentLabel,
        public readonly string $category,
        public readonly float $confidence,
        public readonly array $keywords,
        public readonly string $language,
        public readonly string $modelVersion,
        public readonly ?string $correlationId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'sentiment_score' => ['required', 'numeric', 'between:-1,1'],
            'sentiment_label' => ['required', 'string', Rule::in(self::SENTIMENT_LABELS)],
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'keywords' => ['present', 'array', 'max:'.self::MAX_KEYWORDS],
            'keywords.*' => ['string'],
            // ISO 639-1, exactly two characters (schemas.py: min_length=max_length=2).
            'language' => ['required', 'string', 'size:2'],
            // ai_analyses.model_version is varchar(50).
            'model_version' => ['required', 'string', 'min:1', 'max:50'],
            'correlation_id' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Validate an analyzer response body and build the DTO.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AiServiceUnavailableException when the body violates the contract
     */
    public static function fromResponse(array $payload): self
    {
        try {
            $data = Validator::make($payload, self::rules())->validate();
        } catch (ValidationException $e) {
            // Field names only - the values are model output over customer text.
            throw AiServiceUnavailableException::invalidResponse(array_keys($e->errors()));
        }

        return new self(
            sentimentScore: (float) $data['sentiment_score'],
            sentimentLabel: (string) $data['sentiment_label'],
            category: (string) $data['category'],
            confidence: (float) $data['confidence'],
            keywords: array_values(array_map('strval', $data['keywords'])),
            language: (string) $data['language'],
            modelVersion: (string) $data['model_version'],
            correlationId: isset($payload['correlation_id']) ? (string) $payload['correlation_id'] : null,
        );
    }

    /**
     * The `ai_analyses` column values. `analyzed_at` is stamped by the caller,
     * inside the same transaction that flips the feedback status.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'sentiment_score' => $this->sentimentScore,
            'sentiment_label' => $this->sentimentLabel,
            'category' => $this->category,
            'confidence' => $this->confidence,
            'keywords' => $this->keywords,
            'model_version' => $this->modelVersion,
        ];
    }
}
