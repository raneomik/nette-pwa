<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push\Debug;

use Minishlink\WebPush\MessageSentReport;
use Raneomik\NettePWA\Push\Notification;

/**
 * What PushSender sent during the request: given to it only in debug mode, read by the Tracy panel.
 *
 * Kept short: in worker mode (FrankenPHP) the container, and so this log, outlives the request.
 * The panel drains it when rendered; a request without a bar (API, JSON) does not, hence the cap.
 */
final class SendLogger
{
    private const int Max = 50;

    /**
     * @var list<SentPush>
     */
    private array $sent = [];

    /**
     * @param list<string> $topics
     * @param list<MessageSentReport> $reports
     * @param list<string> $locales of the recipients; $notification is the one of the first
     */
    public function record(array $topics, Notification $notification, int $recipients, array $reports, float $milliseconds, array $locales = []): void
    {
        $expired = [];
        $failures = [];
        foreach ($reports as $report) {
            if ($report->isSubscriptionExpired()) {
                $expired[] = $this->service($report->getEndpoint());
            } elseif (! $report->isSuccess()) {
                $failures[] = [
                    'service' => $this->service($report->getEndpoint()),
                    'status' => $report->getResponse()?->getStatusCode(),
                    'reason' => $report->getReason(),
                ];
            }
        }

        $this->sent[] = new SentPush(
            $topics,
            $notification,
            $recipients,
            \count($reports) - \count($expired) - \count($failures),
            $expired,
            $failures,
            $milliseconds,
            $locales,
        );
        $this->sent = \array_slice($this->sent, -self::Max);
    }

    /**
     * @return list<SentPush>
     */
    public function drain(): array
    {
        [$sent, $this->sent] = [$this->sent, []];

        return $sent;
    }

    /**
     * the host only: an endpoint is a capability URL, it has no business in a debug bar
     */
    private function service(string $endpoint): string
    {
        return parse_url($endpoint, \PHP_URL_HOST) ?: 'unknown';
    }
}
