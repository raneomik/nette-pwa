<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

use Minishlink\WebPush\MessageSentReport;
use Nette\Utils\Json;
use Raneomik\NettePWA\Bridge\Translator\LocaleResolver;
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
     * @param Notification|\Closure(string $notification): Notification $notification a closure is called once per locale of the listeners
     * @return list<MessageSentReport> failed deliveries
     */
    public function send(string|array $topics, Notification|\Closure $notification): array
    {
        $start = hrtime(true);
        $topics = (array) $topics;

        $webPush = null;
        $logged = null;
        $locales = [];
        $recipients = 0;
        // no listener either while VAPID keys are empty: no client could subscribe
        foreach ([] === $topics ? [] : $this->storage->find($topics) as $locale => $subscriptions) {
            $webPush ??= $this->webPushFactory->create();
            $localized = $notification instanceof \Closure ? $notification((string) $locale) : $notification;
            $logged ??= $localized;
            $locales[] = $locale;
            $payload = Json::encode($localized);
            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification($subscription, $payload);
                ++$recipients;
            }
        }

        $reports = [];
        foreach ($webPush?->flush() ?? [] as $report) {
            $reports[] = $report;
        }

        if ($this->logger instanceof SendLogger) {
            $logged ??= $notification instanceof \Closure
                ? $notification(LocaleResolver::DefaultLocale)
                : $notification;
            $this->logger->record($topics, $logged, $recipients, $reports, (hrtime(true) - $start) / 1e6, $locales);
        }

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
