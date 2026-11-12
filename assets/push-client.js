// Web Push opt-in/opt-out: any [data-pwa-push="topic1 topic2"] button; reads the <meta> tags written by {pwaHead()}.
// The browser listens to the topics of all the buttons of the page (replacing those of a previous page).
// A button toggles: on (aria-pressed="true", data-pwa-on label) / off (its own label). A visitor who turned
// notifications off stays off on the next pages, even though the browser permission is still granted.
// Labels: data-pwa-on, data-pwa-blocked, data-pwa-error, data-pwa-install (iOS outside the installed app).

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

document.addEventListener('DOMContentLoaded', async () => {
    const buttons = [...document.querySelectorAll('[data-pwa-push]')];
    const key = meta('pwa-vapid-key');
    if (!buttons.length) {
        return;
    }
    if (!key || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        // navigator.standalone exists only on iOS: there, Web Push needs the installed app
        const install = 'standalone' in navigator && !navigator.standalone;
        buttons.forEach((button) => install && key ? disable(button, button.dataset.pwaInstall ?? '📲 Add to Home Screen to get notifications') : button.hidden = true);
        return;
    }

    const push = {
        buttons,
        key,
        topics: [...new Set(buttons.flatMap((button) => button.dataset.pwaPush.split(/\s+/).filter(Boolean)))],
        subscribeUrl: meta('pwa-subscribe-url'),
        // the service worker reads its subscribe URL from its own query string (pushsubscriptionchange)
        swUrl: `${meta('pwa-sw-url') ?? '/sw.js'}?subscribe=${encodeURIComponent(meta('pwa-subscribe-url'))}`,
    };
    buttons.forEach((button) => {
        button.dataset.pwaOff ??= button.textContent;
        button.addEventListener('click', () => button.getAttribute('aria-pressed') === 'true' ? unsubscribe(push) : subscribe(push));
    });

    if (Notification.permission === 'denied') {
        buttons.forEach((button) => disable(button, button.dataset.pwaBlocked ?? '🔕 Notifications blocked'));
        return;
    }

    // already on: refresh silently (the topics may have changed, e.g. a new session).
    // Granted but no subscription = the visitor turned it off: do not subscribe again behind their back.
    const registration = await navigator.serviceWorker.getRegistration();
    const current = await registration?.pushManager.getSubscription();
    if (Notification.permission === 'granted' && current) {
        await subscribe(push);
    } else {
        buttons.forEach((button) => toggle(button, false));
    }
});

async function subscribe(push) {
    if (await Notification.requestPermission() !== 'granted') {
        push.buttons.forEach((button) => disable(button, button.dataset.pwaBlocked ?? '🔕 Notifications blocked'));
        return;
    }

    try {
        await navigator.serviceWorker.register(push.swUrl);
        const registration = await navigator.serviceWorker.ready; // subscribe() needs an active worker
        const subscription = await registration.pushManager.getSubscription()
            ?? await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: base64UrlToBytes(push.key)});

        await send(
            push.subscribeUrl,
            'POST', {
                subscription,
                topics: push.topics,
                locale: document.documentElement.lang
            });
        push.buttons.forEach((button) => toggle(button, true));
    } catch (error) {
        console.error(error);
        push.buttons.forEach((button) => disable(button, button.dataset.pwaError ?? '🔕 Notifications unavailable'));
    }
}

async function unsubscribe(push) {
    try {
        const registration = await navigator.serviceWorker.getRegistration();
        const subscription = await registration?.pushManager.getSubscription();
        if (subscription) {
            // server first: if the browser unsubscribed first and the request failed, the row would stay until a 410
            await send(push.subscribeUrl, 'DELETE', {endpoint: subscription.endpoint});
            await subscription.unsubscribe();
        }
        push.buttons.forEach((button) => toggle(button, false));
    } catch (error) {
        console.error(error);
        push.buttons.forEach((button) => disable(button, button.dataset.pwaError ?? '🔕 Notifications unavailable'));
    }
}

async function send(url, method, body) {
    const response = await fetch(url, {
        method,
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify(body),
    });
    if (!response.ok) {
        throw new Error(`Push subscription ${method} refused: ${response.status}`);
    }
}

function toggle(button, on) {
    button.textContent = on ? button.dataset.pwaOn ?? '🔔 Notifications on' : button.dataset.pwaOff;
    button.setAttribute('aria-pressed', String(on));
    button.disabled = false;
}

function disable(button, label) {
    button.textContent = label;
    button.removeAttribute('aria-pressed');
    button.disabled = true;
}

function base64UrlToBytes(value) {
    const base64 = (value + '='.repeat((4 - value.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}
