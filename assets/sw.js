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

        await self.registration.showNotification(title ?? '', {body, tag: tag ?? url, data: {url}});
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
