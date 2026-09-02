<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email half of the soft warning at 80% usage (spec 7.3). The in-app half
 * is App\Events\QuotaThresholdReached, broadcast on the company's private
 * channel.
 *
 * Queued, because it is raised from inside AnalyzeFeedbackJob: an SMTP round
 * trip must not sit between a successful analysis and the next one.
 */
class QuotaWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly string $companyName,
    ) {}

    public static function forCompany(Company $company, int $used, int $limit): self
    {
        return new self($used, $limit, $company->name);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $percent = $this->limit > 0 ? (int) floor($this->used / $this->limit * 100) : 100;

        return (new MailMessage)
            ->subject(__('quota.warning.subject', ['percent' => $percent]))
            ->greeting(__('quota.warning.greeting', ['name' => $notifiable->name]))
            ->line(__('quota.warning.line', [
                'company' => $this->companyName,
                'used' => $this->used,
                'limit' => $this->limit,
            ]))
            ->line(__('quota.warning.line_remaining'))
            ->action(__('quota.warning.action'), rtrim((string) config('app.frontend_url'), '/').'/billing')
            ->salutation(__('quota.warning.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'company' => $this->companyName,
        ];
    }
}
