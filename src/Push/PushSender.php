<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

use Minishlink\WebPush\MessageSentReport;
use Nette\Utils\Json;
use Raneomik\NettePWA\Push\Debug\SendLogger;

final readonly class PushSender
{
    /**
     * @param ?SendLogger $logger debug mode only (Tracy panel)
     */
    public function __construct(
        private WebPushFactory $webPushFactory,
        private SubscriptionStorage $storage,
        private ?SendLogger $logger = null,
    ) {
    }

    /**
     * @param string|list<string> $topics
     * @return list<MessageSentReport> failed deliveries
     */
    public function send(string|array $topics, Notification $notification): array
    {
        $start = hrtime(true);
        $topics = (array) $topics;
        $subscriptions = [] === $topics ? [] : [...$this->storage->findByTopics($topics)];

        $reports = [];
        if ([] !== $subscriptions) { // none either while VAPID keys are empty: no client could subscribe
            $webPush = $this->webPushFactory->create();
            $payload = Json::encode($notification);
            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                $reports[] = $report;
            }
        }

        // "nobody listens to these topics" is the first thing to know when nothing shows up
        $this->logger?->record($topics, $notification, \count($subscriptions), $reports, (hrtime(true) - $start) / 1e6);

        $failures = [];
        foreach ($reports as $report) {
            if ($report->isSubscriptionExpired()) {
                $this->storage->remove($report->getEndpoint());
                continue;
            }

            if (false === $report->isSuccess()) {
                $failures[] = $report;
            }
        }

        return $failures;
    }
}
