<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Action;

use Minishlink\WebPush\Subscription;
use Nette\Application\BadRequestException;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Raneomik\NettePWA\Action\Value\SubscriptionInput;
use Raneomik\NettePWA\Action\Value\TopicsInput;
use Raneomik\NettePWA\Push\PushServices;
use Raneomik\NettePWA\Push\SubscriptionStorage;
use Raneomik\NettePWA\Push\TopicAuthorizer;

/**
 * POST /pwa/push/subscribe (route callback), from assets/push-client.js:
 * {"subscription": <PushSubscription.toJSON()>, "topics": ["player/42", ...]}
 */
final readonly class SubscribeAction
{
    public function __construct(
        private IRequest $request,
        private TopicAuthorizer $authorizer,
        private PushServices $pushServices,
        private SubscriptionStorage $storage,
    ) {
    }

    public function __invoke(): Response
    {
        // X-Requested-With: a cross-site form cannot set it; the session cookie (SameSite=Lax) is not sent anyway
        if (! $this->request->isMethod('POST') || ! $this->request->isAjax()) {
            throw new BadRequestException('Forbidden', IResponse::S403_Forbidden);
        }

        try {
            $data = Json::decode((string) $this->request->getRawBody(), forceArrays: true);
        } catch (JsonException) {
            throw new BadRequestException('Invalid JSON', IResponse::S400_BadRequest);
        }

        try {
            $subscriptionInput = SubscriptionInput::fromInput($data['subscription'] ?? false);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            throw new BadRequestException($invalidArgumentException->getMessage(), IResponse::S400_BadRequest, $invalidArgumentException);
        }

        if (! $this->pushServices->accepts($subscriptionInput->endpoint)) {
            throw new BadRequestException('Invalid subscription endpoint', IResponse::S400_BadRequest);
        }

        try {
            $topics = TopicsInput::fromInput(
                $data['topics'] ?? false,
                fn (mixed $topic): bool => \is_string($topic)
                        && '' !== $topic
                        && 255 >= \strlen($topic)
                        && $this->authorizer->isAllowed($topic),
            );
        }catch (\InvalidArgumentException $invalidArgumentException){
            throw new BadRequestException($invalidArgumentException->getMessage(), IResponse::S403_Forbidden, $invalidArgumentException);
        }

        $this->storage->save(
            new Subscription($subscriptionInput->endpoint, $subscriptionInput->p256dh, $subscriptionInput->auth),
            $topics->values,
        );

        return new JsonResponse([
            'topics' => $topics->values,
        ]);
    }
}
