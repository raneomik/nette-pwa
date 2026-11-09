// Web Push opt-in: any [data-pwa-push="topic1 topic2"] button; reads the <meta> tags written by {pwaHead()}.
// The browser listens to the topics of all the buttons of the page (replacing those of a previous page).
// Labels: data-pwa-on, data-pwa-blocked, data-pwa-error, data-pwa-install (iOS outside the installed app).

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

document.addEventListener('DOMContentLoaded', () => {
    const buttons = [...document.querySelectorAll('[data-pwa-push]')];
    const key = meta('pwa-vapid-key');
    if (!buttons.length) {
        return;
    }
    if (!key || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        // navigator.standalone exists only on iOS: there, Web Push needs the installed app
        const install = 'standalone' in navigator && !navigator.standalone;
        buttons.forEach((button) => install && key ? done(button, button.dataset.pwaInstall ?? '📲 Add to Home Screen to get notifications') : button.hidden = true);
        return;
    }

    const topics = [...new Set(buttons.flatMap((button) => button.dataset.pwaPush.split(/\s+/).filter(Boolean)))];
    buttons.forEach((button) => button.addEventListener('click', () => subscribe(buttons, key, topics)));
    // already allowed: refresh silently (the topics may have changed, e.g. a new session)
    if (Notification.permission === 'granted') {
        subscribe(buttons, key, topics);
    }
});

async function subscribe(buttons, key, topics) {
    if (await Notification.requestPermission() !== 'granted') {
        buttons.forEach((button) => done(button, button.dataset.pwaBlocked ?? '🔕 Notifications blocked'));
        return;
    }

    try {
        await navigator.serviceWorker.register(meta('pwa-sw-url') ?? '/sw.js');
        const registration = await navigator.serviceWorker.ready; // subscribe() needs an active worker
        const subscription = await registration.pushManager.getSubscription()
            ?? await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: base64UrlToBytes(key)});

        const response = await fetch(meta('pwa-subscribe-url'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({subscription, topics}),
        });
        if (!response.ok) {
            throw new Error(`Push subscription refused: ${response.status}`);
        }
        buttons.forEach((button) => done(button, button.dataset.pwaOn ?? '🔔 Notifications on'));
    } catch (error) {
        console.error(error);
        buttons.forEach((button) => done(button, button.dataset.pwaError ?? '🔕 Notifications unavailable'));
    }
}

function done(button, label) {
    button.textContent = label;
    button.disabled = true;
}

function base64UrlToBytes(value) {
    const base64 = (value + '='.repeat((4 - value.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}
