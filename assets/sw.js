// Service worker served at /sw.js by ServiceWorkerAction; payload = Raneomik\NettePWA\Push\Notification.
// To extend it (offline cache...), copy it and set "pwa: serviceWorker: <path>".

self.addEventListener('push', (event) => {
    const {title, body, url, tag} = event.data?.json() ?? {};

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        // the page it points to is in front: the app shows it its own way (e.g. Mercure)
        if (url && windows.some((client) => client.focused && client.url === url)) {
            return;
        }

        // renotify: a notification replacing one with the same tag pops up again, instead of silently taking its place
        // in the notification center (Chrome refuses renotify without a tag)
        const replaces = tag ?? url;
        await self.registration.showNotification(title ?? '', {body, tag: replaces, renotify: Boolean(replaces), data: {url}});
    })());
});

// The browser (mostly Firefox) may rotate the subscription: the old endpoint stops working, without
// any page open to notice. Subscribe again and let the server move the topics to the new endpoint.
// The subscribe URL comes from the registration URL (sw.js?subscribe=...), set by push-client.js.
self.addEventListener('pushsubscriptionchange', (event) => {
    const subscribeUrl = new URL(self.location.href).searchParams.get('subscribe');
    const old = event.oldSubscription;
    if (!subscribeUrl || !old) {
        return; // nothing to move: the next visit with the permission granted subscribes again
    }

    event.waitUntil((async () => {
        const subscription = event.newSubscription ?? await self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: old.options?.applicationServerKey,
        });

        await fetch(subscribeUrl, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({oldEndpoint: old.endpoint, subscription}),
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/';

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        const tab = windows.find((client) => client.url === url);

        return tab ? tab.focus() : self.clients.openWindow(url);
    })());
});
