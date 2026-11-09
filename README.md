Nette PWA Extension
===================

[![.github/workflows/ci.yml](https://github.com/raneomik/nette-pwa/actions/workflows/ci.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-mercure/actions/workflows/ci.yml)
[![.github/workflows/coverage.yml](https://github.com/raneomik/nette-pwa/actions/workflows/coverage.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-mercure/actions/workflows/coverage.yml)
[![codecov](https://codecov.io/gh/raneomik/nette-pwa/graph/badge.svg?token=Bc23JJTFL0&style=flat-square)](https://codecov.io/gh/raneomik/nette-mercure)
[![Mutation Testing](https://github.com/raneomik/nette-pwa/actions/workflows/mutation-test.yml/badge.svg)](https://github.com/raneomik/nette-mercure/actions/workflows/mutation-test.yml)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Franeomik%2Fnette-pwa%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/raneomik/nette-pwa/main)


*Work In Progress*

🔔 Nette extension for Progressive Web Apps: the web app manifest written in NEON, a service worker,
and Web Push notifications (optional) with [minishlink/web-push](https://github.com/web-push-libs/web-push-php).

## Configuration

```neon
pwa:
    manifest: # served at /manifest.webmanifest
        name: My app
        shortName: App
        backgroundColor: '#11161c'
        themeColor: '#11161c'
        icons:
            - {src: 'img:icon', sizes: any, type: image/svg+xml}  # Nette Assets ref = versioned URL, or '/icon.svg'
        extra:  # any other manifest member, as is
            categories: [games]

    # serviceWorker: %appDir%/../assets/sw.js   # your own copy of assets/sw.js (offline cache...), served at /sw.js

    push: # optional: Web Push + POST /pwa/push/subscribe
        vapid:
            publicKey: %vapidPublicKey%   # dynamic parameter (getenv); empty = push off, opt-in hidden
            privateKey: %vapidPrivateKey%
            # subject: 'mailto:admin@example.com'  # default %baseUrl%
        # ttl: 2419200
        # urgency: normal
        # timeout: 3.0
        # table: push_subscriptions
        # pushServices: [...]       # endpoint hosts accepted (default: FCM, Mozilla, Apple, Windows)
        # subscribeAtPath: '/pwa-subscribe' # default '/pwa/push/subscribe'
```

The routes are prepended to the app's `RouteList`, so a catch-all `<presenter>/<action>` does not
take them. They are served by PHP (no file to dump, never stale after a deploy) with
`Cache-Control: no-cache` + `ETag`: the browser gets a 304 while nothing changed.

```latte
<head>
    {pwaHead()}   {* manifest link, theme-color, and the <meta> tags read by push-client.js *}
</head>

{* the browser listens to these topics (all the buttons of the page, replacing a previous page's) *}
<button type="button" data-pwa-push="player/{$user->id} room/{$room}" data-pwa-on="🔔 On">🔔 Notify me</button>
```

```js
import '../vendor/raneomik/nette-pwa/assets/push-client.js';
```

## Web Push

```bash
composer require minishlink/web-push symfony/http-client nyholm/psr7   # + ext-gmp, strongly advised
php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```

Topics work as with Mercure: a browser subscribes to topics, you send to a topic without knowing
who listens. Unlike Mercure, the browser is not connected: its subscription (endpoint + keys) is
stored, it is the only way to reach it later.

```php
$failures = $pushSender->send("player/{$id}", new Notification('Bob joined', 'Time to play!', url: $link));
$pushSender->send(['room/abc', 'room/def'], $notification); // each browser once
```

Expired subscriptions (404/410) are removed; the other failures are returned for you to log.

**Required**: a `TopicAuthorizer` service, which topics the visitor may subscribe to (the compilation
fails without one: "allow all" would let anyone receive anyone's pushes). Usually the same rule as
your Mercure subscriber authorization:

```php
final class TopicAccess implements Raneomik\NettePWA\Push\TopicAuthorizer
{
    public function __construct(private Nette\Security\User $user) {}

    public function isAllowed(string $topic): bool
    {
        return str_starts_with($topic, 'room/') || $topic === "player/{$this->user->getId()}";
    }
}
```

Default storage (`nette/database`; your own `SubscriptionStorage` service replaces it), table to create:

```sql
CREATE TABLE push_subscriptions (endpoint TEXT NOT NULL, topic TEXT NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL, createdAt INTEGER NOT NULL, PRIMARY KEY (endpoint, topic));
CREATE INDEX push_subscriptions_topic ON push_subscriptions (topic);
```

On iOS, Web Push works only in the installed app (Add to Home Screen): `push-client.js` shows the
`data-pwa-install` label there.

## Tests

```bash
composer install && composer ci
```
