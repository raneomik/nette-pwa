# Nette PWA Extension

[![.github/workflows/ci.yml](https://github.com/raneomik/nette-pwa/actions/workflows/ci.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-pwa/actions/workflows/ci.yml)
[![.github/workflows/coverage.yml](https://github.com/raneomik/nette-pwa/actions/workflows/coverage.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-pwa/actions/workflows/coverage.yml)
[![codecov](https://codecov.io/gh/raneomik/nette-pwa/graph/badge.svg?token=Bc23JJTFL0&style=flat-square)](https://codecov.io/gh/raneomik/nette-pwa)
[![Mutation Testing](https://github.com/raneomik/nette-pwa/actions/workflows/mutation-test.yml/badge.svg)](https://github.com/raneomik/nette-pwa/actions/workflows/mutation-test.yml)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Franeomik%2Fnette-pwa%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/raneomik/nette-pwa/main)

*Work In Progress*

📲 Nette extension for Progressive Web Apps:
the web app manifest written in NEON, a service worker, and Web Push notifications (optional) with
[minishlink/web-push](https://github.com/web-push-libs/web-push-php).

## Requirements

- PHP 8.3 or newer
- `nette/application` 3.2 or newer, with a `RouteList` router
- Optional, suggested during install:

| Package | For |
|---------|-----|
| `latte/latte` | `{pwaHead()}` |
| `nette/assets` | versioned icon URLs (`src: 'img:icon'`) |
| `minishlink/web-push`, `symfony/http-client`, `nyholm/psr7` | Web Push (`ext-gmp` strongly advised) |
| `nette/database` | default subscription storage |
| `contributte/translation` | a subscription keeps the locale of its page |
| `tracy/tracy` | debug panel |

## Getting started

```bash
composer require raneomik/nette-pwa
```

Register the extension and describe the manifest:

```neon
extensions:
    pwa: Raneomik\NettePWA\Bridge\DI\PWAExtension

pwa:
    manifest:
        name: My app
        shortName: App
        backgroundColor: '#11161c'
        themeColor: '#11161c'
        icons:
            - {src: 'img:icon', sizes: any, type: image/svg+xml} # Nette Assets ref, or '/icon.svg'
```

Link it from your layout:

```latte
<head>
    {pwaHead()}
</head>
```

The manifest is now served at `/manifest.webmanifest` & the service worker at `/sw.js`.

### Enable Web Push

```bash
composer require minishlink/web-push symfony/http-client nyholm/psr7
php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```

```neon
pwa:
    push:
        vapid:
            publicKey: %vapidPublicKey%   # dynamic parameter; empty = push off, opt-in hidden
            privateKey: %vapidPrivateKey%
```

Then:

1. declare a [`TopicAuthorizer`](#authorize-topics) service (required);
2. create the [subscriptions table](#store-subscriptions);
3. add an opt-in button and the client script:

```latte
<button type="button" data-pwa-push="player/{$user->id} room/{$room}" data-pwa-on="🔔 On">🔔 Notify me</button>
```

*quickstart on dev :*
```js
import '../vendor/raneomik/nette-pwa/assets/push-client.js';
```

*prefer copy-paste-adapt :*

```shell
cp vendor/raneomik/nette-pwa/assets/push-client.js assets/js/
```

The button is a toggle: clicked again, it turns notifications off (`aria-pressed="true"` while on).

### Send a notification

Topics work as with Mercure: a browser subscribes to topics, you send to a topic without knowing who listens.

```php
use Raneomik\NettePWA\Push\Notification;

$failures = $pushSender->send("player/{$id}", new Notification('Bob joined', 'Time to play!', url: $link));
$pushSender->send(['room/abc', 'room/def'], $notification); // each browser once
```

A browser subscribes in the locale of its page: `<html lang>` when `contributte/translation` knows it, the translator's
current locale otherwise, `en` without a translator.
Pass a closure to send each one in its language: it is called once per locale of the listeners.

```php
$pushSender->send("player/{$id}", fn (string $locale) => new Notification(
    $translator->translate('game.joined', ['name' => 'Bob'], locale: $locale),
));
```

Expired subscriptions (404/410) are removed; the other failures are returned for you to log.

### Authorize topics

The compilation fails without a `TopicAuthorizer` service: "allow all" would let anyone receive anyone's pushes.

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

### Store subscriptions

Table of the default storage (`nette/database`):

```sql
CREATE TABLE push_subscriptions (endpoint TEXT NOT NULL, topic TEXT NOT NULL, locale varchar(64) NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL, createdAt INTEGER NOT NULL, PRIMARY KEY (endpoint, topic));
CREATE INDEX push_subscriptions_topic ON push_subscriptions (topic);
```

Your own `Raneomik\NettePWA\Push\SubscriptionStorage` service replaces it.

### Customize the service worker

Copy `assets/sw.js`, add what you need (offline cache...), keep its `push`, `pushsubscriptionchange` and
`notificationclick` listeners:

```neon
pwa:
    serviceWorker: %appDir%/../assets/sw.js
```

### Test locally

- `sw.js` shows nothing when a **focused** tab already shows the notification's URL: switch tabs or minimize the window.
- The service worker only registers on a certificate the browser trusts: import the local CA (Caddy's, mkcert's)
  in the system store, and in Firefox's own store (or set `security.enterprise_roots.enabled`).
- Notifications must be allowed for the browser by the OS (macOS Focus, Windows Focus assist).
- DevTools > Application > Service Workers > *Push* (data `{"title": "Test"}`) shows one without the server:
  if it appears, look at the failures returned by `send()`.
- Safari rejects a `localhost` VAPID subject: set `subject: 'mailto:...'`.
- On iOS, Web Push works only in the installed app (Add to Home Screen).

## Reference

### Configuration

| Option | Default | Description |
|--------|---------|-------------|
| `manifest.name` | required | |
| `manifest.shortName`, `description`, `id`, `orientation`, `lang`, `backgroundColor`, `themeColor` | – | |
| `manifest.startUrl`, `manifest.scope` | `/` | |
| `manifest.display` | `standalone` | `fullscreen`, `standalone`, `minimal-ui`, `browser` |
| `manifest.icons[]` | – | `src` (required: Nette Assets ref or URL), `sizes`, `type`, `purpose` |
| `manifest.extra` | – | any other manifest member, as is (`shortcuts`, `screenshots`...) |
| `serviceWorker` | package `assets/sw.js` | file served at `/sw.js` |
| `push` | `null` | no push services, no subscribe route |
| `push.vapid.publicKey`, `privateKey` | required | dynamic parameters allowed |
| `push.vapid.subject` | `%baseUrl%` | `mailto:` or `https://` |
| `push.ttl` | `2419200` | seconds the push service keeps a push for an offline device |
| `push.urgency` | – | `very-low`, `low`, `normal`, `high` |
| `push.timeout` | `3.0` | seconds, per push service call |
| `push.table` | `push_subscriptions` | default storage only |
| `push.pushServices` | FCM, Mozilla, Apple, Windows | endpoint hosts accepted |
| `push.subscribeAtPath` | `pwa/push/subscribe` | relative to the base path |
| `debugger` | `%debugMode%` | Tracy panel |

### Routes

Prepended to the app's `RouteList`. The manifest and the service worker are served with `Cache-Control: no-cache` + `ETag`.

<table>
  <tr><th>Route</th><th>Method</th><th></th></tr>
  <tr><td><code>/manifest.webmanifest</code></td><td><code>GET</code></td><td></td></tr>
  <tr><td><code>/sw.js</code></td><td><code>GET</code></td><td></td></tr>
  <tr><td rowspan="3"><code>/pwa/push/subscribe</code></td><td><code>POST</code></td><td>subscribe to topics</td></tr>
  <tr><td><code>PUT</code></td><td>subscription rotated by the browser: topics move to the new endpoint</td></tr>
  <tr><td><code>DELETE</code></td><td>unsubscribe</td></tr>
</table>

### Latte

`{pwaHead()}`: manifest link, `theme-color`, and the `<meta>` tags read by `push-client.js`.

### Button attributes (`push-client.js`)

| Attribute | Value |
|-----------|-------|
| `data-pwa-push` | topics, space separated; the browser listens to those of all the buttons of the page (20 at most, 255 characters each) |
| `data-pwa-on` | label while on (default `🔔 Notifications on`) |
| `data-pwa-off` | label while off (default: the button's own text) |
| `data-pwa-blocked` | label when the permission is denied (default `🔕 Notifications blocked`) |
| `data-pwa-error` | label when the subscription fails (default `🔕 Notifications unavailable`) |
| `data-pwa-install` | label on iOS outside the installed app (default `📲 Add to Home Screen to get notifications`) |

The script sets `aria-pressed` (`true` while on) and `disabled` (blocked, error, install). Without VAPID key or browser
support, the buttons are hidden.

### PHP

| Service | |
|---------|---|
| `Manifest\ManifestProvider` | `->manifest`: typed, read-only `Manifest\Value\Manifest` (`Icon`, `Display` enum) |
| `Push\PushSender` | `send(string\|array $topics, Notification\|Closure $notification): array` of failures (`MessageSentReport`) |
| `Push\Notification` | `title`, `body`, `url` (opened on click), `tag` (replaces a previous one, default: the url) |
| `Push\TopicAuthorizer` | `isAllowed(string $topic): bool`, yours to implement |
| `Push\SubscriptionStorage` | `save()`, `find()`, `remove()`, `replace()` |

```php
$manifest = $manifestProvider->manifest;
$manifest->themeColor;          // ?string
$manifest->display;             // Display::Standalone
$manifest->icons[0]->src;       // 'img:icon', as configured (the JSON gets the asset URL)
$manifest->extra['categories']; // the extra members stay raw
```

## How it works

- The manifest and the service worker are served by PHP: no file to dump, never stale after a deploy.
  The browser gets a 304 while nothing changed.
- Unlike Mercure, the browser is not connected: its subscription (endpoint + keys) is stored, it is the only way
  to reach it later.
- A visitor who turns notifications off stays off on the next pages, even though the browser permission is still granted.
- When the browser rotates a subscription (mostly Firefox), `sw.js` subscribes again and the server moves its topics
  to the new endpoint.

## Debugging

In debug mode, a **PWA** [<img src="https://tracy.nette.org/favicon.ico" width="13"> Tracy](https://tracy.nette.org) panel
shows what the browser DevTools cannot:

- **Manifest**: install criteria (display, start_url within scope, an icon of 144x144 or more), the icon files
  (found, actual size matching `sizes`), and recommendations (`shortName`, maskable icon, `themeColor`, `backgroundColor`, `id`);
- **Service worker**: secure context, the file served, and the push listeners a custom file must keep;
- **Push**: the VAPID subject and every `PushSender::send()` of the request: topics, notification, recipients, delivered,
  expired, failures with the push service status, duration. Endpoints are never shown, only the push service host.

The tab counts the pushes sent and, in red, the errors. Outside debug mode, nothing is recorded.

## Development

```bash
composer install && composer ci
```

## Resources

- [<img src="https://nette.org/favicon.ico" width="13"> Nette](https://nette.org),
  [<img src="https://latte.nette.org/favicon.ico" width="13"> Latte](https://latte.nette.org),
  [<img src="https://tracy.nette.org/favicon.ico" width="13"> Tracy](https://tracy.nette.org)
- [minishlink/web-push](https://github.com/web-push-libs/web-push-php)
- [PWA community logo](https://github.com/webmaxru/progressive-web-apps-logo) (CC0), used by the panel tab

## License

[MIT](licence)
