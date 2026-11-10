Nette PWA Extension
===================

[![.github/workflows/ci.yml](https://github.com/raneomik/nette-pwa/actions/workflows/ci.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-pwa/actions/workflows/ci.yml)
[![.github/workflows/coverage.yml](https://github.com/raneomik/nette-pwa/actions/workflows/coverage.yml/badge.svg?style=flat-square)](https://github.com/raneomik/nette-pwa/actions/workflows/coverage.yml)
[![codecov](https://codecov.io/gh/raneomik/nette-pwa/graph/badge.svg?token=Bc23JJTFL0&style=flat-square)](https://codecov.io/gh/raneomik/nette-pwa)
[![Mutation Testing](https://github.com/raneomik/nette-pwa/actions/workflows/mutation-test.yml/badge.svg)](https://github.com/raneomik/nette-pwa/actions/workflows/mutation-test.yml)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Franeomik%2Fnette-pwa%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/raneomik/nette-pwa/main)


*Work In Progress*

📲 Nette extension for Progressive Web Apps: the web app manifest written in NEON, a service worker,
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
        # startUrl: /  scope: /  display: standalone   # defaults; display: fullscreen | standalone | minimal-ui | browser
        extra:  # any other manifest member, as is (shortcuts, screenshots, display_override...)
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
        # subscribeAtPath: pwa-subscribe # default 'pwa/push/subscribe', relative to the base path (like /sw.js)
```

The routes are prepended to the app's `RouteList`, so a catch-all `<presenter>/<action>` does not
take them. They are served by PHP (no file to dump, never stale after a deploy) with
`Cache-Control: no-cache` + `ETag`: the browser gets a 304 while nothing changed.

The `manifest` section is validated at compile time and becomes a typed, read-only value object
(`Manifest\Value\Manifest`, with `Icon` and the `Display` enum). It is reachable from the
autowired `ManifestProvider`:

```php
$manifest = $manifestProvider->manifest;  // Raneomik\NettePWA\Manifest\Value\Manifest
$manifest->themeColor;                    // ?string
$manifest->display;                       // Display::Standalone
$manifest->icons[0]->src;                 // 'img:icon', as configured (the JSON gets the asset URL)
$manifest->extra['categories'];           // the extra members stay raw
```

```latte
<head>
    {pwaHead()}   {* manifest link, theme-color, and the <meta> tags read by push-client.js *}
</head>

{* the browser listens to these topics (all the buttons of the page, replacing a previous page's) *}
{* a toggle: clicked again, it turns notifications off (aria-pressed="true" while on) *}
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

A visitor who turns notifications off (`DELETE` on the subscribe route) stays off on the next pages,
even though the browser permission is still granted. When the browser rotates a subscription (mostly
Firefox), `sw.js` subscribes again and the server moves its topics to the new endpoint (`PUT`).

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

Default storage (`nette/database`; your own `SubscriptionStorage` service replaces it, it must implement
`save()`, `findByTopics()`, `remove()` and `replace()`), table to create:

```sql
CREATE TABLE push_subscriptions (endpoint TEXT NOT NULL, topic TEXT NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL, createdAt INTEGER NOT NULL, PRIMARY KEY (endpoint, topic));
CREATE INDEX push_subscriptions_topic ON push_subscriptions (topic);
```

### Tracy panel

In debug mode, a **PWA** panel shows what the browser DevTools cannot:

- **Manifest**: install criteria (display, start_url within scope, an icon of 144x144 or more), the icon
  files themselves (found, actual size matching `sizes`), and recommendations (`shortName`, maskable icon,
  `themeColor`, `backgroundColor`, `id`);
- **Service worker**: secure context (HTTPS, or localhost), the file served, and the push listeners a custom
  `serviceWorker` file must keep;
- **Push**: the VAPID subject (Apple refuses localhost) and every `PushSender::send()` of the request:
  topics, notification, recipients (0 = nobody listens to these topics), delivered, expired, failures with
  the push service status, duration. Endpoints are never shown, only the push service host.

The tab (the [PWA community logo](https://github.com/webmaxru/progressive-web-apps-logo), CC0) counts the
pushes sent and, in red, the errors. `debugger: false` turns it off, `debugger: true` forces it; without
`debugger`, it follows `%debugMode%`. Outside debug mode, nothing is recorded.

### Testing locally

- `sw.js` shows nothing when a **focused** tab already shows the notification's URL: the app is expected to
  display it its own way (e.g. Mercure). Switch tabs or minimize the window before sending.
- The service worker only registers on a certificate the browser trusts: import the local CA (Caddy's, mkcert's)
  in the system store, and in Firefox's own store (or set `security.enterprise_roots.enabled`).
- Notifications must be allowed for the browser itself by the OS (macOS Focus, Windows Focus assist).
- DevTools > Application > Service Workers > *Push* (data `{"title": "Test"}`) shows one without the server:
  if it appears, look at the server side (the failures returned by `send()`).
- Safari rejects a `localhost` VAPID subject: set `subject: 'mailto:...'`.

On iOS, Web Push works only in the installed app (Add to Home Screen): `push-client.js` shows the
`data-pwa-install` label there.

## Tests

```bash
composer install && composer ci
```
