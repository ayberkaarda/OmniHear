<?php

namespace App\Events;

use App\Support\Ai\AnalysisResult;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One feedback finished analysis (spec 6.5).
 *
 * Broadcast on `private-company.{id}` so the Inbox and the Overview KPIs update
 * without polling. Authorization for that channel lives in routes/channels.php
 * and is invariant I1 on the websocket surface: a socket belonging to company A
 * must not be able to subscribe to company B's channel.
 *
 * The payload is the analysis summary rather than the feedback body: the body
 * is customer PII and the client already has it (or can fetch it through
 * GET /api/v1/feedbacks/{id}), so putting it on a fan-out channel would widen
 * its exposure for nothing.
 */
final class FeedbackAnalyzed implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly int $feedbackId,
        public readonly string $sentimentLabel,
        public readonly float $sentimentScore,
        public readonly string $category,
        public readonly string $modelVersion,
    ) {}

    public static function fromResult(int $companyId, int $feedbackId, AnalysisResult $result): self
    {
        return new self(
            companyId: $companyId,
            feedbackId: $feedbackId,
            sentimentLabel: $result->sentimentLabel,
            sentimentScore: $result->sentimentScore,
            category: $result->category,
            modelVersion: $result->modelVersion,
        );
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->companyId)];
    }

    public function broadcastAs(): string
    {
        return 'feedback.analyzed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'feedback_id' => $this->feedbackId,
            'sentiment_label' => $this->sentimentLabel,
            'sentiment_score' => $this->sentimentScore,
            'category' => $this->category,
            'model_version' => $this->modelVersion,
        ];
    }
}
