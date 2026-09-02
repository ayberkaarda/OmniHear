<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The in-app half of the soft warning at 80% usage (spec 7.3); the email half
 * is App\Notifications\QuotaWarningNotification.
 *
 * Fired exactly once, by the single reservation whose RETURNING value crosses
 * the line - see App\Support\Quota\QuotaSnapshot::crossedWarningThreshold().
 *
 * "In-app" is delivered over the same private company channel as
 * FeedbackAnalyzed rather than through a stored notification, because the
 * schema for this wave carries no notifications table and migrations are
 * frozen. A banner driven off the live channel plus the email is the part of
 * spec 7.3 that can be honoured without a schema change; a persisted
 * notification centre is noted as follow-up work.
 */
final class QuotaThresholdReached implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly int $used,
        public readonly int $limit,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->companyId)];
    }

    public function broadcastAs(): string
    {
        return 'quota.threshold-reached';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => max(0, $this->limit - $this->used),
        ];
    }
}
