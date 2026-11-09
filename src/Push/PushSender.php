<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

use Minishlink\WebPush\MessageSentReport;
use Nette\Utils\Json;

final readonly class PushSender
{
    public function __construct(
        private WebPushFactory $webPushFactory,
        private SubscriptionStorage $storage,
    ) {
    }

    /**
     * @param string|list<string> $topics
     * @return list<MessageSentReport> failed deliveries
     */
    public function send(string|array $topics, Notification $notification): array
    {
        $topics = (array) $topics;
        $subscriptions = [] === $topics ? [] : [...$this->storage->findByTopics($topics)];
        if ([] === $subscriptions) { // also the case while VAPID keys are empty: no client could subscribe
            return [];
        }

        $webPush = $this->webPushFactory->create();
        $payload = Json::encode($notification);
        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification($subscription, $payload);
        }

        $failures = [];
        foreach ($webPush->flush() as $report) {
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
