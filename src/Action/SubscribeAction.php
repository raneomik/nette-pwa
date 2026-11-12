<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Action;

use Minishlink\WebPush\Subscription;
use Nette\Application\BadRequestException;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Raneomik\NettePWA\Action\Value\SubscriptionInput;
use Raneomik\NettePWA\Action\Value\TopicsInput;
use Raneomik\NettePWA\Bridge\Translator\LocaleResolver;
use Raneomik\NettePWA\Push\PushServices;
use Raneomik\NettePWA\Push\SubscriptionStorage;
use Raneomik\NettePWA\Push\TopicAuthorizer;

/**
 * /pwa/push/subscribe (route callback):
 * - POST   {"subscription": <PushSubscription.toJSON()>, "topics": ["player/42", ...], "locale": "fr"} from assets/push-client.js
 * - DELETE {"endpoint": "https://..."} from assets/push-client.js, when the visitor turns notifications off
 * - PUT    {"oldEndpoint": "https://...", "subscription": <...>} from assets/sw.js, when the browser rotates it
 *
 * An endpoint is an unguessable capability URL: knowing it is what allows to remove or rotate it,
 * as the push service itself does.
 */
final readonly class SubscribeAction
{
    public const string DefaultPath = 'pwa/push/subscribe';

    public function __construct(
        private IRequest $request,
        private TopicAuthorizer $authorizer,
        private PushServices $pushServices,
        private SubscriptionStorage $storage,
        private LocaleResolver $localeResolver,
    ) {
    }

    public function __invoke(): Response
    {
        // X-Requested-With: a cross-site form cannot set it; the session cookie (SameSite=Lax) is not sent anyway
        if (! $this->request->isAjax()) {
            throw new BadRequestException('Forbidden', IResponse::S403_Forbidden);
        }

        return match ($this->request->getMethod()) {
            IRequest::Post => $this->subscribe($this->body()),
            IRequest::Delete => $this->unsubscribe($this->body()),
            IRequest::Put => $this->rotate($this->body()),
            default => throw new BadRequestException('Forbidden', IResponse::S403_Forbidden),
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function subscribe(array $data): Response
    {
        $subscription = $this->subscription($data['subscription'] ?? null);

        try {
            $topicsFilter = fn (mixed $topic): bool => \is_string($topic)
                && '' !== $topic
                && 255 >= \strlen($topic)
                && $this->authorizer->isAllowed($topic);

            $topics = TopicsInput::fromInput($data['topics'] ?? false, $topicsFilter);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            throw new BadRequestException(
                $invalidArgumentException->getMessage(),
                IResponse::S403_Forbidden,
                $invalidArgumentException,
            );
        }

        $this->storage->save(
            $subscription,
            $topics->values,
            $this->localeResolver->getLocale(
                is_string($locale = $data['locale'] ?? null)
                    ? $locale
                    : null
            ),
        );

        return new JsonResponse($topics);
    }

    /**
     * @param array<mixed> $data
     */
    private function unsubscribe(array $data): Response
    {
        // idempotent: an endpoint already removed (expired, other tab) is not an error
        $this->storage->remove($this->endpoint($data['endpoint'] ?? null));

        return new VoidResponse();
    }

    /**
     * @param array<mixed> $data
     */
    private function rotate(array $data): Response
    {
        $oldEndpoint = $this->endpoint($data['oldEndpoint'] ?? null);
        $subscription = $this->subscription($data['subscription'] ?? null);

        // the topics are kept: they were authorized when the old endpoint subscribed
        if (! $this->storage->replace($oldEndpoint, $subscription)) {
            throw new BadRequestException('Unknown subscription', IResponse::S404_NotFound);
        }

        return new VoidResponse();
    }

    /**
     * @return array<mixed>
     */
    private function body(): array
    {
        try {
            $data = Json::decode((string) $this->request->getRawBody(), forceArrays: true);
        } catch (JsonException) {
            throw new BadRequestException('Invalid JSON', IResponse::S400_BadRequest);
        }

        if (! \is_array($data)) {
            throw new BadRequestException('Invalid JSON', IResponse::S400_BadRequest);
        }

        return $data;
    }

    private function subscription(mixed $input): Subscription
    {
        try {
            $subscriptionInput = SubscriptionInput::fromInput(\is_array($input) ? $input : false);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            throw new BadRequestException($invalidArgumentException->getMessage(), IResponse::S400_BadRequest, $invalidArgumentException);
        }

        $this->endpoint($subscriptionInput->endpoint);

        return new Subscription($subscriptionInput->endpoint, $subscriptionInput->p256dh, $subscriptionInput->auth);
    }

    private function endpoint(mixed $endpoint): string
    {
        if (! \is_string($endpoint) || ! $this->pushServices->accepts($endpoint)) {
            throw new BadRequestException('Invalid subscription endpoint', IResponse::S400_BadRequest);
        }

        return $endpoint;
    }
}
